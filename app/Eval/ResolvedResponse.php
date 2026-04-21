<?php

declare(strict_types=1);

namespace App\Eval;

final class ResolvedResponse
{
    public function __construct(
        public readonly string $text,
        public readonly bool $red_flag_triggered = false,
        public readonly ?string $red_flag_id = null,
        public readonly ?string $handoff_target = null,
    ) {}
}
