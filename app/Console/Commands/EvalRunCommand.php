<?php

namespace App\Console\Commands;

use App\Eval\Loader;
use App\Eval\Runner;
use App\Models\EvalDataset;
use Illuminate\Console\Command;
use Symfony\Component\Finder\Finder;

class EvalRunCommand extends Command
{
    protected $signature = 'eval:run {--dataset= : Only run the dataset with this slug} {--trigger=manual : manual|scheduled|ci}';

    protected $description = 'Sync YAML datasets from docs/eval/datasets and execute them, writing eval_runs + eval_results.';

    public function handle(Loader $loader, Runner $runner): int
    {
        $slug = $this->option('dataset');
        $trigger = $this->option('trigger') ?: 'manual';

        $paths = $this->discoverYaml();
        if (empty($paths)) {
            $this->warn('No YAML datasets found under docs/eval/datasets/.');
            return self::SUCCESS;
        }

        $datasetIds = [];
        foreach ($paths as $path) {
            try {
                $datasetIds[] = $loader->sync($path);
            } catch (\Throwable $e) {
                $this->error("failed to sync {$path}: {$e->getMessage()}");
                return self::FAILURE;
            }
        }

        $query = EvalDataset::query()->whereIn('id', $datasetIds);
        if ($slug !== null) {
            $query->where('slug', $slug);
        }
        $datasets = $query->get();

        if ($datasets->isEmpty()) {
            $this->warn($slug ? "no dataset with slug={$slug}" : 'no datasets synced');
            return self::SUCCESS;
        }

        foreach ($datasets as $dataset) {
            $runId = $runner->runDataset($dataset->id, $trigger);
            $run = \App\Models\EvalRun::find($runId);
            $this->line(sprintf(
                '[run #%d] %s: %d/%d passed (%s%%)',
                $run->id,
                $dataset->slug,
                $run->cases_passed,
                $run->cases_total,
                $run->score_pct ?? 'n/a'
            ));
        }

        return self::SUCCESS;
    }

    /** @return array<int,string> */
    private function discoverYaml(): array
    {
        $root = base_path('docs/eval/datasets');
        if (!is_dir($root)) {
            return [];
        }
        $out = [];
        foreach ((new Finder())->in($root)->files()->name(['*.yaml', '*.yml']) as $file) {
            $out[] = $file->getPathname();
        }
        return $out;
    }
}
