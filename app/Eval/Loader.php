<?php

namespace App\Eval;

use App\Models\EvalCase;
use App\Models\EvalDataset;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final class Loader
{
    public function sync(string $absolutePath): int
    {
        if (!is_file($absolutePath)) {
            throw new \RuntimeException("dataset file not found: {$absolutePath}");
        }
        $bytes = file_get_contents($absolutePath);
        $hash = hash('sha256', $bytes);

        try {
            $parsed = Yaml::parse($bytes);
        } catch (ParseException $e) {
            throw new \RuntimeException("invalid YAML in {$absolutePath}: {$e->getMessage()}", 0, $e);
        }

        foreach (['slug', 'name', 'vertical', 'cases'] as $k) {
            if (!array_key_exists($k, $parsed)) {
                throw new \RuntimeException("dataset {$absolutePath} missing required key: {$k}");
            }
        }

        $relativePath = $this->relativePath($absolutePath);

        return DB::transaction(function () use ($parsed, $hash, $relativePath) {
            $existing = EvalDataset::where('slug', $parsed['slug'])->first();
            $unchanged = $existing && $existing->source_hash === $hash;

            $dataset = EvalDataset::updateOrCreate(
                ['slug' => $parsed['slug']],
                [
                    'name' => $parsed['name'],
                    'vertical_slug' => $parsed['vertical'],
                    'avatar_slug' => $parsed['avatar_slug'] ?? null,
                    'description' => $parsed['description'] ?? null,
                    'source_path' => $relativePath,
                    'source_hash' => $hash,
                ]
            );

            if ($unchanged && $dataset->cases()->exists()) {
                return $dataset->id;
            }

            $dataset->cases()->delete();
            foreach ($parsed['cases'] as $i => $case) {
                if (empty($case['slug'])) {
                    throw new \RuntimeException("case #{$i} in {$relativePath} missing slug");
                }
                EvalCase::create([
                    'dataset_id' => $dataset->id,
                    'slug' => $case['slug'],
                    'prompt' => $case['prompt'] ?? '',
                    'context_json' => $case['context'] ?? null,
                    'stub_response' => $case['stub_response'] ?? null,
                    'assertions_json' => $case['assertions'] ?? [],
                ]);
            }

            return $dataset->id;
        });
    }

    private function relativePath(string $absolutePath): string
    {
        $base = base_path();
        if (str_starts_with($absolutePath, $base)) {
            return ltrim(str_replace('\\', '/', substr($absolutePath, strlen($base))), '/');
        }
        return $absolutePath;
    }
}
