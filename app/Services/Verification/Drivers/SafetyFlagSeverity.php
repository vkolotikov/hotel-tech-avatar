<?php

namespace App\Services\Verification\Drivers;

enum SafetyFlagSeverity: string {
    case HARD = 'hard';
    case SOFT = 'soft';
}
