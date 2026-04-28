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
     *
     * `$language` is an ISO-639-1 code (en, es, fr, …). Passing it
     * tells Whisper / gpt-4o-transcribe to expect that language and
     * dramatically reduces transcription drift in voice mode — without
     * it, a user speaking Latvian into a Russian-detected stream would
     * come back as garbled mixed text. Null = auto-detect (legacy).
     *
     * `$prompt` is an optional context hint OpenAI uses to bias the
     * model toward expected vocabulary — proper nouns (avatar names),
     * domain jargon (lab abbreviations, supplement names), and
     * acronyms (PMID, HbA1c). Per the OpenAI transcription guide this
     * is the most cost-effective accuracy boost we can do here. Max
     * 224 tokens; we keep ours well under that.
     *
     * Timeout: dedicated `transcribe_timeout` config (default 90s)
     * because voice-mode clips can be up to 45 s of audio + Whisper's
     * own model latency, which sometimes exceeds the chat 45s timeout
     * we used previously.
     */
    public function transcribe(
        string $audioPath,
        string $model = null,
        string $filename = null,
        ?string $language = null,
        ?string $prompt = null,
    ): string {
        $model    = $model ?? config('services.openai.transcribe_model', 'gpt-4o-transcribe');
        $filename = $filename ?: basename($audioPath);
        $timeout  = (int) config('services.openai.transcribe_timeout', 90);

        $payload = ['model' => $model];
        if ($language) {
            $payload['language'] = $language;
        }
        if ($prompt !== null && $prompt !== '') {
            // Trim defensively to stay well under the 224-token API
            // limit — even at one token per word the cap is generous,
            // but keeping it short also keeps prompt costs down.
            $payload['prompt'] = mb_substr($prompt, 0, 600);
        }

        $response = Http::withToken($this->apiKey)
            ->timeout($timeout)
            ->attach('file', file_get_contents($audioPath), $filename)
            ->post("{$this->baseUrl}/audio/transcriptions", $payload);

        if (!$response->successful()) {
            // Log full body so deploy-time issues are diagnosable; the
            // exception message stays short for the catch path.
            \Illuminate\Support\Facades\Log::error('OpenAI transcribe failed', [
                'status'   => $response->status(),
                'model'    => $model,
                'language' => $language,
                'prompt'   => $prompt !== null ? mb_substr((string) $prompt, 0, 200) : null,
                'body'     => $response->body(),
            ]);
            throw new \RuntimeException(
                "Transcription failed (HTTP {$response->status()}, model={$model})"
            );
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
     * Text-to-speech in raw PCM (16-bit signed little-endian, 24kHz,
     * mono) — LiveAvatar's LITE-mode wire format. We return the raw
     * bytes; chunking + base64 happens at the controller layer so
     * other consumers can still get an mp3/etc via speak().
     */
    public function speakPcm(string $text, string $voice = 'alloy', string $model = null): string
    {
        $model = $model ?? config('services.openai.tts_model', 'gpt-4o-mini-tts');

        $response = Http::withToken($this->apiKey)
            ->timeout($this->timeout)
            ->post("{$this->baseUrl}/audio/speech", [
                'model'           => $model,
                'input'           => $text,
                'voice'           => $voice,
                'response_format' => 'pcm',
            ]);

        if (!$response->successful()) {
            throw new \RuntimeException('TTS PCM failed: ' . $response->body());
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
