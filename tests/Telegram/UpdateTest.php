<?php

declare(strict_types=1);

namespace Tests\Telegram;

use Bot\Telegram\Update;
use Phenogram\Bindings\Factories\ChatFactory;
use Phenogram\Bindings\Factories\MessageFactory;
use Phenogram\Bindings\Factories\UpdateFactory;
use Phenogram\Bindings\Factories\UserFactory;
use Tests\TestCase;

class UpdateTest extends TestCase
{
    public function testUpdateIsCustomClass(): void
    {
        $update = UpdateFactory::make(
            message: MessageFactory::make(
                chat: ChatFactory::make(id: -100123, type: 'supergroup'),
                from: UserFactory::make(id: 42, username: 'alice'),
            ),
        );

        self::assertInstanceOf(Update::class, $update);
    }

    public function testEffectiveChat(): void
    {
        $update = UpdateFactory::make(
            message: MessageFactory::make(
                chat: ChatFactory::make(id: -100123, type: 'supergroup', title: 'Test Group'),
            ),
        );

        assert($update instanceof Update);
        self::assertNotNull($update->effectiveChat);
        self::assertSame(-100123, $update->effectiveChat->id);
    }

    public function testEffectiveUser(): void
    {
        $update = UpdateFactory::make(
            message: MessageFactory::make(
                chat: ChatFactory::make(id: -100123),
                from: UserFactory::make(id: 42, username: 'bob', firstName: 'Bob'),
            ),
        );

        assert($update instanceof Update);
        self::assertNotNull($update->effectiveUser);
        self::assertSame(42, $update->effectiveUser->id);
        self::assertSame('bob', $update->effectiveUser->username);
    }

    public function testEffectiveMessage(): void
    {
        $update = UpdateFactory::make(
            message: MessageFactory::make(
                messageId: 555,
                chat: ChatFactory::make(id: -100123),
                text: 'Hello world!',
            ),
        );

        assert($update instanceof Update);
        self::assertNotNull($update->effectiveMessage);
        self::assertSame(555, $update->effectiveMessage->messageId);
        self::assertSame('Hello world!', $update->effectiveMessage->text);
    }

    public function testEffectiveMessageWithThread(): void
    {
        $update = UpdateFactory::make(
            message: MessageFactory::make(
                chat: ChatFactory::make(id: -100123, type: 'supergroup'),
                messageThreadId: 42,
                text: 'Threaded message',
            ),
        );

        assert($update instanceof Update);
        self::assertSame(42, $update->effectiveMessage->messageThreadId);
    }

    public function testEditedMessageEffective(): void
    {
        $update = UpdateFactory::make(
            editedMessage: MessageFactory::make(
                chat: ChatFactory::make(id: -100999),
                from: UserFactory::make(id: 77, username: 'editor'),
                text: 'Edited text',
            ),
        );

        assert($update instanceof Update);
        self::assertNotNull($update->effectiveMessage);
        self::assertSame('Edited text', $update->effectiveMessage->text);
        self::assertSame(77, $update->effectiveUser->id);
    }

    public function testNullEffectiveWhenNoMessage(): void
    {
        // Update with no message content (just updateId)
        $update = UpdateFactory::make();
        assert($update instanceof Update);
        self::assertNull($update->effectiveMessage);
    }
}
