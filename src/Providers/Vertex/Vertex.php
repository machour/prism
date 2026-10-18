<?php

declare(strict_types=1);

namespace Prism\Prism\Providers\Vertex;

use Generator;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Prism\Prism\Concerns\InitializesClient;
use Prism\Prism\Embeddings\Request as EmbeddingRequest;
use Prism\Prism\Embeddings\Response as EmbeddingResponse;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\Exceptions\PrismProviderOverloadedException;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Providers\Gemini\Concerns\ProcessesRateLimits;
use Prism\Prism\Providers\Provider;
use Prism\Prism\Providers\Vertex\Handlers\Embeddings;
use Prism\Prism\Providers\Vertex\Handlers\Stream;
use Prism\Prism\Providers\Vertex\Handlers\Structured;
use Prism\Prism\Providers\Vertex\Handlers\Text;
use Prism\Prism\Structured\Request as StructuredRequest;
use Prism\Prism\Structured\Response as StructuredResponse;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\Text\Response as TextResponse;

class Vertex extends Provider
{
    use InitializesClient;
    use ProcessesRateLimits;

    public function __construct(
        public readonly string $projectId,
        public readonly string $region,
        #[\SensitiveParameter] public readonly ?string $accessToken = null,
        public readonly ?string $credentialsPath = null,
        #[\SensitiveParameter] public readonly ?array $credentialsData = null,
    ) {}

    #[\Override]
    public function text(TextRequest $request): TextResponse
    {
        $handler = new Text(
            $this->client($request->clientOptions(), $request->clientRetry()),
            $request->model()
        );

        return $handler->handle($request);
    }

    #[\Override]
    public function structured(StructuredRequest $request): StructuredResponse
    {
        $handler = new Structured(
            $this->client($request->clientOptions(), $request->clientRetry()),
            $request->model()
        );

        return $handler->handle($request);
    }

    #[\Override]
    public function embeddings(EmbeddingRequest $request): EmbeddingResponse
    {
        $handler = new Embeddings(
            $this->client($request->clientOptions(), $request->clientRetry()),
            $request->model()
        );

        return $handler->handle($request);
    }

    #[\Override]
    public function stream(TextRequest $request): Generator
    {
        $handler = new Stream(
            $this->client($request->clientOptions(), $request->clientRetry()),
            $request->model()
        );

        return $handler->handle($request);
    }

    public function handleRequestException(string $model, RequestException $e): never
    {
        $responseData = $e->response->json() ?? [];

        match ($e->response->getStatusCode()) {
            429 => throw PrismRateLimitedException::make(
                rateLimits: $this->processRateLimits($responseData),
                retryAfter: $this->extractRetryAfterSeconds($responseData)
            ),
            503 => throw PrismProviderOverloadedException::make(class_basename($this)),
            default => $this->handleResponseErrors($e),
        };
    }

    protected function handleResponseErrors(RequestException $e): never
    {
        $data = $e->response->json() ?? [];

        throw PrismException::providerRequestErrorWithDetails(
            provider: 'Vertex',
            statusCode: $e->response->getStatusCode(),
            errorType: data_get($data, 'error.status'),
            errorMessage: data_get($data, 'error.message'),
            previous: $e
        );
    }

    /**
     * @param  array<string, mixed>  $options
     * @param  array<mixed>  $retry
     */
    protected function client(array $options = [], array $retry = []): PendingRequest
    {
        $accessToken = $this->resolveAccessToken();

        return $this->baseClient()
            ->withToken($accessToken)
            ->withOptions($options)
            ->when($retry !== [], fn ($client) => $client->retry(...$retry))
            ->baseUrl($this->buildBaseUrl());
    }

    protected function buildBaseUrl(): string
    {
        if ($this->region === 'global') {
            return sprintf(
                'https://aiplatform.googleapis.com/v1/projects/%s/locations/global/publishers/google/models',
                $this->projectId
            );
        }

        return sprintf(
            'https://%s-aiplatform.googleapis.com/v1/projects/%s/locations/%s/publishers/google/models',
            $this->region,
            $this->projectId,
            $this->region
        );
    }

