<?php

namespace Tests\Unit\Sentry;

use PHPUnit\Framework\TestCase;
use Sentry\Event;
use Sentry\EventHint;

class BeforeSendScrubberTest extends TestCase
{
    private \Closure $scrubber;

    protected function setUp(): void
    {
        parent::setUp();
        $config = require __DIR__ . '/../../../config/sentry.php';
        $this->scrubber = $config['before_send'];
    }

    public function test_scrubs_top_level_message_key(): void
    {
        $event = Event::createEvent();
        $event->setRequest(['data' => ['message' => 'I have chest pain']]);

        $result = ($this->scrubber)($event, null);

        $this->assertSame('[scrubbed]', $result->getRequest()['data']['message']);
    }

    public function test_scrubs_nested_message_key(): void
    {
        $event = Event::createEvent();
        $event->setRequest([
            'data' => [
                'conversation' => [
                    'message' => 'I have chest pain',
                    'attachments' => [
                        ['content' => 'user upload bytes'],
                    ],
                ],
            ],
        ]);

        $result = ($this->scrubber)($event, null);

        $data = $result->getRequest()['data'];
        $this->assertSame('[scrubbed]', $data['conversation']['message']);
        $this->assertSame('[scrubbed]', $data['conversation']['attachments'][0]['content']);
    }

    public function test_scrubs_all_seven_keys(): void
    {
        $event = Event::createEvent();
        $event->setRequest([
            'data' => [
                'message' => 'x', 'prompt' => 'x', 'response' => 'x',
                'content' => 'x', 'transcription' => 'x',
                'input' => 'x', 'output' => 'x',
                'unrelated' => 'keep me',
            ],
        ]);

        $data = ($this->scrubber)($event, null)->getRequest()['data'];

        foreach (['message', 'prompt', 'response', 'content', 'transcription', 'input', 'output'] as $k) {
            $this->assertSame('[scrubbed]', $data[$k], "key {$k} not scrubbed");
        }
        $this->assertSame('keep me', $data['unrelated']);
    }

    public function test_noop_when_request_data_is_missing(): void
    {
        $event = Event::createEvent();
        $event->setRequest([]);

        $result = ($this->scrubber)($event, null);

        $this->assertNotNull($result);
    }

    public function test_noop_when_request_data_is_scalar(): void
    {
        $event = Event::createEvent();
        $event->setRequest(['data' => 'raw-body']);

        $result = ($this->scrubber)($event, null);

        $this->assertSame('raw-body', $result->getRequest()['data']);
    }
}
