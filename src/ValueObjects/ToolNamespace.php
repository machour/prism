<?php

declare(strict_types=1);

namespace Prism\Prism\ValueObjects;

use Prism\Prism\Tool;

readonly class ToolNamespace
{
    /**
     * @param  array<int, Tool>  $tools
     */
    public function __construct(
        public string $name,
        public string $description,
        public array $tools,
        public bool $deferLoading = true,
    ) {}
}
