<?php

declare(strict_types=1);

namespace Tests\Providers\Vertex;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Facades\Prism;
use Tests\Fixtures\FixtureResponse;

beforeEach(function (): void {
    $this->originalVertexAdc = getenv('GOOGLE_APPLICATION_CREDENTIALS');
    $this->vertexCredentialsPath = tempnam(sys_get_temp_dir(), 'prism-vertex-test-');
    config()->set('prism.providers.vertex', [
        'project_id' => 'test-project', 'region' => 'us-central1',
        'access_token' => null, 'credentials_path' => null,
    ]);
    Http::preventStrayRequests();
});

afterEach(function (): void {
    putenv($this->originalVertexAdc === false ? 'GOOGLE_APPLICATION_CREDENTIALS' : 'GOOGLE_APPLICATION_CREDENTIALS='.$this->originalVertexAdc);
    unlink($this->vertexCredentialsPath);
});

it('uses the service account file from configuration or the ADC environment', function (bool $environment): void {
    $privateKey = openssl_pkey_new(['private_key_bits' => 2048]);
    openssl_pkey_export($privateKey, $pem);
    file_put_contents($this->vertexCredentialsPath, json_encode([
        'type' => 'service_account', 'client_email' => 'test@example.iam.gserviceaccount.com', 'private_key' => $pem,
    ], JSON_THROW_ON_ERROR));

    if ($environment) {
        putenv('GOOGLE_APPLICATION_CREDENTIALS='.$this->vertexCredentialsPath);
    } else {
        config()->set('prism.providers.vertex.credentials_path', $this->vertexCredentialsPath);
        putenv('GOOGLE_APPLICATION_CREDENTIALS=/must-not-be-used.json');
    }
    FixtureResponse::fakeResponseSequence('*', 'vertex/generate-text-with-a-prompt');

    Prism::text()->using(Provider::Vertex, 'gemini-2.5-flash')->withPrompt('Hi')->asText();

    Http::assertSent(function (Request $request) use ($privateKey): bool {
        $jwt = substr((string) $request->header('Authorization')[0], strlen('Bearer '));
        [$header, $payload, $signature] = explode('.', $jwt);
        $claims = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);

        expect($claims['iss'])->toBe('test@example.iam.gserviceaccount.com')
            ->and($claims['aud'])->toBe('https://aiplatform.googleapis.com/')
            ->and($claims['exp'] - $claims['iat'])->toBe(3600)
            ->and(openssl_verify($header.'.'.$payload, base64_decode(strtr($signature, '-_', '+/')), openssl_pkey_get_details($privateKey)['key'], OPENSSL_ALGO_SHA256))->toBe(1);

        return true;
    });
})->with(['explicit file' => false, 'ADC environment' => true]);

it('refreshes authorized-user credentials from a configured or environment file', function (bool $environment): void {
    file_put_contents($this->vertexCredentialsPath, json_encode([
        'type' => 'authorized_user', 'client_id' => 'test-client', 'client_secret' => 'test-secret', 'refresh_token' => 'test-refresh',
    ], JSON_THROW_ON_ERROR));

    if ($environment) {
        putenv('GOOGLE_APPLICATION_CREDENTIALS='.$this->vertexCredentialsPath);
    } else {
        config()->set('prism.providers.vertex.credentials_path', $this->vertexCredentialsPath);
    }

    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'refreshed-token']),
        '*aiplatform.googleapis.com/*' => Http::response(json_decode(file_get_contents('tests/Fixtures/vertex/generate-text-with-a-prompt-1.json'), true)),
    ]);

    Prism::text()->using(Provider::Vertex, 'gemini-2.5-flash')->withPrompt('Hi')->asText();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://oauth2.googleapis.com/token'
        && $request['grant_type'] === 'refresh_token' && $request['refresh_token'] === 'test-refresh');
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'aiplatform.googleapis.com')
        && $request->header('Authorization') === ['Bearer refreshed-token']);
    Http::assertSentCount(2);
})->with(['explicit file' => false, 'ADC environment' => true]);

