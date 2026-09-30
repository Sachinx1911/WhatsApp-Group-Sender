<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Monolog\Handler\NullHandler;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Tests run the queue synchronously; never wait between groups or for the fake sender.
        config([
            'educationhub.sending.delay_seconds' => 0,
            'educationhub.whatsapp.fake.delay_ms' => 0,
            'educationhub.whatsapp.driver' => 'fake',
            // Keep test runs out of the real storage/logs/whatsapp.log.
            'logging.channels.whatsapp' => ['driver' => 'monolog', 'handler' => NullHandler::class],
        ]);
    }
}
