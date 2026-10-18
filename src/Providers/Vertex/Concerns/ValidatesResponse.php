<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Vertex\Concerns;

trait ValidatesResponse
{
    protected function responseProviderName(): string
    {
        return 'Vertex';
    }
}
