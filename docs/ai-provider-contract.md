# Harmony AI Provider Contract

`App\AI\AIProviderInterface` is the extension point for every LLM provider in Harmony.

## Required methods

- `sendPrompt(PromptRequest $promptRequest): ProviderResponse`
- `streamPrompt(PromptRequest $promptRequest, callable $onChunk): ProviderResponse`
- `getModelList(): array`

## Expectations

- Providers stay stateless after construction except for the injected credential.
- Provider classes must not log or expose the raw API key.
- `sendPrompt()` returns a normalized `ProviderResponse` with:
  - `provider`
  - `model`
  - final `content`
  - optional token usage
  - raw decoded payload for debugging
- `streamPrompt()` may progressively emit chunks through the callback, then return the final normalized response.
- `getModelList()` returns the Harmony-supported models for that provider.

## Factory integration

- `App\AI\ProviderFactory` is responsible for:
  - selecting the provider from the project configuration
  - resolving BYOK first, then platform credentials
  - instantiating the provider with the decrypted credential

## Adding a new provider

1. Implement `AIProviderInterface`.
2. Add the supported model list.
3. Normalize the remote payload into `ProviderResponse`.
4. Register selection logic in `ProviderFactory::createForProvider()`.
5. Add unit tests for success, invalid JSON and HTTP failure cases.
