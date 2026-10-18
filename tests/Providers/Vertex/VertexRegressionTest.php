<?php

declare(strict_types=1);

namespace Tests\Providers\Vertex;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Facades\Tool;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Streaming\Events\StepFinishEvent;
use Prism\Prism\Streaming\Events\StreamEndEvent;
use Prism\Prism\Streaming\Events\StreamStartEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Streaming\Events\ThinkingEvent;
use Prism\Prism\ValueObjects\Media\Document;
use Prism\Prism\ValueObjects\Media\Image;

beforeEach(function (): void {
    config()->set('prism.providers.vertex', [
        'project_id' => 'test-project',
        'region' => 'global',
        'access_token' => 'test-access-token',
    ]);
    Http::preventStrayRequests();
});

/**
 * @param  array<array<string, mixed>>  $parts
 * @param  array<string, int>  $usage
 * @return array<string, mixed>
 */
function vertexResponse(array $parts, array $usage = [], string $finishReason = 'STOP'): array
{
    return [
        'candidates' => [['content' => ['role' => 'model', 'parts' => $parts], 'finishReason' => $finishReason]],
        'usageMetadata' => $usage,
    ];
}

/**
 * @param  array<string, mixed>  ...$chunks
 */
function vertexSse(array ...$chunks): string
{
    return implode('', array_map(fn (array $chunk): string => 'data: '.json_encode($chunk, JSON_THROW_ON_ERROR)."\n\n", $chunks));
}

it('normalizes cache and reasoning usage consistently for text and structured output', function (bool $structured): void {
    Http::fake(['*' => Http::response(vertexResponse([['text' => '{"value":null}']], [
        'promptTokenCount' => 100, 'cachedContentTokenCount' => 40,
        'toolUsePromptTokenCount' => 5, 'candidatesTokenCount' => 20, 'thoughtsTokenCount' => 7,
    ]))]);

    $pending = ($structured ? Prism::structured() : Prism::text())
        ->using(Provider::Vertex, 'gemini-2.5-flash')->withPrompt('Give a value');

    if ($structured) {
        $pending->withSchema(new ObjectSchema('result', 'Result', [new StringSchema('value', 'Value', nullable: true)], ['value']));
    }

    $response = $structured ? $pending->asStructured() : $pending->asText();

    expect($response->usage->toArray())->toBe([
        'prompt_tokens' => 65, 'completion_tokens' => 27,
        'cache_write_input_tokens' => null, 'cache_read_input_tokens' => 40, 'thought_tokens' => 7,
    ]);

    Http::assertSent(function (Request $request) use ($structured): bool {
        expect($request->url())->toBe('https://aiplatform.googleapis.com/v1/projects/test-project/locations/global/publishers/google/models/gemini-2.5-flash:generateContent')
            ->and($request->header('Authorization'))->toBe(['Bearer test-access-token'])
            ->and($request->hasHeader('x-goog-api-key'))->toBeFalse();

        if ($structured) {
            expect($request['generationConfig']['response_json_schema']['properties']['value']['type'])->toBe(['string', 'null']);
        }

        return true;
    });
})->with(['text' => false, 'structured' => true]);

it('aggregates streaming steps once and replays function calls with their thought signatures', function (): void {
    Http::fake(['*' => Http::sequence()
        ->push(vertexSse(vertexResponse([
            ['thought' => true, 'text' => 'Check the weather'],
            ['text' => '0'],
            ['functionCall' => ['name' => 'get_weather', 'args' => ['city' => 'Tunis']], 'thoughtSignature' => 'opaque-signature'],
        ], ['promptTokenCount' => 100, 'cachedContentTokenCount' => 40, 'toolUsePromptTokenCount' => 5, 'candidatesTokenCount' => 20, 'thoughtsTokenCount' => 7])), 200, ['Content-Type' => 'text/event-stream'])
        ->push(vertexSse(vertexResponse([['text' => 'Sunny']], [
            'promptTokenCount' => 80, 'cachedContentTokenCount' => 20, 'candidatesTokenCount' => 10, 'thoughtsTokenCount' => 3,
        ])), 200, ['Content-Type' => 'text/event-stream']),
    ]);

    $calls = 0;
    $tool = Tool::as('get_weather')->for('Get weather')->withStringParameter('city', 'City')
        ->using(function (string $city) use (&$calls): string {
            $calls++;

            return "Sunny in {$city}";
        });

    $events = collect(iterator_to_array(Prism::text()->using(Provider::Vertex, 'gemini-2.5-flash')
        ->withPrompt('Weather in Tunis?')->withTools([$tool])->withMaxSteps(3)->asStream(), false));

    expect($calls)->toBe(1)
        ->and($events->whereInstanceOf(StreamStartEvent::class)->sole()->provider)->toBe('vertex')
        ->and($events->whereInstanceOf(StreamStartEvent::class)->sole()->model)->toBe('gemini-2.5-flash')
        ->and($events->whereInstanceOf(TextDeltaEvent::class)->pluck('delta')->implode(''))->toBe('0Sunny')
        ->and($events->whereInstanceOf(ThinkingEvent::class))->toHaveCount(1)
        ->and($events->whereInstanceOf(StepFinishEvent::class))->toHaveCount(2)
        ->and($events->whereInstanceOf(StepFinishEvent::class)->pluck('finishReason')->all())->toBe([FinishReason::ToolCalls, FinishReason::Stop])
        ->and($events->whereInstanceOf(StreamEndEvent::class)->sole()->usage->toArray())->toBe([
            'prompt_tokens' => 125, 'completion_tokens' => 40,
            'cache_write_input_tokens' => null, 'cache_read_input_tokens' => 60, 'thought_tokens' => 10,
        ]);

    $requests = Http::recorded()->map(fn (array $pair): Request => $pair[0]);
    expect($requests)->toHaveCount(2);

    foreach ($requests as $request) {
        expect($request->url())->toContain('aiplatform.googleapis.com/v1/projects/test-project/locations/global/')
            ->and($request->header('Authorization'))->toBe(['Bearer test-access-token'])
            ->and(array_is_list($request['tools']))->toBeTrue()
            ->and($request['tools'][0]['function_declarations'][0]['name'])->toBe('get_weather');
    }

    expect($requests[1]['contents'][1]['parts'][1]['thoughtSignature'])->toBe('opaque-signature')
        ->and($requests[1]['contents'][2]['parts'][0]['functionResponse']['name'])->toBe('get_weather');
});

