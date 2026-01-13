<?php

namespace mini;

use mini\Inference\InferenceServiceInterface;

/**
 * Inference Feature - Global Helper Functions
 *
 * Provides the public API for LLM-based structured inference.
 */

// Register InferenceServiceInterface - apps must provide implementation via config
Mini::$mini->addService(
    InferenceServiceInterface::class,
    Lifetime::Singleton,
    fn() => Mini::$mini->loadServiceConfig(InferenceServiceInterface::class)
);

/**
 * Get the inference service instance
 *
 * Returns the configured InferenceServiceInterface implementation for
 * LLM-based structured evaluation.
 *
 * Usage:
 *   // Boolean question
 *   $result = inference()->evaluate("Is this spam?\n\n$text", validator()->enum([true, false]));
 *
 *   // Classification
 *   $category = inference()->evaluate("Classify:\n\n$text", validator()->enum(['bug', 'feature', 'question']));
 *
 *   // Structured extraction
 *   $data = inference()->evaluate($prompt, validator()->type('object')->properties([
 *       'summary' => validator()->type('string')->required(),
 *       'priority' => validator()->enum(['low', 'medium', 'high']),
 *   ]));
 *
 * @return InferenceServiceInterface Inference service instance
 * @throws \mini\Exceptions\ConfigurationRequiredException If no implementation configured
 */
function inference(): InferenceServiceInterface
{
    return Mini::$mini->get(InferenceServiceInterface::class);
}
