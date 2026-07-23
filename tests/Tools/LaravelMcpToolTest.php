<?php

declare(strict_types=1);

namespace Tests\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool as McpTool;
use Prism\Prism\Tools\LaravelMcpTool;

it('can reuse a precomputed MCP definition', function (): void {
    $schemaCalls = 0;
    $mcpTool = new class($schemaCalls) extends McpTool
    {
        protected string $name = 'precomputed-tool';

        protected string $description = 'A tool with an expensive schema';

        public function __construct(private int &$schemaCalls) {}

        public function schema(JsonSchema $schema): array
        {
            $this->schemaCalls++;

            return [
                'expensive' => $schema->string(),
            ];
        }
    };

    $tool = new LaravelMcpTool($mcpTool, [
        'inputSchema' => [
            'properties' => [
                'cached' => ['type' => 'string'],
            ],
            'required' => ['cached'],
        ],
    ]);

    expect($schemaCalls)->toBe(0)
        ->and($tool->parametersAsArray())->toBe([
            'cached' => ['type' => 'string'],
        ])
        ->and($tool->requiredParameters())->toBe(['cached']);
});

it('can handle a tool that returns a Response', function (): void {
    $mcpTool = new class extends McpTool
    {
        protected string $name = 'test-tool';

        protected string $description = 'A test tool';

        public function handle(Request $request): Response
        {
            return Response::text('Hello, World!');
        }

        public function schema(JsonSchema $schema): array
        {
            return [];
        }
    };

    $tool = new LaravelMcpTool($mcpTool);

    $result = $tool->handle();

    expect($result)->toBe('Hello, World!');
});

it('can handle a tool that returns a ResponseFactory from Response::structured()', function (): void {
    $mcpTool = new class extends McpTool
    {
        protected string $name = 'structured-tool';

        protected string $description = 'A tool that returns structured data';

        public function handle(Request $request): ResponseFactory
        {
            return Response::structured([
                'status' => 'success',
                'data' => [
                    'id' => 123,
                    'name' => 'Test',
                ],
            ]);
        }

        public function schema(JsonSchema $schema): array
        {
            return [];
        }
    };

    $tool = new LaravelMcpTool($mcpTool);

    $result = $tool->handle();

    expect($result)->toContain('"status":"success"')
        ->and($result)->toContain('"id":123')
        ->and($result)->toContain('"name":"Test"');
});

it('can handle a tool that returns a ResponseFactory from Response::make()', function (): void {
    $mcpTool = new class extends McpTool
    {
        protected string $name = 'make-tool';

        protected string $description = 'A tool that uses Response::make()';

        public function handle(Request $request): ResponseFactory
        {
            return Response::make([
                Response::text('First response'),
                Response::text('Second response'),
            ]);
        }

        public function schema(JsonSchema $schema): array
        {
            return [];
        }
    };

    $tool = new LaravelMcpTool($mcpTool);

    $result = $tool->handle();

    expect($result)->toBe("First response\nSecond response");
});

it('can handle a tool that returns a Generator of Responses', function (): void {
    $mcpTool = new class extends McpTool
    {
        protected string $name = 'generator-tool';

        protected string $description = 'A tool that yields responses';

        /** @return \Generator<Response> */
        public function handle(Request $request): \Generator
        {
            yield Response::text('Yielded first');
            yield Response::text('Yielded second');
        }

        public function schema(JsonSchema $schema): array
        {
            return [];
        }
    };

    $tool = new LaravelMcpTool($mcpTool);

    $result = $tool->handle();

    expect($result)->toBe("Yielded first\nYielded second");
});