    protected function resolveAccessToken(): string
    {
        if ($this->accessToken !== null && $this->accessToken !== '') {
            return $this->accessToken;
        }

        if ($this->credentialsData !== null) {
            return $this->getAccessTokenFromCredentials($this->credentialsData);
        }

        if ($this->credentialsPath !== null && $this->credentialsPath !== '') {
            return $this->getAccessTokenFromCredentialsFile($this->credentialsPath);
        }

        return $this->getAccessTokenFromApplicationDefaultCredentials();
    }

    protected function getAccessTokenFromCredentialsFile(string $path): string
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new PrismException("Vertex AI credentials file not found or unreadable: {$path}");
        }

        $contents = file_get_contents($path);
        $credentials = $contents === false ? null : json_decode($contents, true);

        if (! is_array($credentials)) {
            throw new PrismException('Invalid Vertex AI credentials file');
        }

        return $this->getAccessTokenFromCredentials($credentials);
    }

    protected function getAccessTokenFromCredentials(#[\SensitiveParameter] array $credentials): string
    {
        if (($credentials['type'] ?? null) === 'authorized_user') {
            return $this->refreshAccessToken($credentials);
        }

        if (! is_string($credentials['client_email'] ?? null) || ! is_string($credentials['private_key'] ?? null)) {
            throw new PrismException('Invalid or unsupported Vertex AI credentials file');
        }

        return $this->generateJwtToken($credentials);
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    protected function generateJwtToken(array $credentials): string
    {
        $header = [
            'alg' => 'RS256',
            'typ' => 'JWT',
        ];

        $now = time();
        $payload = [
            'iss' => $credentials['client_email'],
            'sub' => $credentials['client_email'],
            'aud' => 'https://aiplatform.googleapis.com/',
            'iat' => $now,
            'exp' => $now + 3600,
        ];

        $headerEncoded = $this->base64UrlEncode(json_encode($header, JSON_THROW_ON_ERROR));
        $payloadEncoded = $this->base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR));

        $signatureInput = $headerEncoded.'.'.$payloadEncoded;

        $privateKey = openssl_pkey_get_private($credentials['private_key']);
        if ($privateKey === false) {
            throw new PrismException('Failed to parse Vertex AI private key');
        }

        $signature = '';
        if (! openssl_sign($signatureInput, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new PrismException('Failed to sign Vertex AI JWT token');
        }

        return $headerEncoded.'.'.$payloadEncoded.'.'.$this->base64UrlEncode($signature);
    }

    protected function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    protected function getAccessTokenFromApplicationDefaultCredentials(): string
    {
        $adcPath = getenv('GOOGLE_APPLICATION_CREDENTIALS');

        if ($adcPath !== false && $adcPath !== '') {
            return $this->getAccessTokenFromCredentialsFile($adcPath);
        }

        $defaultPath = $this->getDefaultAdcPath();
        if (file_exists($defaultPath)) {
            return $this->getAccessTokenFromCredentialsFile($defaultPath);
        }

        throw new PrismException(
            'Vertex AI requires authentication. Provide an access_token, credentials_path, '.
            'or set up Application Default Credentials (run: gcloud auth application-default login)'
        );
    }

    protected function getDefaultAdcPath(): string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return getenv('APPDATA').'/gcloud/application_default_credentials.json';
        }

        return getenv('HOME').'/.config/gcloud/application_default_credentials.json';
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    protected function refreshAccessToken(array $credentials): string
    {
        foreach (['client_id', 'client_secret', 'refresh_token'] as $key) {
            if (! is_string($credentials[$key] ?? null) || $credentials[$key] === '') {
                throw new PrismException('Invalid Vertex AI authorized-user credentials');
            }
        }

        $response = $this->baseClient()
            ->throwIf(fn (): bool => false)
            ->asForm()
            ->post('https://oauth2.googleapis.com/token', [
                'client_id' => $credentials['client_id'],
                'client_secret' => $credentials['client_secret'],
                'refresh_token' => $credentials['refresh_token'],
                'grant_type' => 'refresh_token',
            ]);

        $accessToken = $response->json('access_token');

        if (! $response->successful() || ! is_string($accessToken) || $accessToken === '') {
            throw new PrismException('Failed to refresh Vertex AI access token (HTTP '.$response->status().')');
        }

        return $accessToken;
    }
}
