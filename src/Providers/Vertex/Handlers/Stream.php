<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Vertex\Handlers;

use Illuminate\Http\Client\PendingRequest;
use Prism\Prism\Providers\Gemini\Handlers\Stream as GeminiStream;

class Stream extends GeminiStream
{
    public function __construct(
        PendingRequest $client,
        protected string $model,
    ) {
        parent::__construct($client, '');
    }

    #[\Override]
    protected function providerName(): string
    {
        return 'vertex';
    }
}
