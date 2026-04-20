<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('eval_runs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('dataset_id')->constrained('eval_datasets')->restrictOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->integer('cases_total')->default(0);
            $table->integer('cases_passed')->default(0);
            $table->integer('cases_failed')->default(0);
            $table->decimal('score_pct', 5, 2)->nullable();
            $table->string('trigger', 32);
            $table->string('trace_id', 64)->nullable();
            $table->jsonb('metadata_json')->nullable();

            $table->index('dataset_id');
            $table->index('trigger');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eval_runs');
    }
};
