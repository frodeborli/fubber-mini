<?php
/**
 * Default InferenceServiceInterface configuration for Mini framework
 *
 * Applications MUST provide their own implementation by creating:
 *   _config/mini/Inference/InferenceServiceInterface.php
 *
 * Example (_config/mini/Inference/InferenceServiceInterface.php):
 *
 *   <?php
 *   return new App\Inference\OllamaInference(
 *       model: 'llama3.2',
 *       baseUrl: 'http://localhost:11434'
 *   );
 *
 * Or using Claude API:
 *
 *   <?php
 *   return new App\Inference\ClaudeInference(
 *       apiKey: $_ENV['ANTHROPIC_API_KEY']
 *   );
 */

throw new \mini\Exceptions\ConfigurationRequiredException(
    'mini/Inference/InferenceServiceInterface.php',
    'inference service implementation (create _config/mini/Inference/InferenceServiceInterface.php and return your InferenceServiceInterface implementation)'
);
