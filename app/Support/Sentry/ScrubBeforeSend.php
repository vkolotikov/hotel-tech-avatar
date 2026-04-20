<?php

declare(strict_types=1);

namespace App\Support\Sentry;

use Sentry\Event;
use Sentry\EventHint;

/**
 * Sentry before_send scrubber. Lives in a class (not a closure in config)
 * so `php artisan config:cache` can serialize the config file.
 */
final class ScrubBeforeSend
{
    private const SCRUB_KEYS = [
        'message', 'prompt', 'response', 'content',
        'transcription', 'input', 'output',
    ];

    public static function handle(Event $event, ?EventHint $hint): ?Event
    {
        $request = $event->getRequest();
        if (is_array($request) && isset($request['data']) && is_array($request['data'])) {
            self::scrub($request['data']);
            $event->setRequest($request);
        }
        return $event;
    }

    private static function scrub(array &$node): void
    {
        foreach ($node as $k => &$v) {
            if (in_array($k, self::SCRUB_KEYS, true)) {
                $v = '[scrubbed]';
                continue;
            }
            if (is_array($v)) {
                self::scrub($v);
            }
        }
    }
}
