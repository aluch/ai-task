<?php

declare(strict_types=1);

namespace App\AI;

use App\AI\DTO\ClaudeResponse;
use App\AI\Exception\ClaudeClientException;
use App\AI\Exception\ClaudeRateLimitException;
use App\AI\Exception\ClaudeTransientException;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class ClaudeClient
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';
    private const API_VERSION = '2023-06-01';
    private const DEFAULT_MODEL = 'claude-opus-4-6';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly string $apiKey,
    ) {
    }

    /**
     * @param array<array{role: string, content: string|array}> $messages
     * @param array|null $tools список tool-деклараций в формате Anthropic API
     *   (каждая: {name, description, input_schema})
     * @param bool $cacheSystem пометить system prompt как кешируемый (ephemeral)
     * @param bool $cacheTools пометить tools как кешируемые (cache_control на
     *   последнем tool — Anthropic кэширует всё, что выше последнего маркера)
     */
    public function createMessage(
        string $systemPrompt,
        array $messages,
        ?string $model = null,
        int $maxTokens = 1024,
        ?float $temperature = null,
        ?array $tools = null,
        bool $cacheSystem = false,
        bool $cacheTools = false,
    ): ClaudeResponse {
        $model ??= self::DEFAULT_MODEL;
        $start = microtime(true);

        $body = [
            'model' => $model,
            'max_tokens' => $maxTokens,
            'messages' => $messages,
        ];

        if ($cacheSystem) {
            $body['system'] = [
                [
                    'type' => 'text',
                    'text' => $systemPrompt,
                    'cache_control' => ['type' => 'ephemeral'],
                ],
            ];
        } else {
            $body['system'] = $systemPrompt;
        }

        if ($temperature !== null) {
            $body['temperature'] = $temperature;
        }

        if ($tools !== null && $tools !== []) {
            if ($cacheTools) {
                // cache_control на последнем tool в массиве — Anthropic
                // кэширует всё что до последнего маркера по префиксу.
                $lastIndex = array_key_last($tools);
                $tools[$lastIndex]['cache_control'] = ['type' => 'ephemeral'];
            }
            $body['tools'] = $tools;
        }

        try {
            $response = $this->httpClient->request('POST', self::API_URL, [
                'headers' => [
                    'x-api-key' => $this->apiKey,
                    'anthropic-version' => self::API_VERSION,
                    'Content-Type' => 'application/json',
                ],
                'json' => $body,
                'timeout' => 60,
            ]);

            $statusCode = $response->getStatusCode();
            $data = $response->toArray(false);
        } catch (\Throwable $e) {
            $elapsed = round(microtime(true) - $start, 2);
            $this->logger->error('Claude API network error', [
                'model' => $model,
                'elapsed' => $elapsed,
                'error' => $e->getMessage(),
            ]);
            throw new ClaudeTransientException('Network error: ' . $e->getMessage(), 0, $e);
        }

        $elapsed = round(microtime(true) - $start, 2);

        if ($statusCode === 429) {
            $retryAfter = $response->getHeaders(false)['retry-after'][0] ?? null;
            $this->logger->warning('Claude API rate limited', [
                'model' => $model,
                'retry_after' => $retryAfter,
            ]);
            throw new ClaudeRateLimitException(
                $data['error']['message'] ?? 'Rate limited',
                $retryAfter !== null ? (int) $retryAfter : null,
            );
        }

        if ($statusCode >= 500) {
            $this->logger->warning('Claude API server error', [
                'model' => $model,
                'status' => $statusCode,
                'elapsed' => $elapsed,
            ]);
            throw new ClaudeTransientException(
                sprintf('Server error %d: %s', $statusCode, $data['error']['message'] ?? 'unknown'),
            );
        }

        if ($statusCode >= 400) {
            $this->logger->error('Claude API client error', [
                'model' => $model,
                'status' => $statusCode,
                'error' => $data['error'] ?? null,
            ]);
            throw new ClaudeClientException(
                sprintf('Client error %d: %s', $statusCode, $data['error']['message'] ?? 'unknown'),
            );
        }

        $text = $this->extractText($data);
        $usage = $data['usage'] ?? [];
        $inputTokens = $usage['input_tokens'] ?? 0;
        $outputTokens = $usage['output_tokens'] ?? 0;
        $cacheCreationTokens = $usage['cache_creation_input_tokens'] ?? 0;
        $cacheReadTokens = $usage['cache_read_input_tokens'] ?? 0;

        $this->logger->info('Claude API call', [
            'model' => $model,
            'input_tokens' => $inputTokens,
            'cache_read_tokens' => $cacheReadTokens,
            'cache_creation_tokens' => $cacheCreationTokens,
            'output_tokens' => $outputTokens,
            'elapsed' => $elapsed,
            'stop_reason' => $data['stop_reason'] ?? 'unknown',
        ]);

        return new ClaudeResponse(
            text: $text,
            stopReason: $data['stop_reason'] ?? 'unknown',
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            cacheCreationInputTokens: $cacheCreationTokens,
            cacheReadInputTokens: $cacheReadTokens,
            data: $data,
        );
    }

    private function extractText(array $data): string
    {
        $parts = [];
        foreach ($data['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') {
                $parts[] = $block['text'];
            }
        }

        return implode('', $parts);
    }
}
