<?php

namespace App\Neuron\Providers;

use NeuronAI\HttpClient\HttpClientInterface;
use NeuronAI\Providers\OpenAI\OpenAI;

/**
 * EdenAIProvider — Uses NeuronAI's OpenAI provider architecture, but directs calls
 * to EdenAI v3 chat completions endpoint (https://api.edenai.run/v3).
 *
 * This allows NeuronAI agents to run with our existing EdenAI API key,
 * without requiring direct Anthropic/OpenAI keys while preserving full NeuronAI functionality!
 */
class EdenAIProvider extends OpenAI
{
    protected string $baseUri = 'https://api.edenai.run/v3';

    public function __construct(
        string $key,
        string $model,
        array $parameters = [],
        bool $strict_response = false,
        ?HttpClientInterface $httpClient = null,
    ) {
        // Ensure model has provider prefix for EdenAI (e.g. "anthropic/claude-sonnet-4-6")
        parent::__construct($key, $model, $parameters, $strict_response, $httpClient);
    }
}
