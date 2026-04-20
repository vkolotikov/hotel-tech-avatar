<?php

namespace Tests\Unit\Sentry;

use App\Support\Sentry\ScrubBeforeSend;
use PHPUnit\Framework\TestCase;
use Sentry\Event;

class BeforeSendScrubberTest extends TestCase
{
    public function test_config_before_send_is_var_export_safe(): void
    {
        $config = require __DIR__ . '/../../../config/sentry.php';
        $exported = var_export($config['before_send'], true);
        // Round-trip: the exported string must be valid PHP that rebuilds an equal callable.
        $rebuilt = eval('return ' . $exported . ';');
        $this->assertSame($config['before_send'], $rebuilt);
        $this->assertTrue(is_callable($config['before_send']));
    }

    public function test_scrubs_top_level_message_key(): void
    {
        $event = Event::createEvent();
        $event->setRequest(['data' => ['message' => 'I have chest pain']]);

        $result = ScrubBeforeSend::handle($event, null);

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

        $result = ScrubBeforeSend::handle($event, null);

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

        $data = ScrubBeforeSend::handle($event, null)->getRequest()['data'];

        foreach (['message', 'prompt', 'response', 'content', 'transcription', 'input', 'output'] as $k) {
            $this->assertSame('[scrubbed]', $data[$k], "key {$k} not scrubbed");
        }
        $this->assertSame('keep me', $data['unrelated']);
    }

    public function test_noop_when_request_data_is_missing(): void
    {
        $event = Event::createEvent();
        $event->setRequest([]);

        $result = ScrubBeforeSend::handle($event, null);

        $this->assertNotNull($result);
    }

    public function test_noop_when_request_data_is_scalar(): void
    {
        $event = Event::createEvent();
        $event->setRequest(['data' => 'raw-body']);

        $result = ScrubBeforeSend::handle($event, null);

        $this->assertSame('raw-body', $result->getRequest()['data']);
    }
}
