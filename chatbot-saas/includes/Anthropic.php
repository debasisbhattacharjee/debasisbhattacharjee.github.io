<?php
/**
 * Minimal, dependency-free Claude API client using cURL.
 *
 * This project intentionally avoids Composer (see README.md) so it can be
 * dropped onto any shared host via plain FTP - no `composer install` step,
 * no vendor/ folder, no build process. If you'd rather use the official
 * Anthropic PHP SDK (`composer require anthropic-ai/sdk`), swap this class
 * for `Anthropic\Client` and the calling code below stays the same shape.
 */
class AnthropicClient
{
    private string $apiKey;
    private string $baseUrl = 'https://api.anthropic.com/v1/messages';

    public function __construct(string $apiKey)
    {
        $this->apiKey = $apiKey;
    }

    /**
     * @param array $messages  [['role' => 'user'|'assistant', 'content' => string], ...]
     * @return array{ok:bool, text:?string, error:?string, stop_reason:?string, usage:?array}
     */
    public function chat(array $messages, string $system, string $model, int $maxTokens = 1024): array
    {
        if ($this->apiKey === '') {
            return ['ok' => false, 'text' => null, 'error' => 'No Anthropic API key configured.', 'stop_reason' => null, 'usage' => null];
        }

        $payload = [
            'model' => $model,
            'max_tokens' => $maxTokens,
            'system' => $system,
            'messages' => $messages,
        ];

        $ch = curl_init($this->baseUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: 2023-06-01',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);

        $body = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false) {
            return ['ok' => false, 'text' => null, 'error' => 'Connection error: ' . $curlError, 'stop_reason' => null, 'usage' => null];
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            return ['ok' => false, 'text' => null, 'error' => 'Invalid response from Claude API.', 'stop_reason' => null, 'usage' => null];
        }

        if ($httpCode >= 400) {
            $msg = $data['error']['message'] ?? ('HTTP ' . $httpCode);
            return ['ok' => false, 'text' => null, 'error' => $msg, 'stop_reason' => null, 'usage' => null];
        }

        $stopReason = $data['stop_reason'] ?? null;

        // Safety classifiers can decline a request even on a 200 response.
        if ($stopReason === 'refusal') {
            return [
                'ok' => false,
                'text' => null,
                'error' => 'The assistant declined to answer that message.',
                'stop_reason' => $stopReason,
                'usage' => $data['usage'] ?? null,
            ];
        }

        $text = '';
        foreach (($data['content'] ?? []) as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= $block['text'];
            }
        }

        if ($text === '') {
            return ['ok' => false, 'text' => null, 'error' => 'Empty response from Claude API.', 'stop_reason' => $stopReason, 'usage' => $data['usage'] ?? null];
        }

        return ['ok' => true, 'text' => $text, 'error' => null, 'stop_reason' => $stopReason, 'usage' => $data['usage'] ?? null];
    }
}
