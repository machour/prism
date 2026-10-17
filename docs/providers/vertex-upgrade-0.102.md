# Upgrading the Vertex fork to Prism 0.102.0

This fork integrates the official [Prism 0.102.0 release](https://github.com/prism-php/prism/releases/tag/v0.102.0), commit `b620af44d948d708c75ad43df04ab19580af724b`, on top of the previous Vertex branch, `5dd3c3dc834e423781b1caf2a0a946ab2c21948d`. The official tag does not ship a Vertex provider, despite a commit title mentioning Vertex.

## What changes for Vertex

Vertex keeps its own Google Cloud project/location URLs, Bearer authentication, and text embedding `:predict` endpoint. Text generation, structured output, and streaming now delegate to the upstream Gemini handlers. Future Gemini fixes apply to Vertex through these shared handlers instead of requiring three parallel implementations.

- Tools are encoded as a JSON list. Built-in provider tools can be combined with function tools on models that support that combination.
- Tool arguments benefit from upstream scalar/BackedEnum coercion, empty parameter objects, and JSON control-character handling.
- Structured output uses native `response_json_schema`, preserving nullable types and enums. Applications must use a model that supports this field.
- Streaming preserves text and thinking delivered alongside function calls, including the literal string `"0"`. Thought signatures are replayed with tool calls.
- A stream emits one terminal `StreamEndEvent`; each completed step emits `StepFinishEvent` with its own finish reason and usage. Terminal usage aggregates every step once and keeps the latest cumulative usage snapshot within a step.
- Tools do not execute when a response ends because of a token limit or safety filter. Step limits still bound tool execution.
- Vertex streaming continues to identify itself as `vertex`; requests without a returned `modelVersion` use the requested model in their start event.
- Google quota violations and `RetryInfo` now populate `PrismRateLimitedException` details and retry delays.
- `GOOGLE_APPLICATION_CREDENTIALS` now actually selects the credentials file. Explicit and environment files accept service accounts or authorized-user credentials. Invalid configured paths fail instead of silently selecting another identity. Failed OAuth refreshes do not expose response bodies or accept a missing token.
- Embedding usage comes from `predictions[0].embeddings.statistics.token_count`, not `billableCharacterCount`. Missing token counts remain `null`; raw character counts remain accessible in `raw`. The text embedding handler explicitly rejects media/multipart inputs rather than silently dropping them. It still accepts one text input per request.

## Breaking usage-accounting contract

The new [upstream usage contract](https://github.com/prism-php/prism/pull/1043) applies to Vertex in text, structured, and streaming responses:

| Prism field | Meaning |
| --- | --- |
| `promptTokens` | Uncached input, including separately reported tool-use prompt tokens |
| `cacheReadInputTokens` | Cached input, separate from `promptTokens` |
| `cacheWriteInputTokens` | Cache-write input when reported; Vertex currently leaves it `null` |
| `completionTokens` | All output, including reasoning |
| `thoughtTokens` | Reasoning subset already included in `completionTokens` |

Optional metrics distinguish an unreported value (`null`) from a reported zero (`0`). Do not add reasoning tokens to completion tokens again, or subtract cache tokens from `promptTokens` again.

For example, Google's `promptTokenCount=100`, `cachedContentTokenCount=40`, `toolUsePromptTokenCount=5`, `candidatesTokenCount=20`, and `thoughtsTokenCount=7` produce:

```php
new Usage(
    promptTokens: 65,
    completionTokens: 27,
    cacheReadInputTokens: 40,
    thoughtTokens: 7,
);
```

A consumer whose own storage/pricing contract includes cache in input must convert once at its application boundary:

```php
$inputIncludingCache = $usage->promptTokens
    + ($usage->cacheReadInputTokens ?? 0)
    + ($usage->cacheWriteInputTokens ?? 0);
$outputExcludingReasoning = $usage->completionTokens - ($usage->thoughtTokens ?? 0);
```

These application values are not Prism `Usage` values. Keep the distinction explicit in storage and streamed/UI payloads. If a consumer instead adopts Prism's canonical contract, update its pricing calculator, long-context threshold, token validation, persistence, and display together. A fully cached request can have zero uncached input: usage validation must also consider cache buckets. Decide how reasoning is billed in the application; do not silently change existing balances or reinterpret historical records.

## Other upstream improvements included

- Laravel 13 support; the new framework requirement is `^12.61.1|^13.12.0`, so Laravel 11 is no longer supported. PHP remains `^8.2` (Laravel 13 requires PHP 8.3+).
- OpenAI GPT-6 and Anthropic Claude 5.5 support, including improved reasoning/provider-state replay and structured output. Anthropic provider tools work in structured mode, with pause/refusal handling.
- DeepSeek reasoning/cache usage and reasoning replay, OpenRouter error bodies/provider errors/reasoning details/cache TTL, Mistral structured tools and diarization, Ollama embedding options, and Z streaming fixes.
- Empty tool arguments consistently serialize as objects. Unknown finish reasons are handled without losing the terminal state. Stream adapters handle client disconnects more consistently.
- `StreamCollector` was removed; consumers assembling assistant/tool messages from a stream should use `StreamedMessages::fromEvents($events)`.
- Fake tool-call responses must use `FinishReason::ToolCalls` to execute tools, matching real provider behavior. Review existing test fixtures when upgrading.

## Consumer rollout

1. Review usage and event consumers, especially pricing, cache discounts, reasoning, long-context rates, interrupted calls, and multi-step tool loops. Preserve historical accounting semantics explicitly.
2. Pin a reviewed fork commit or a versioned release in Composer and commit the lockfile. Avoid an unreviewed branch-head update during a production deployment.
3. Re-run application tests with the new package: chat with/without tools, all tool steps in final usage, structured extraction, PDFs/images, authorization, quotas, and uncertain/aborted requests. Package HTTP fixtures do not prove application accounting is compatible.
4. Verify the configured project, regional/global endpoint, credentials, and current model with a controlled live smoke test in the deployment environment. This PR's tests fake Google HTTP calls; they do not validate IAM permissions or live model availability.
5. Restart long-running queue workers as part of the application deployment. Keep the previous application build and lockfile available for rollback. This package update requires no database migration by itself.

Authentication remains limited to explicit access tokens, service-account JSON keys (self-signed JWT), and authorized-user JSON files discovered explicitly, through `GOOGLE_APPLICATION_CREDENTIALS`, or in the local gcloud ADC path. This is not a full Google ADC implementation: metadata-server identities, impersonation, external-account/Workload Identity Federation, and automatic renewal of an explicitly supplied token are not implemented. An environment requiring those mechanisms should provide externally managed tokens or use the official Google authentication library in a separately reviewed change.
