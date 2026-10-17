# Vertex AI

Google Vertex AI provides access to Google's Gemini models through Google Cloud projects and locations. The provider shares Gemini's text, structured, and streaming implementation while keeping Vertex URLs, authentication, and text embedding requests.

See the [0.102.0 upgrade report](./vertex-upgrade-0.102.md) before updating an existing application, especially its token accounting and pricing.

## Configuration

```php
'vertex' => [
    'project_id' => env('VERTEX_PROJECT_ID', ''),
    'region' => env('VERTEX_REGION', 'us-central1'),
    'access_token' => env('VERTEX_ACCESS_TOKEN', null),
    'credentials_path' => env('VERTEX_CREDENTIALS_PATH', null),
],
```

### Authentication

Vertex AI supports multiple authentication methods:

#### 1. Access Token (Recommended for development)

Provide an access token directly:

```env
VERTEX_ACCESS_TOKEN=your-access-token
```

You can obtain an access token using the Google Cloud CLI:

```bash
gcloud auth print-access-token
```

#### 2. Service Account Credentials

Provide the path to your service account JSON key file:

```env
VERTEX_CREDENTIALS_PATH=/path/to/service-account.json
```

#### 3. Application Default Credentials

If no token or explicit file is provided, Prism looks for a credentials file in `GOOGLE_APPLICATION_CREDENTIALS`, then the local gcloud ADC path. Service-account keys and authorized-user JSON files are supported. Set up a local authorized-user file by running:

```bash
gcloud auth application-default login
```

Or by setting the `GOOGLE_APPLICATION_CREDENTIALS` environment variable:

```bash
export GOOGLE_APPLICATION_CREDENTIALS=/path/to/service-account.json
```

Precedence is explicit token, configured credentials file, environment credentials file, then local gcloud ADC file. An invalid selected file fails without falling back to another identity. Metadata-server identities, impersonation, and Workload Identity Federation are not implemented. Explicit access tokens must be renewed by the caller.

## Basic Usage

### Text Generation

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;

$response = Prism::text()
    ->using(Provider::Vertex, 'gemini-2.5-flash')
    ->withPrompt('Explain quantum computing in simple terms.')
    ->asText();

echo $response->text;
```

### With System Prompt

```php
$response = Prism::text()
    ->using(Provider::Vertex, 'gemini-2.5-flash')
    ->withSystemPrompt('You are a helpful coding assistant.')
    ->withPrompt('Write a Python function to calculate fibonacci numbers.')
    ->asText();
```

## Structured Output

Vertex AI supports structured output, allowing you to define schemas that constrain the model's responses to match your exact data structure requirements.

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use Prism\Prism\Schema\NumberSchema;

$schema = new ObjectSchema(
    name: 'user_profile',
    description: 'A user profile object',
    properties: [
        new StringSchema('name', 'The user\'s full name'),
        new NumberSchema('age', 'The user\'s age'),
        new StringSchema('email', 'The user\'s email address'),
    ],
    requiredFields: ['name', 'age', 'email']
);

$response = Prism::structured()
    ->using(Provider::Vertex, 'gemini-2.5-flash')
    ->withSchema($schema)
    ->withPrompt('Generate a profile for a fictional user named John Doe.')
    ->generate();

// Access structured data
$profile = $response->structured;
echo $profile['name'];  // "John Doe"
echo $profile['age'];   // 30
echo $profile['email']; // "john.doe@example.com"
```

## Tool Usage

Vertex AI supports function calling (tools) to extend the model's capabilities:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Tool;

$weatherTool = (new Tool)
    ->as('get_weather')
    ->for('Get the current weather for a location')
    ->withStringParameter('location', 'The city and state, e.g. San Francisco, CA')
    ->using(function (string $location): string {
        // Your weather API call here
        return "The weather in {$location} is 72°F and sunny.";
    });

$response = Prism::text()
    ->using(Provider::Vertex, 'gemini-2.5-flash')
    ->withTools([$weatherTool])
    ->withMaxSteps(3)
    ->withPrompt('What is the weather like in San Francisco?')
    ->asText();

echo $response->text;
```

## Embeddings

Vertex AI supports text embeddings for semantic search, clustering, and other ML tasks:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;

$response = Prism::embeddings()
    ->using(Provider::Vertex, 'text-embedding-004')
    ->fromInput('The quick brown fox jumps over the lazy dog.')
    ->generate();

// Access the embedding vector
$embedding = $response->embeddings[0]->embedding;
```

## Streaming

Vertex AI supports streaming responses for real-time output:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;

$stream = Prism::text()
    ->using(Provider::Vertex, 'gemini-2.5-flash')
    ->withPrompt('Write a short story about a robot.')
    ->asStream();

foreach ($stream as $event) {
    if ($event instanceof \Prism\Prism\Streaming\Events\TextDeltaEvent) {
        echo $event->delta;
    }
}
```

## Image Understanding

Vertex AI supports multimodal inputs including images:

```php
use Prism\Prism\Facades\Prism;
use Prism\Prism\Enums\Provider;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Media\Image;

$response = Prism::text()
    ->using(Provider::Vertex, 'gemini-2.5-flash')
    ->withMessages([
        new UserMessage(
            'What do you see in this image?',
            additionalContent: [
                Image::fromLocalPath('/path/to/image.png'),
            ],
        ),
    ])
    ->asText();

echo $response->text;
```

## Thinking Mode

For models that support it (like Gemini 2.5), you can configure thinking mode:

```php
$response = Prism::text()
    ->using(Provider::Vertex, 'gemini-2.5-flash')
    ->withPrompt('Solve this complex math problem...')
    ->withProviderOptions([
        'thinkingBudget' => 2048,
    ])
    ->asText();

// Access thinking token usage
echo $response->usage->thoughtTokens;
```

## Model Selection

Use a model enabled for your Google Cloud project and location. Model availability and supported tool combinations vary; consult the [Google model documentation](https://cloud.google.com/vertex-ai/generative-ai/docs/learn/models). Structured output uses `response_json_schema`, which must be supported by the selected model.

## Regions

Vertex AI is available in multiple regions. Common options include:

- `us-central1` (default)
- `us-east1`
- `us-west1`
- `europe-west1`
- `europe-west4`
- `asia-northeast1`
- `asia-southeast1`

Configure your region in the `.env` file:

```env
VERTEX_REGION=us-central1
```

## Differences from Gemini API

While Vertex AI uses the same underlying Gemini models, there are key differences:

| Feature | Gemini API | Vertex AI |
|---------|------------|-----------|
| Authentication | API Key | OAuth 2.0 / Service Account |
| Pricing | Pay-as-you-go | Google Cloud billing |
| Data residency | Global | Regional control |
| Enterprise features | Limited | Full (VPC, audit logs, etc.) |
| SLA | None | Enterprise SLA available |

Choose Vertex AI when you need enterprise-grade security, compliance, or integration with other Google Cloud services.
