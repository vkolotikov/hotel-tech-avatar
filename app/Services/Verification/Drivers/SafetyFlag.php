<?php

namespace App\Services\Verification\Drivers;

enum SafetyFlagSeverity: string {
    case HARD = 'hard';
    case SOFT = 'soft';
}

final class SafetyFlag
{
    public function __construct(
        public readonly SafetyFlagSeverity $severity,
        public readonly string $matched_pattern,
        public readonly string $suggested_action,
        public readonly string $matched_text,
    ) {}
}
