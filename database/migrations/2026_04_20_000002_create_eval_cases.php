<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('eval_cases', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('dataset_id')->constrained('eval_datasets')->cascadeOnDelete();
            $table->string('slug', 128);
            $table->text('prompt');
            $table->jsonb('context_json')->nullable();
            $table->text('stub_response')->nullable();
            $table->jsonb('assertions_json');
            $table->timestamps();

            $table->unique(['dataset_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eval_cases');
    }
};
