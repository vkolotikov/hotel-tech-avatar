<?php

declare(strict_types=1);

namespace App\Services\Knowledge\Drivers;

interface DriverInterface
{
    /**
     * Fetch and normalize knowledge from the API.
     * @param array<string, mixed> $config Driver-specific configuration
     * @return array<RetrievedChunk>
     */
    public function fetch(array $config): array;

    /**
     * Human-readable name of this driver (e.g., "USDA FoodData").
     */
    public function name(): string;
}