it('does not execute tools after a truncated or safety-blocked stream', function (string $finishReason, FinishReason $expected): void {
    Http::fake(['*' => Http::response(vertexSse(vertexResponse([
        ['text' => '0'], ['functionCall' => ['name' => 'dangerous', 'args' => (object) []]],
    ], ['promptTokenCount' => 10, 'candidatesTokenCount' => 1], $finishReason)), 200, ['Content-Type' => 'text/event-stream'])]);

    $calls = 0;
    $tool = Tool::as('dangerous')->for('Must not run')->using(function () use (&$calls): string {
        $calls++;

        return 'Executed';
    });
    $events = collect(iterator_to_array(Prism::text()->using(Provider::Vertex, 'gemini-2.5-flash')
        ->withPrompt('Hi')->withTools([$tool])->withMaxSteps(3)->asStream(), false));

    expect($calls)->toBe(0)
        ->and($events->whereInstanceOf(TextDeltaEvent::class)->pluck('delta')->implode(''))->toBe('0')
        ->and($events->whereInstanceOf(StreamEndEvent::class)->sole()->finishReason)->toBe($expected)
        ->and($events->whereInstanceOf(StepFinishEvent::class)->sole()->finishReason)->toBe($expected);
    Http::assertSentCount(1);
})->with(['length' => ['MAX_TOKENS', FinishReason::Length], 'safety' => ['SAFETY', FinishReason::ContentFilter]]);

it('preserves inline PDF inputs on structured Vertex requests', function (): void {
    Http::fake(['*' => Http::response(vertexResponse([['text' => '{"value":"PDF"}']]))]);

    Prism::structured()->using(Provider::Vertex, 'gemini-2.5-flash')
        ->withSchema(new ObjectSchema('result', 'Result', [new StringSchema('value', 'Value')], ['value']))
        ->withPrompt('Read this', [Document::fromBase64(base64_encode('%PDF-1.7 test'), 'application/pdf')])
        ->asStructured();

    Http::assertSent(fn (Request $request): bool => $request['contents'][0]['parts'][0]['inline_data'] === [
        'mime_type' => 'application/pdf', 'data' => base64_encode('%PDF-1.7 test'),
    ]);
});

it('rejects media embeddings without silently dropping content', function (): void {
    Http::fake();

    expect(fn () => Prism::embeddings()->using(Provider::Vertex, 'text-embedding-004')
        ->fromImage(Image::fromBase64('test', 'image/png'))->generate())
        ->toThrow(PrismException::class, 'exactly one text input');

    Http::assertNothingSent();
});

it('leaves missing embedding token counts unknown instead of counting characters', function (): void {
    Http::fake(['*' => Http::response([
        'predictions' => [['embeddings' => ['values' => [0.1, 0.2]]]],
        'metadata' => ['billableCharacterCount' => 100],
    ])]);

    $response = Prism::embeddings()->using(Provider::Vertex, 'text-embedding-004')->fromInput('Hello')->generate();

    expect($response->usage->tokens)->toBeNull()
        ->and($response->raw['metadata']['billableCharacterCount'])->toBe(100);
});

it('retains the Vertex provider name on structured response errors', function (): void {
    Http::fake(['*' => Http::response(['error' => ['code' => 400, 'message' => 'Bad schema']])]);

    Prism::structured()->using(Provider::Vertex, 'gemini-2.5-flash')
        ->withSchema(new ObjectSchema('result', 'Result', [new StringSchema('value', 'Value')], ['value']))
        ->withPrompt('Hi')->asStructured();
})->throws(PrismException::class, 'Vertex Error: [400] Bad schema');
