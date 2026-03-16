<?php

declare(strict_types=1);

namespace Tests\Telegram;

use Bot\Telegram\TelegramFileUrlResolverInterface;
use Bot\Telegram\TelegramUpdateViewFactory;
use Phenogram\Bindings\Types\Chat;
use Phenogram\Bindings\Types\Message;
use Phenogram\Bindings\Types\PhotoSize;
use Phenogram\Bindings\Types\Update;
use Phenogram\Bindings\Types\User;
use Tests\TestCase;

class TelegramUpdateViewFactoryTest extends TestCase
{
    public function testBuildsReadableViewForPlainTextMessage(): void
    {
        $view = (new TelegramUpdateViewFactory())->create(new Update(
            updateId: 42,
            message: new Message(
                messageId: 101,
                date: 1_710_000_000,
                chat: new Chat(id: -100123, type: 'supergroup', title: 'Tea Room'),
                from: new User(id: 7, isBot: false, firstName: 'Alice', username: 'alice'),
                text: "hello\nthere",
            ),
        ));

        $this->assertSame('alice', $view->participantReference);
        $this->assertSame([], $view->imageUrls);
        $this->assertStringContainsString('Telegram update: message', $view->text);
        $this->assertStringContainsString('From: Alice (@alice, id 7)', $view->text);
        $this->assertStringContainsString("Text:\nhello\nthere", $view->text);
        $this->assertStringContainsString('What happened:', $view->text);
        $this->assertStringContainsString('- sent a text message', $view->text);
    }

    public function testBuildsMultimodalViewForEditedPhotoMessage(): void
    {
        $resolver = $this->createMock(TelegramFileUrlResolverInterface::class);
        $resolver
            ->expects($this->once())
            ->method('resolve')
            ->with('photo-big')
            ->willReturn('https://cdn.example.test/photo-big.jpg');

        $view = (new TelegramUpdateViewFactory($resolver))->create(new Update(
            updateId: 77,
            editedMessage: new Message(
                messageId: 202,
                date: 1_710_000_000,
                chat: new Chat(id: -100555, type: 'supergroup', title: 'Visual Lab'),
                from: new User(id: 11, isBot: false, firstName: 'Nora'),
                editDate: 1_710_000_321,
                caption: 'Cat tax',
                photo: [
                    new PhotoSize(fileId: 'photo-small', fileUniqueId: 'u1', width: 90, height: 90),
                    new PhotoSize(fileId: 'photo-big', fileUniqueId: 'u2', width: 1280, height: 960),
                ],
            ),
        ));

        $this->assertSame('user_11', $view->participantReference);
        $this->assertSame(['https://cdn.example.test/photo-big.jpg'], $view->imageUrls);
        $this->assertStringContainsString('Telegram update: edited message', $view->text);
        $this->assertStringContainsString('Edited at:', $view->text);
        $this->assertStringContainsString("Caption:\nCat tax", $view->text);
        $this->assertStringContainsString('- edited a photo (1280x960)', $view->text);
    }
}
