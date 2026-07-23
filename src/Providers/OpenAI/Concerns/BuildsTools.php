<?php

namespace Prism\Prism\Providers\OpenAI\Concerns;

use Prism\Prism\Providers\OpenAI\Maps\ToolMap;
use Prism\Prism\Providers\OpenAI\Maps\ToolNamespaceMap;
use Prism\Prism\Structured\Request as StructuredRequest;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\ValueObjects\ProviderTool;

trait BuildsTools
{
    /**
     * @return array<int|string,mixed>
     */
    protected function buildTools(TextRequest|StructuredRequest $request): array
    {
        $tools = [
            ...ToolNamespaceMap::map($request->toolNamespaces()),
            ...ToolMap::map($request->tools()),
        ];

        if ($request->providerTools() === []) {
            return $tools;
        }

        $providerTools = array_map(
            fn (ProviderTool $tool): array => [
                'type' => $tool->type,
                ...$tool->options,
            ],
            $request->providerTools()
        );

        return array_merge($providerTools, $tools);
    }
}
