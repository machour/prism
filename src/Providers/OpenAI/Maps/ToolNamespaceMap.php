<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\OpenAI\Maps;

use Prism\Prism\ValueObjects\ToolNamespace;

class ToolNamespaceMap
{
    /**
     * @param  array<int, ToolNamespace>  $namespaces
     * @return array<int, array<string, mixed>>
     */
    public static function map(array $namespaces): array
    {
        return array_map(
            static fn (ToolNamespace $namespace): array => [
                'type' => 'namespace',
                'name' => $namespace->name,
                'description' => $namespace->description,
                'tools' => ToolMap::map($namespace->tools, $namespace->deferLoading),
            ],
            $namespaces,
        );
    }
}