it('gives explicit access tokens precedence over credential files', function (): void {
    config()->set('prism.providers.vertex.access_token', 'explicit-token');
    config()->set('prism.providers.vertex.credentials_path', '/must-not-be-used.json');
    putenv('GOOGLE_APPLICATION_CREDENTIALS=/also-must-not-be-used.json');
    FixtureResponse::fakeResponseSequence('*', 'vertex/generate-text-with-a-prompt');

    Prism::text()->using(Provider::Vertex, 'gemini-2.5-flash')->withPrompt('Hi')->asText();

    Http::assertSent(fn (Request $request): bool => $request->header('Authorization') === ['Bearer explicit-token']);
    Http::assertSentCount(1);
});

it('rejects invalid or unsupported credential files before requesting a model', function (string $contents): void {
    file_put_contents($this->vertexCredentialsPath, $contents);
    config()->set('prism.providers.vertex.credentials_path', $this->vertexCredentialsPath);
    Http::fake();

    expect(fn () => Prism::text()->using(Provider::Vertex, 'gemini-2.5-flash')->withPrompt('Hi')->asText())
        ->toThrow(PrismException::class, 'Invalid');
    Http::assertNothingSent();
})->with(['malformed JSON' => '{', 'unsupported type' => '{"type":"external_account"}', 'incomplete authorized user' => '{"type":"authorized_user"}']);

it('fails on an invalid explicit ADC path instead of falling back to another identity', function (): void {
    putenv('GOOGLE_APPLICATION_CREDENTIALS=/missing-vertex-test-file.json');
    Http::fake();

    expect(fn () => Prism::text()->using(Provider::Vertex, 'gemini-2.5-flash')->withPrompt('Hi')->asText())
        ->toThrow(PrismException::class, 'credentials file not found or unreadable');
    Http::assertNothingSent();
});

it('does not expose OAuth response bodies or accept missing access tokens', function (int $status, array $response): void {
    file_put_contents($this->vertexCredentialsPath, json_encode([
        'type' => 'authorized_user', 'client_id' => 'test-client', 'client_secret' => 'test-secret', 'refresh_token' => 'test-refresh',
    ], JSON_THROW_ON_ERROR));
    config()->set('prism.providers.vertex.credentials_path', $this->vertexCredentialsPath);
    Http::fake(['https://oauth2.googleapis.com/token' => Http::response($response, $status)]);

    try {
        Prism::text()->using(Provider::Vertex, 'gemini-2.5-flash')->withPrompt('Hi')->asText();
        test()->fail('Expected authentication failure');
    } catch (PrismException $exception) {
        expect($exception->getMessage())->toBe("Failed to refresh Vertex AI access token (HTTP {$status})");
    }
    Http::assertSentCount(1);
})->with(['failed refresh' => [400, ['error' => 'sensitive-provider-body']], 'missing token' => [200, []], 'empty token' => [200, ['access_token' => '']]]);

it('authenticates from in-memory authorized-user credentials without a file', function (): void {
    config()->set('prism.providers.vertex.credentials_data', [
        'type' => 'authorized_user', 'client_id' => 'db-client', 'client_secret' => 'db-secret', 'refresh_token' => 'db-refresh',
    ]);
    config()->set('prism.providers.vertex.credentials_path', '/must-not-be-read.json');
    Http::fake([
        'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'database-token']),
        '*aiplatform.googleapis.com/*' => Http::response(json_decode(file_get_contents('tests/Fixtures/vertex/generate-text-with-a-prompt-1.json'), true)),
    ]);
    Prism::text()->using(Provider::Vertex, 'gemini-2.5-flash')->withPrompt('Hi')->asText();
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://oauth2.googleapis.com/token' && $request['refresh_token'] === 'db-refresh');
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'aiplatform.googleapis.com') && $request->header('Authorization') === ['Bearer database-token']);
    Http::assertSentCount(2);
});
