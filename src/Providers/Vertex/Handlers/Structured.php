<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Vertex\Handlers;

use Illuminate\Http\Client\PendingRequest;
use Prism\Prism\Providers\Gemini\Handlers\Structured as GeminiStructured;
use Prism\Prism\Providers\Vertex\Concerns\ValidatesResponse;

class Structured extends GeminiStructured
{
    use ValidatesResponse;

    public function __construct(
        PendingRequest $client,
        protected string $model,
    ) {
        parent::__construct($client);
    }
}
