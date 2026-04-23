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

        if (!is_array($parsed)) {
            throw new \RuntimeException("dataset {$absolutePath} is empty or not a YAML mapping");
        }

        foreach (['slug', 'name', 'vertical', 'cases'] as $k) {
            if (!array_key_exists($k, $parsed)) {
                throw new \RuntimeException("dataset {$absolutePath} missing required key: {$k}");
            }
        }

        if (!is_array($parsed['cases'])) {
            throw new \RuntimeException("dataset {$absolutePath}: 'cases' must be a list");
        }

        $relativePath = $this->relativePath($absolutePath);

        return DB::transaction(function () use ($parsed, $hash, $relativePath) {
            $existing = EvalDataset::where('slug', $parsed['slug'])->first();
            $unchanged = $existing && $existing->source_hash === $hash;

            $update = [
                'name' => $parsed['name'],
                'vertical_slug' => $parsed['vertical'],
                'avatar_slug' => $parsed['avatar_slug'] ?? null,
                'description' => $parsed['description'] ?? null,
                'source_path' => $relativePath,
                'source_hash' => $hash,
            ];

            // Persist top-level "mode: live" / "mode: stubbed" from the YAML
            // into mode_json so Runner picks it up.
            if (isset($parsed['mode']) && is_string($parsed['mode'])) {
                $update['mode_json'] = ['mode' => $parsed['mode']];
            }

            $dataset = EvalDataset::updateOrCreate(
                ['slug' => $parsed['slug']],
                $update,
            );

            if ($unchanged && $dataset->cases()->exists()) {
                return $dataset->id;
            }

            $incomingSlugs = [];
            foreach ($parsed['cases'] as $i => $case) {
                if (empty($case['slug'])) {
                    throw new \RuntimeException("case #{$i} in {$relativePath} missing slug");
                }
                $incomingSlugs[] = $case['slug'];
                EvalCase::updateOrCreate(
                    ['dataset_id' => $dataset->id, 'slug' => $case['slug']],
                    [
                        'prompt' => $case['prompt'] ?? '',
                        'context_json' => $case['context'] ?? null,
                        'stub_response' => $case['stub_response'] ?? null,
                        'assertions_json' => $case['assertions'] ?? [],
                    ]
                );
            }

            // Prune cases that were removed from YAML, but only if they have no
            // historical results. Cases with results are retained as orphans so
            // prior eval_runs remain auditable.
            $dataset->cases()
                ->whereNotIn('slug', $incomingSlugs)
                ->whereDoesntHave('results')
                ->delete();

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
