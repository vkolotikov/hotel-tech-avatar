<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('eval_results', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('run_id')->constrained('eval_runs')->cascadeOnDelete();
            $table->foreignId('case_id')->constrained('eval_cases')->restrictOnDelete();
            $table->smallInteger('assertion_index');
            $table->string('assertion_type', 64);
            $table->boolean('passed');
            $table->text('actual_response')->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('run_id');
            $table->index('case_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eval_results');
    }
};
