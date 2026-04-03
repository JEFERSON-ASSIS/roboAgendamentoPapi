<?php

namespace App\Service;

use App\Infrastructure\Http\HttpClient;
use RuntimeException;

class OpenAIClient
{
    public function __construct(
        private readonly HttpClient $httpClient,
        private readonly string $apiKey,
        private readonly string $baseUrl = 'https://api.openai.com/v1'
    ) {
    }

    public function chatJson(array $messages, string $model): array
    {
        if ($this->apiKey === '') {
            throw new RuntimeException('AI_API_KEY nao configurada.');
        }

        $response = $this->httpClient->post(
            rtrim($this->baseUrl, '/') . '/chat/completions',
            [
                'model' => $model,
                'temperature' => 0.1,
                'response_format' => ['type' => 'json_object'],
                'messages' => $messages,
            ],
            [
                'Authorization' => 'Bearer ' . $this->apiKey,
            ],
            60
        );

        if (($response['status'] ?? 0) >= 400) {
            throw new RuntimeException('Erro OpenAI HTTP ' . ($response['status'] ?? 0) . ': ' . ($response['body'] ?? ''));
        }

        $content = $response['json']['choices'][0]['message']['content'] ?? null;

        if (!is_string($content) || trim($content) === '') {
            throw new RuntimeException('Resposta vazia da OpenAI.');
        }

        $decoded = json_decode($content, true);

        if (!is_array($decoded)) {
            throw new RuntimeException('A OpenAI nao retornou JSON valido.');
        }

        return $decoded;
    }

    public function transcribeAudioFile(
        string $filePath,
        string $model = 'gpt-4o-mini-transcribe',
        ?string $language = 'pt',
        ?string $prompt = null
    ): array {
        if ($this->apiKey === '') {
            throw new RuntimeException('AI_API_KEY nao configurada.');
        }

        if (!is_file($filePath)) {
            throw new RuntimeException('Arquivo de audio nao encontrado para transcricao.');
        }

        $ch = curl_init();

        if ($ch === false) {
            throw new RuntimeException('Falha ao inicializar cURL para transcricao de audio.');
        }

        $mimeType = $this->resolveAudioMimeType($filePath);
        $postFields = [
            'file' => curl_file_create($filePath, $mimeType, basename($filePath)),
            'model' => $model,
            'response_format' => 'json',
        ];

        if (is_string($language) && trim($language) !== '') {
            $postFields['language'] = trim($language);
        }

        if (is_string($prompt) && trim($prompt) !== '') {
            $postFields['prompt'] = trim($prompt);
        }

        curl_setopt_array($ch, [
            CURLOPT_URL => rtrim($this->baseUrl, '/') . '/audio/transcriptions',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
            ],
            CURLOPT_POSTFIELDS => $postFields,
            CURLOPT_TIMEOUT => 120,
        ]);

        $body = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException('Erro na transcricao de audio: ' . $error);
        }

        if ($status >= 400) {
            throw new RuntimeException('Erro OpenAI audio HTTP ' . $status . ': ' . $body);
        }

        $decoded = json_decode($body, true);

        if (!is_array($decoded)) {
            throw new RuntimeException('A OpenAI nao retornou JSON valido na transcricao de audio.');
        }

        return $decoded;
    }

    private function resolveAudioMimeType(string $filePath): string
    {
        $extension = strtolower((string) pathinfo($filePath, PATHINFO_EXTENSION));

        $mimeFromExtension = match ($extension) {
            'ogg', 'oga', 'opus' => 'audio/ogg',
            'mp3', 'mpeg', 'mpga' => 'audio/mpeg',
            'm4a', 'mp4' => 'audio/mp4',
            'wav' => 'audio/wav',
            'webm' => 'audio/webm',
            default => null,
        };

        if ($mimeFromExtension !== null) {
            return $mimeFromExtension;
        }

        $mimeType = mime_content_type($filePath) ?: 'application/octet-stream';

        return $mimeType === 'application/ogg' ? 'audio/ogg' : $mimeType;
    }
}
