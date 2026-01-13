<?php

namespace mini\Inference;

use mini\Validator\Validator;

/**
 * Inference service interface for LLM-based structured evaluation
 *
 * Applications implement this interface to provide LLM inference capabilities.
 * The evaluate() method takes a prompt and a JSON Schema (via Validator or array)
 * and returns a response guaranteed to match the schema.
 *
 * Example implementation using Ollama:
 *
 *     class OllamaInference implements InferenceServiceInterface
 *     {
 *         public function __construct(
 *             private string $model = 'llama3.2',
 *             private string $baseUrl = 'http://localhost:11434'
 *         ) {}
 *
 *         public function evaluate(string $prompt, Validator|\JsonSerializable|array $schema): mixed
 *         {
 *             $response = $this->request('/api/generate', [
 *                 'model' => $this->model,
 *                 'prompt' => $prompt,
 *                 'format' => $schema,
 *                 'stream' => false,
 *             ]);
 *             return json_decode($response['response'], true);
 *         }
 *     }
 *
 * Usage:
 *
 *     use function mini\inference;
 *     use function mini\validator;
 *
 *     // Boolean evaluation
 *     $needsReview = inference()->evaluate(
 *         "Does this message require human action?\n\n$text",
 *         validator()->enum([true, false])
 *     );
 *
 *     // Classification
 *     $sentiment = inference()->evaluate(
 *         "Classify the sentiment:\n\n$text",
 *         validator()->enum(['positive', 'negative', 'neutral'])
 *     );
 *
 *     // Structured extraction
 *     $data = inference()->evaluate(
 *         "Extract contact info:\n\n$text",
 *         validator()->type('object')->properties([
 *             'name' => validator()->type('string')->required(),
 *             'email' => validator()->type('string'),
 *         ])
 *     );
 */
interface InferenceServiceInterface
{
    /**
     * Evaluate a prompt and return a structured response matching the schema
     *
     * The schema parameter accepts:
     * - Validator instance (recommended) - serializes to JSON Schema via JsonSerializable
     * - Any JsonSerializable that produces valid JSON Schema
     * - Raw array representing JSON Schema
     *
     * The implementation should:
     * 1. Send the prompt to the LLM with the schema as output format constraint
     * 2. Parse the JSON response
     * 3. Optionally validate the response matches the schema
     * 4. Return the parsed result
     *
     * @param string $prompt The prompt to send to the LLM
     * @param Validator|\JsonSerializable|array $schema JSON Schema for the expected response
     * @return mixed The structured response matching the schema
     * @throws InferenceException If the inference fails or response is invalid
     */
    public function evaluate(string $prompt, Validator|\JsonSerializable|array $schema): mixed;

    /**
     * Evaluate multiple prompts with the same schema
     *
     * Allows implementations to optimize batch processing where supported.
     * Implementations without native batching should loop over evaluate().
     *
     * @param array<string> $prompts Array of prompts to evaluate
     * @param Validator|\JsonSerializable|array $schema JSON Schema for all responses
     * @return array<mixed> Array of results in same order as prompts
     * @throws InferenceException If any inference fails
     */
    public function batchEvaluate(array $prompts, Validator|\JsonSerializable|array $schema): array;
}
