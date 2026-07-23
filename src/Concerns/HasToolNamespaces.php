<?php

declare(strict_types=1);

namespace Prism\Prism\Concerns;

use Prism\Prism\ValueObjects\ToolNamespace;

trait HasToolNamespaces
{
    /** @var array<int, ToolNamespace> */
    protected array $toolNamespaces = [];

    /**
     * @param  array<int, ToolNamespace>  $toolNamespaces
     */
    public function withToolNamespaces(array $toolNamespaces): self
    {
        $this->toolNamespaces = $toolNamespaces;

        return $this;
    }
}
