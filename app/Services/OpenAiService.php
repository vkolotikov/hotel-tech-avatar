<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenAiService
{
    private string $apiKey;
    private string $baseUrl;
    private int $timeout;

    public function __construct()
    {
        $this->apiKey  = config('services.openai.api_key', '');
        $this->baseUrl = config('services.openai.base_url', 'https://api.openai.com/v1');
        $this->timeout = (int) config('services.openai.timeout', 45);
    }

    /**
     * Transcribe audio to text.
     */
    public function transcribe(string $audioPath, string $model = null, string $filename = null): string
    {
        $model    = $model ?? config('services.openai.transcribe_model', 'gpt-4o-transcribe');
        $filename = $filename ?: basename($audioPath);

        $response = Http::withToken($this->apiKey)
            ->timeout($this->timeout)
            ->attach('file', file_get_contents($audioPath), $filename)
            ->post("{$this->baseUrl}/audio/transcriptions", [
                'model' => $model,
            ]);

        if (!$response->successful()) {
            throw new \RuntimeException('Transcription failed: ' . $response->body());
        }

        return $response->json('text', '');
    }

    /**
     * Text-to-speech.
     */
    public function speak(string $text, string $voice = 'alloy', string $model = null): string
    {
        $model = $model ?? config('services.openai.tts_model', 'gpt-4o-mini-tts');

        $response = Http::withToken($this->apiKey)
            ->timeout($this->timeout)
            ->post("{$this->baseUrl}/audio/speech", [
                'model'           => $model,
                'input'           => $text,
                'voice'           => $voice,
                'response_format' => 'mp3',
            ]);

        if (!$response->successful()) {
            throw new \RuntimeException('TTS failed: ' . $response->body());
        }

        return $response->body();
    }

    /**
     * Create a file in OpenAI for vector store use.
     */
    public function uploadFile(string $filePath, string $purpose = 'assistants'): array
    {
        $response = Http::withToken($this->apiKey)
            ->timeout($this->timeout)
            ->attach('file', file_get_contents($filePath), basename($filePath))
            ->post("{$this->baseUrl}/files", [
                'purpose' => $purpose,
            ]);

        if (!$response->successful()) {
            throw new \RuntimeException('File upload failed: ' . $response->body());
        }

        return $response->json();
    }

    /**
     * Create a vector store.
     */
    public function createVectorStore(string $name): array
    {
        return $this->request('POST', '/vector_stores', ['name' => $name]);
    }

    /**
     * Add file to vector store.
     */
    public function addFileToVectorStore(string $vectorStoreId, string $fileId): array
    {
        return $this->request('POST', "/vector_stores/{$vectorStoreId}/files", [
            'file_id' => $fileId,
        ]);
    }

    /**
     * Delete a file from OpenAI.
     */
    public function deleteFile(string $fileId): void
    {
        $this->request('DELETE', "/files/{$fileId}");
    }

    private function request(string $method, string $path, array $body = []): array
    {
        $http = Http::withToken($this->apiKey)
            ->timeout($this->timeout)
            ->withHeaders(['Content-Type' => 'application/json']);

        $url = $this->baseUrl . $path;

        $response = match (strtoupper($method)) {
            'POST'   => $http->post($url, $body),
            'DELETE'  => $http->delete($url, $body),
            'GET'     => $http->get($url, $body),
            default   => $http->post($url, $body),
        };

        if (!$response->successful()) {
            Log::error("OpenAI API error: {$method} {$path}", [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            throw new \RuntimeException("OpenAI API error: " . $response->body());
        }

        return $response->json() ?? [];
    }
}
