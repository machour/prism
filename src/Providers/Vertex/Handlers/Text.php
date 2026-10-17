<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Vertex\Handlers;

use Illuminate\Http\Client\PendingRequest;
use Prism\Prism\Providers\Gemini\Handlers\Text as GeminiText;
use Prism\Prism\Providers\Vertex\Concerns\ValidatesResponse;

class Text extends GeminiText
{
    use ValidatesResponse;

    public function __construct(
        PendingRequest $client,
        protected string $model,
    ) {
        parent::__construct($client, '');
    }
}
