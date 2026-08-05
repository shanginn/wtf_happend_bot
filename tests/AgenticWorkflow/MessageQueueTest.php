<?php

declare(strict_types=1);

namespace Tests\AgenticWorkflow;

use Bot\AgenticWorkflow\MessageQueue;
use Tests\TestCase;

final class MessageQueueTest extends TestCase
{
    public function testPrependsFailedBatchAheadOfSignalsReceivedDuringActivity(): void
    {
        $queue = new MessageQueue();
        $queue->push('new-signal');

        $queue->prepend(['failed-update', 'remaining-update']);

        self::assertSame([
            'failed-update',
            'remaining-update',
            'new-signal',
        ], $queue->flush());
        self::assertFalse($queue->has());
    }
}
