<?php

namespace Tests\Feature\Eval;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ScheduleTest extends TestCase
{
    public function test_eval_run_is_scheduled_daily_at_0300(): void
    {
        Artisan::call('list');

        /** @var Schedule $schedule */
        $schedule = app(Schedule::class);

        $events = collect($schedule->events())
            ->filter(fn ($e) => str_contains($e->command ?? '', 'eval:run'));

        $this->assertCount(1, $events, 'expected exactly one eval:run schedule entry');
        $this->assertSame('0 3 * * *', $events->first()->expression);
    }
}
