<?php

declare(strict_types=1);

namespace Tests\Providers\OpenAI;

use Prism\Prism\Providers\OpenAI\Maps\ToolMap;
use Prism\Prism\Providers\OpenAI\Maps\ToolNamespaceMap;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\ToolNamespace;

it('maps tools', function (): void {
    $tool = (new Tool)
        ->as('search')
        ->for('Searching the web')
        ->withStringParameter('query', 'the detailed search query')
        ->using(fn (): string => '[Search results]');

    expect(ToolMap::map([$tool]))->toBe([[
        'type' => 'function',
        'name' => $tool->name(),
        'description' => $tool->description(),
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'description' => 'the detailed search query',
                    'type' => 'string',
                ],
            ],
            'required' => $tool->requiredParameters(),
        ],
    ]]);
});

it('maps tools with strict mode', function (): void {
    $tool = (new Tool)
        ->as('search')
        ->for('Searching the web')
        ->withStringParameter('query', 'the detailed search query')
        ->using(fn (): string => '[Search results]')
        ->withProviderOptions([
            'strict' => true,
        ]);

    expect(ToolMap::map([$tool]))->toBe([[
        'type' => 'function',
        'name' => $tool->name(),
        'description' => $tool->description(),
        'parameters' => [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'description' => 'the detailed search query',
                    'type' => 'string',
                ],
            ],
            'required' => $tool->requiredParameters(),
        ],
        'strict' => true,
    ]]);
});

it('maps deferred tool namespaces', function (): void {
    $tool = (new Tool)
        ->as('search_orders')
        ->for('Search customer orders')
        ->withStringParameter('customer_id', 'The customer identifier')
        ->using(fn (): string => '[Orders]');

    expect(ToolNamespaceMap::map([
        new ToolNamespace(
            name: 'crm',
            description: 'Customer relationship tools.',
            tools: [$tool],
        ),
    ]))->toBe([[
        'type' => 'namespace',
        'name' => 'crm',
        'description' => 'Customer relationship tools.',
        'tools' => [[
            'type' => 'function',
            'name' => 'search_orders',
            'description' => 'Search customer orders',
            'defer_loading' => true,
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'customer_id' => [
                        'description' => 'The customer identifier',
                        'type' => 'string',
                    ],
                ],
                'required' => ['customer_id'],
            ],
        ]],
    ]]);
});
