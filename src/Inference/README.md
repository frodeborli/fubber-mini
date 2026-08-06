# Inference — LLM Seam

The integration point between Mini and a language model. Mini ships the **interface only**: `InferenceServiceInterface`, the `inference()` accessor, and `InferenceException`. There is no bundled provider client, no prompt templating, no agent loop — a forkable core defines the seam, the vendor SDK lives outside it.

Two things make this worth having in the core rather than in a more opinionated layer above it:

1. It is a **contract**, not a feature. Ten lines of interface that let library and aspect authors depend on "an LLM is available" without depending on OpenAI, Anthropic, Ollama, or llama.cpp.
2. It closes the loop with `mini\Validator`. Mini already describes data with JSON Schema-compatible validators; structured LLM output is described the same way. The schema you validate with is the schema you constrain generation with — one description, both directions.

## Philosophy

- **Structured output only.** `evaluate()` takes a prompt and a schema and returns a value matching that schema. There is no free-text completion method, no chat transcript type, no streaming callback. Those are provider-shaped APIs that age badly; a schema-constrained function call does not.
- **The schema is a `Validator`.** Anything `JsonSerializable` that produces JSON Schema works, as does a raw array — but a `mini\Validator\Validator` is the intended input, because it is also what you validate the response with.
- **No provider vocabulary.** No model names, temperature, token budgets, or tool definitions in the interface. Those belong to the implementation and its config file, where they can change without breaking callers.
- **Fail fast when unconfigured.** `inference()` resolves through the service container, which throws if no config file provides an implementation. Mini never silently degrades to a stub.

## Configuration

`InferenceServiceInterface` is registered as a singleton whose factory calls `Mini::$mini->loadServiceConfig(InferenceServiceInterface::class)`. That resolves to:

```
_config/mini/Inference/InferenceServiceInterface.php
```

The file returns an instance:

```php
<?php
// _config/mini/Inference/InferenceServiceInterface.php
return new App\Inference\OllamaInference(model: 'llama3.2');
```

Without that file, `inference()` throws — the config is not optional, because Mini has no default model to call.

## Usage

```php
use function mini\inference;
use function mini\validator;

// Boolean judgement
$needsReview = inference()->evaluate(
    "Does this support message require human action?\n\n$text",
    validator()->enum([true, false])
);

// Classification
$category = inference()->evaluate(
    "Classify this issue report:\n\n$text",
    validator()->enum(['bug', 'feature', 'question'])
);

// Structured extraction
$contact = inference()->evaluate(
    "Extract the contact details:\n\n$text",
    validator()->type('object')->properties([
        'name'  => validator()->type('string')->required(),
        'email' => validator()->type('string')->format('email'),
    ])
);
```

### Reusing an entity's schema

Because `validator(SomeClass::class)` builds a validator from the class's `#[Type]`, `#[Required]`, `#[Format]` … attributes, the schema that guards your writes can constrain generation too:

```php
use function mini\inference;
use function mini\validator;

$draft = inference()->evaluate(
    "Draft a support ticket from this email:\n\n$body",
    validator(SupportTicket::class)
);
```

One declaration, used for validation, JSON Schema export, and LLM output constraint.

### Batches

```php
$verdicts = inference()->batchEvaluate(
    array_map(fn($c) => "Is this comment spam?\n\n$c", $comments),
    validator()->enum([true, false])
);
```

Results come back in the same order as the prompts. Implementations with native batch endpoints should use them; the rest simply loop over `evaluate()`.

## The interface

`mini\Inference\InferenceServiceInterface`:

| Method | Purpose |
| ------ | ------- |
| `evaluate(string $prompt, Validator\|\JsonSerializable\|array $schema): mixed` | Evaluate one prompt; return a value matching `$schema`. |
| `batchEvaluate(array $prompts, Validator\|\JsonSerializable\|array $schema): array` | Evaluate many prompts against one schema; results in input order. |

`mini\Inference\InferenceException` (extends `RuntimeException`) is the failure type: service unavailable, timeout, rate limit, or a response that does not match the schema.

## Implementing a provider

```php
namespace App\Inference;

use mini\Inference\InferenceException;
use mini\Inference\InferenceServiceInterface;
use mini\Validator\Validator;

final class OllamaInference implements InferenceServiceInterface
{
    public function __construct(
        private string $model = 'llama3.2',
        private string $baseUrl = 'http://localhost:11434',
    ) {}

    public function evaluate(string $prompt, Validator|\JsonSerializable|array $schema): mixed
    {
        $jsonSchema = $schema instanceof \JsonSerializable ? $schema->jsonSerialize() : $schema;

        $raw = $this->post('/api/generate', [
            'model'  => $this->model,
            'prompt' => $prompt,
            'format' => $jsonSchema,
            'stream' => false,
        ]);

        $result = json_decode($raw['response'] ?? '', true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new InferenceException('Model did not return valid JSON');
        }

        // Trust nothing: the schema is also the validator.
        if ($schema instanceof Validator && ($error = $schema->validate($result))) {
            throw new InferenceException("Response failed schema validation: $error");
        }

        return $result;
    }

    public function batchEvaluate(array $prompts, Validator|\JsonSerializable|array $schema): array
    {
        return array_map(fn(string $p) => $this->evaluate($p, $schema), $prompts);
    }

    private function post(string $path, array $payload): array { /* PSR-18 client */ }
}
```

Implementation obligations:

1. **Honour the schema.** Use the provider's structured-output/JSON-mode facility if it has one; validate the response if it does not.
2. **Throw `InferenceException`, not the SDK's exception type.** Callers depend on the seam, not on your client library.
3. **Preserve batch order.** `batchEvaluate()` results are positional.

## Deliberately absent

Prompt templates, conversation memory, embeddings, vector search, tool/function calling, streaming, retries, and cost accounting are **not** part of this module. They are opinionated, they move fast, and they are exactly the kind of thing that belongs in an application or in a "Maxi" layer built on top of Mini. The core keeps the one abstraction that is likely to survive: *prompt in, schema-shaped value out*.

## See also

- **[src/Validator/README.md](../Validator/README.md)** — building schemas fluently and from attributes
- **[src/Metadata/README.md](../Metadata/README.md)** — titles, descriptions, and examples that make a schema self-describing to a model
- **[src/Http/Client/README.md](../Http/Client/README.md)** — the PSR-18 client to call a provider with
