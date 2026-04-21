<?php

namespace App\Services\Verification\Drivers;

enum SafetySeverity: string {
    case HARD = 'hard';
    case SOFT = 'soft';
}

final class SafetyFlag
{
    public function __construct(
        public readonly SafetySeverity $severity,
        public readonly string $matched_pattern,
        public readonly string $suggested_action,
        public readonly string $matched_text,
    ) {}
}
