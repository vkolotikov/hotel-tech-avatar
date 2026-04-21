<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(\App\Services\Knowledge\EmbeddingService::class, function ($app) {
            return new \App\Services\Knowledge\EmbeddingService(
                $app->make(\App\Services\Llm\LlmClient::class)
            );
        });

        // Register verification service and its dependencies
        $this->app->singleton(
            \App\Services\Verification\Contracts\ClaimExtractionServiceInterface::class,
            \App\Services\Verification\ClaimExtractionService::class
        );

        $this->app->singleton(
            \App\Services\Verification\Contracts\GroundingServiceInterface::class,
            \App\Services\Verification\GroundingService::class
        );

        $this->app->singleton(
            \App\Services\Verification\Contracts\CitationValidationServiceInterface::class,
            \App\Services\Verification\CitationValidationService::class
        );

        $this->app->singleton(
            \App\Services\Verification\Contracts\SafetyClassifierInterface::class,
            \App\Services\Verification\SafetyClassifier::class
        );

        $this->app->singleton(
            \App\Services\Verification\Contracts\StructuredReviewServiceInterface::class,
            \App\Services\Verification\StructuredReviewService::class
        );

        $this->app->singleton(
            \App\Services\Verification\Contracts\VerificationServiceInterface::class,
            \App\Services\Verification\VerificationService::class
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
