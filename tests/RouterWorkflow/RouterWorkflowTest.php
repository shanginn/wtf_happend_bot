<?php

declare(strict_types=1);

namespace Tests\RouterWorkflow;

use Bot\RouterWorkflow\RouterWorkflow;
use Bot\RouterWorkflow\MessageQueue;
use Tests\TestCase;
use ReflectionMethod;
use ReflectionClass;
use Shanginn\Openai\ChatCompletion\Message\UserMessage;
use Shanginn\Openai\ChatCompletion\Message\User\TextContentPart;
use Shanginn\Openai\ChatCompletion\Message\User\ImageContentPart;

class RouterWorkflowTest extends TestCase
{
    public function testBuildContextMessagePlainText(): void
    {
        $workflow = (new ReflectionClass(RouterWorkflow::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(RouterWorkflow::class, 'buildContextMessage');

        $items = [
            ['date' => '2026-03-10 12:00', 'user' => '@alice Alice', 'text' => 'Hello everyone', 'imageUrl' => null],
            ['date' => '2026-03-10 12:01', 'user' => '@bob Bob', 'text' => 'Hey Alice!', 'imageUrl' => null],
        ];

        /** @var UserMessage $message */
        $message = $method->invoke($workflow, $items);

        self::assertInstanceOf(UserMessage::class, $message);
        self::assertIsString($message->content);
        self::assertStringContainsString('@alice Alice', $message->content);
        self::assertStringContainsString('Hello everyone', $message->content);
        self::assertStringContainsString('@bob Bob', $message->content);
    }

    public function testBuildContextMessageWithImages(): void
    {
        $workflow = (new ReflectionClass(RouterWorkflow::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(RouterWorkflow::class, 'buildContextMessage');

        $items = [
            ['date' => '2026-03-10 12:00', 'user' => '@alice Alice', 'text' => 'Check this out', 'imageUrl' => 'https://example.com/img.jpg'],
            ['date' => '2026-03-10 12:01', 'user' => '@bob Bob', 'text' => 'Nice!', 'imageUrl' => null],
        ];

        /** @var UserMessage $message */
        $message = $method->invoke($workflow, $items);

        self::assertInstanceOf(UserMessage::class, $message);
        self::assertIsArray($message->content);

        $hasImage = false;
        foreach ($message->content as $part) {
            if ($part instanceof ImageContentPart) {
                $hasImage = true;
                break;
            }
        }
        self::assertTrue($hasImage, 'Context message should contain an image part');
    }

    public function testUpdateChatBufferDeduplication(): void
    {
        $workflow = (new ReflectionClass(RouterWorkflow::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(RouterWorkflow::class, 'updateChatBuffer');
        $property = (new ReflectionClass(RouterWorkflow::class))->getProperty('chatBuffer');

        $items = [
            ['date' => '2026-03-10 12:00', 'user' => '@alice', 'text' => 'Hello', 'imageUrl' => null],
        ];

        // Call twice with the same data
        $method->invoke($workflow, $items);
        $method->invoke($workflow, $items);

        $buffer = $property->getValue($workflow);
        self::assertCount(1, $buffer, 'Duplicate items should not be added');
    }

    public function testUpdateChatBufferTrimming(): void
    {
        $workflow = (new ReflectionClass(RouterWorkflow::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(RouterWorkflow::class, 'updateChatBuffer');
        $property = (new ReflectionClass(RouterWorkflow::class))->getProperty('chatBuffer');

        // Add more than MAX_CHAT_BUFFER items
        for ($i = 0; $i < 60; $i++) {
            $items = [
                ['date' => "2026-03-10 12:{$i}", 'user' => '@user', 'text' => "Message {$i}", 'imageUrl' => null],
            ];
            $method->invoke($workflow, $items);
        }

        $buffer = $property->getValue($workflow);
        self::assertCount(50, $buffer, 'Buffer should be trimmed to MAX_CHAT_BUFFER');

        // The oldest messages should have been removed
        $firstMessage = $buffer[0];
        self::assertSame('Message 10', $firstMessage['text']);
    }

    public function testAppendToHistoryTrimming(): void
    {
        $workflow = (new ReflectionClass(RouterWorkflow::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(RouterWorkflow::class, 'appendToHistory');
        $property = (new ReflectionClass(RouterWorkflow::class))->getProperty('history');

        // Add more than MAX_HISTORY messages
        for ($i = 0; $i < 35; $i++) {
            $method->invoke($workflow, [new UserMessage("Message {$i}")]);
        }

        $history = $property->getValue($workflow);
        self::assertCount(30, $history, 'History should be trimmed to MAX_HISTORY');
    }

    public function testMessageQueueFlush(): void
    {
        $queue = new MessageQueue();

        self::assertFalse($queue->has());
        self::assertSame(0, $queue->count());

        $queue->push('a');
        $queue->push('b');

        self::assertTrue($queue->has());
        self::assertSame(2, $queue->count());

        $items = $queue->flush();
        self::assertSame(['a', 'b'], $items);
        self::assertFalse($queue->has());
    }
}
