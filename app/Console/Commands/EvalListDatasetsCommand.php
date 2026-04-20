<?php

namespace App\Console\Commands;

use App\Models\EvalDataset;
use Illuminate\Console\Command;

class EvalListDatasetsCommand extends Command
{
    protected $signature = 'eval:list-datasets';

    protected $description = 'List eval_datasets rows currently synced from YAML.';

    public function handle(): int
    {
        $rows = EvalDataset::orderBy('vertical_slug')->orderBy('slug')->get([
            'slug', 'vertical_slug', 'avatar_slug', 'source_path',
        ])->map(fn ($d) => [
            'slug' => $d->slug,
            'vertical' => $d->vertical_slug,
            'avatar' => $d->avatar_slug ?? '-',
            'source' => $d->source_path,
        ])->all();

        if (empty($rows)) {
            $this->warn('no datasets synced; run php artisan eval:run first');
            return self::SUCCESS;
        }

        $this->table(['slug', 'vertical', 'avatar', 'source'], $rows);
        return self::SUCCESS;
    }
}
