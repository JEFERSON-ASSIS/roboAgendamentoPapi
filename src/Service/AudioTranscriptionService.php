<?php

namespace App\Service;

use App\DTO\IncomingMessageDTO;
use App\Infrastructure\Http\HttpClient;
use App\Infrastructure\Logging\Logger;
use RuntimeException;
use Throwable;

class AudioTranscriptionService
{
    public function __construct(
        private readonly HttpClient $httpClient,
        private readonly OpenAIClient $openAIClient,
        private readonly Logger $logger,
        private readonly string $model = 'gpt-4o-mini-transcribe',
        private readonly string $language = 'pt',
        private readonly string $whatsAppBaseUrl = '',
        private readonly string $whatsAppApiKey = '',
        private readonly bool $debugSaveAudio = false,
        private readonly string $debugAudioPath = 'storage/audio-debug'
    ) {
    }

    public function transcribe(IncomingMessageDTO $message): IncomingMessageDTO
    {
        if ($message->messageType !== 'audio') {
            return $message;
        }

        [$binary, $extension, $sourceMeta] = $this->resolveAudioBinary($message);
        $tempPath = $this->writeTempAudioFile($binary, $extension);
        $debugPath = $this->debugSaveAudio ? $this->writeDebugAudioFile($binary, $extension, $message->phone) : null;
        $diagnostics = $this->buildBinaryDiagnostics($binary, $tempPath, $extension);

        $this->logger->info('Audio preparado para transcricao.', [
            'phone' => $message->phone,
            'media_url' => $message->mediaUrl,
            'extension' => $extension,
            'source_meta' => $sourceMeta,
            'temp_path' => $tempPath,
            'debug_path' => $debugPath,
            'diagnostics' => $diagnostics,
        ]);

        try {
            $result = $this->openAIClient->transcribeAudioFile(
                $tempPath,
                $this->model,
                $this->language,
                'Transcreva em portugues do Brasil com pontuacao simples e preserve numeros, datas, horarios, nomes e CPFs quando forem falados.'
            );
        } catch (Throwable $exception) {
            $this->logger->warning('Diagnostico de falha ao enviar audio para a OpenAI.', [
                'phone' => $message->phone,
                'media_url' => $message->mediaUrl,
                'temp_path' => $tempPath,
                'debug_path' => $debugPath,
                'extension' => $extension,
                'source_meta' => $sourceMeta,
                'diagnostics' => $diagnostics,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        } finally {
            if (is_file($tempPath)) {
                @unlink($tempPath);
            }
        }

        $transcript = trim((string) ($result['text'] ?? ''));

        if ($transcript === '') {
            throw new RuntimeException('A transcricao do audio veio vazia.');
        }

        $payload = $message->payload;
        $payload['audio_transcription'] = [
            'text' => $transcript,
            'model' => $this->model,
            'language' => $this->language,
            'source' => 'openai_audio_transcription',
            'debug_path' => $debugPath,
        ];

        $this->logger->info('Audio transcrito com sucesso.', [
            'phone' => $message->phone,
            'message_type' => $message->messageType,
            'transcript_preview' => mb_substr($transcript, 0, 120),
            'model' => $this->model,
            'debug_path' => $debugPath,
        ]);

        return new IncomingMessageDTO(
            phone: $message->phone,
            messageType: $message->messageType,
            message: $transcript,
            mediaUrl: $message->mediaUrl,
            pushName: $message->pushName,
            payload: $payload
        );
    }

    private function resolveAudioBinary(IncomingMessageDTO $message): array
    {
        $audioPayload = $this->extractAudioPayload($message->payload);
        $mimeType = $this->extractMimeType($audioPayload);
        $extension = $this->extensionFromMimeType($mimeType);

        $base64 = $this->extractBase64($audioPayload);
        if ($base64 !== null) {
            $decoded = base64_decode($base64, true);

            if ($decoded === false || $decoded === '') {
                throw new RuntimeException('Falha ao decodificar o base64 do audio recebido.');
            }

            return [$decoded, $extension, [
                'mode' => 'base64',
                'mime_type' => $mimeType,
            ]];
        }

        if (!is_string($message->mediaUrl) || trim($message->mediaUrl) === '') {
            throw new RuntimeException('Nao encontrei URL nem base64 do audio para transcricao.');
        }

        $mediaUrl = $this->resolveMediaUrl($message->mediaUrl);
        $headers = [];

        if ($this->whatsAppApiKey !== '') {
            $headers['apikey'] = $this->whatsAppApiKey;
        }

        $response = $this->httpClient->get($mediaUrl, $headers, 90);

        if (($response['status'] ?? 0) >= 400) {
            throw new RuntimeException('Falha ao baixar audio para transcricao. HTTP ' . ($response['status'] ?? 0));
        }

        $json = $response['json'] ?? null;
        if (is_array($json)) {
            $base64FromResponse = $this->extractBase64($json);
            if ($base64FromResponse !== null) {
                $decoded = base64_decode($base64FromResponse, true);
                if ($decoded === false || $decoded === '') {
                    throw new RuntimeException('Falha ao decodificar o audio retornado pela API de midia.');
                }

                return [$decoded, $extension, [
                    'mode' => 'json_base64',
                    'mime_type' => $mimeType,
                    'status' => $response['status'] ?? null,
                    'response_content_type' => $response['headers']['Content-Type'] ?? $response['headers']['content-type'] ?? null,
                ]];
            }
        }

        $binary = (string) ($response['body'] ?? '');

        if ($binary === '') {
            throw new RuntimeException('O download do audio retornou conteudo vazio.');
        }

        if ($extension === 'bin') {
            $extension = $this->extensionFromUrl($mediaUrl) ?? 'ogg';
        }

        $extension = $this->normalizeAudioExtension($extension);
        $responseContentType = $response['headers']['Content-Type'] ?? $response['headers']['content-type'] ?? null;

        return [$binary, $extension, [
            'mode' => 'http_download',
            'mime_type' => $mimeType,
            'status' => $response['status'] ?? null,
            'resolved_url' => $mediaUrl,
            'response_content_type' => $responseContentType,
            'response_headers' => $this->filterInterestingHeaders($response['headers'] ?? []),
        ]];
    }

    private function writeTempAudioFile(string $binary, string $extension): string
    {
        $directory = base_path('storage/tmp');

        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $extension = $this->normalizeAudioExtension($extension);
        $extension = preg_replace('/[^a-z0-9]+/i', '', strtolower($extension)) ?: 'ogg';
        $path = $directory . DIRECTORY_SEPARATOR . 'audio_' . uniqid('', true) . '.' . $extension;

        if (file_put_contents($path, $binary) === false) {
            throw new RuntimeException('Falha ao salvar audio temporario para transcricao.');
        }

        return $path;
    }

    private function writeDebugAudioFile(string $binary, string $extension, string $phone): string
    {
        $directory = base_path($this->debugAudioPath);

        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $extension = preg_replace('/[^a-z0-9]+/i', '', strtolower($this->normalizeAudioExtension($extension))) ?: 'ogg';
        $phone = preg_replace('/\D+/', '', $phone) ?: 'unknown';
        $path = $directory . DIRECTORY_SEPARATOR . 'audio_debug_' . $phone . '_' . date('Ymd_His') . '_' . uniqid('', true) . '.' . $extension;

        if (file_put_contents($path, $binary) === false) {
            throw new RuntimeException('Falha ao salvar audio de debug.');
        }

        return $path;
    }

    private function extractAudioPayload(array $payload): array
    {
        $body = $payload['body'] ?? $payload;
        $data = $body['data'] ?? [];
        $message = $data['message'] ?? [];

        return is_array($message['audioMessage'] ?? null) ? $message['audioMessage'] : [];
    }

    private function extractMimeType(array $audioPayload): ?string
    {
        $mimeType = $audioPayload['mimetype'] ?? $audioPayload['mimeType'] ?? null;

        return is_string($mimeType) && trim($mimeType) !== '' ? trim($mimeType) : null;
    }

    private function extractBase64(array $source): ?string
    {
        $candidates = [
            $source['base64'] ?? null,
            $source['fileBase64'] ?? null,
            $source['mediaBase64'] ?? null,
            $source['data'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (!is_string($candidate) || trim($candidate) === '') {
                continue;
            }

            $value = trim($candidate);

            if (str_starts_with($value, 'data:')) {
                $parts = explode(',', $value, 2);
                $value = $parts[1] ?? '';
            }

            if ($value !== '' && !str_contains($value, 'http')) {
                return $value;
            }
        }

        return null;
    }

    private function resolveMediaUrl(string $mediaUrl): string
    {
        $mediaUrl = trim($mediaUrl);

        if (preg_match('#^https?://#i', $mediaUrl) === 1) {
            return $mediaUrl;
        }

        if ($this->whatsAppBaseUrl === '') {
            return $mediaUrl;
        }

        return rtrim($this->whatsAppBaseUrl, '/') . '/' . ltrim($mediaUrl, '/');
    }

    private function extensionFromMimeType(?string $mimeType): string
    {
        if ($mimeType === null || $mimeType === '') {
            return 'bin';
        }

        $normalized = strtolower(trim(explode(';', $mimeType)[0]));

        return match ($normalized) {
            'audio/ogg', 'audio/opus', 'application/ogg' => 'ogg',
            'audio/mpeg', 'audio/mp3' => 'mp3',
            'audio/mp4', 'audio/m4a' => 'm4a',
            'audio/wav', 'audio/x-wav' => 'wav',
            'audio/webm' => 'webm',
            default => 'bin',
        };
    }

    private function normalizeAudioExtension(string $extension): string
    {
        $extension = strtolower(trim($extension));

        return match ($extension) {
            'oga', 'opus' => 'ogg',
            'mpeg' => 'mp3',
            default => $extension === '' ? 'ogg' : $extension,
        };
    }

    private function extensionFromUrl(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);

        if (!is_string($path) || $path === '') {
            return null;
        }

        $extension = pathinfo($path, PATHINFO_EXTENSION);

        return is_string($extension) && $extension !== '' ? strtolower($extension) : null;
    }

    private function buildBinaryDiagnostics(string $binary, string $path, string $extension): array
    {
        return [
            'bytes' => strlen($binary),
            'extension' => $extension,
            'finfo_mime' => $this->detectMimeByBuffer($path),
            'first_bytes_hex' => strtoupper(bin2hex(substr($binary, 0, 16))),
            'first_text_preview' => $this->previewText($binary),
            'looks_like_text_payload' => $this->looksLikeTextPayload($binary),
            'ogg_signature_detected' => str_contains(substr($binary, 0, 32), 'OggS'),
        ];
    }

    private function detectMimeByBuffer(string $path): ?string
    {
        if (!is_file($path)) {
            return null;
        }

        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $mime = finfo_file($finfo, $path) ?: null;
                finfo_close($finfo);

                return is_string($mime) && $mime !== '' ? $mime : null;
            }
        }

        $mime = mime_content_type($path);

        return is_string($mime) && $mime !== '' ? $mime : null;
    }

    private function previewText(string $binary, int $limit = 120): ?string
    {
        $sample = substr($binary, 0, min(strlen($binary), 512));
        $sample = preg_replace('/[^\P{C}\t\r\n]+/u', '', $sample);
        $sample = trim((string) $sample);

        if ($sample === '') {
            return null;
        }

        return mb_substr($sample, 0, $limit);
    }

    private function looksLikeTextPayload(string $binary): bool
    {
        $trimmed = ltrim(substr($binary, 0, 256));

        if ($trimmed === '') {
            return false;
        }

        foreach (['<!DOCTYPE', '<html', '<?xml', '<Error', '{', '['] as $prefix) {
            if (stripos($trimmed, $prefix) === 0) {
                return true;
            }
        }

        return false;
    }

    private function filterInterestingHeaders(array $headers): array
    {
        $interesting = [];

        foreach ($headers as $key => $value) {
            $normalized = strtolower((string) $key);
            if (in_array($normalized, ['content-type', 'content-length', 'content-disposition', 'server', 'x-amz-request-id', 'x-amz-id-2'], true)) {
                $interesting[$key] = $value;
            }
        }

        return $interesting;
    }
}
