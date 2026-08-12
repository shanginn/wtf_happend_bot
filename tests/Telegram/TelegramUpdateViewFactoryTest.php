<?php

declare(strict_types=1);

namespace Tests\Telegram;

use Bot\Telegram\TelegramUpdateViewFactory;
use Phenogram\Bindings\Types\CallbackQuery;
use Phenogram\Bindings\Types\Chat;
use Phenogram\Bindings\Types\Contact;
use Phenogram\Bindings\Types\Location;
use Phenogram\Bindings\Types\Message;
use Phenogram\Bindings\Types\PhotoSize;
use Phenogram\Bindings\Types\Update;
use Phenogram\Bindings\Types\User;
use Phenogram\Bindings\Types\Venue;
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

        $this->assertSame('telegram_user:7', $view->participantReference);
        $this->assertSame(0, $view->imageAttachmentCount);
        $this->assertSame(42, $view->updateId);
        $this->assertSame("hello\nthere", $view->memoryEvidenceText);
        $this->assertStringContainsString('Telegram update: message', $view->text);
        $this->assertStringContainsString('From: Alice (@alice, id 7)', $view->text);
        $this->assertStringContainsString("Text:\nhello\nthere", $view->text);
        $this->assertStringContainsString('What happened:', $view->text);
        $this->assertStringContainsString('- sent a text message', $view->text);
    }

    public function testBuildsMultimodalViewForEditedPhotoMessage(): void
    {
        $view = (new TelegramUpdateViewFactory())->create(new Update(
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

        $this->assertSame('telegram_user:11', $view->participantReference);
        $this->assertSame(1, $view->imageAttachmentCount);
        $this->assertStringContainsString('Telegram update: edited message', $view->text);
        $this->assertStringContainsString('Edited at:', $view->text);
        $this->assertStringContainsString("Caption:\nCat tax", $view->text);
        $this->assertStringContainsString('- edited a photo (1280x960)', $view->text);
    }

    public function testCallbackViewOmitsInternalQueryIdentity(): void
    {
        $view = (new TelegramUpdateViewFactory())->create(new Update(
            updateId: 88,
            callbackQuery: new CallbackQuery(
                id: 'callback-123',
                from: new User(id: 7, isBot: false, firstName: 'Alice', username: 'alice'),
                chatInstance: 'chat-instance',
                data: 'approve',
            ),
        ));

        self::assertSame('telegram_user:7', $view->participantReference);
        self::assertStringNotContainsString('callback-123', $view->text);
        self::assertStringContainsString('Data: approve', $view->text);
    }

    public function testAnonymousMessageUsesSenderChatIdentity(): void
    {
        $view = (new TelegramUpdateViewFactory())->create(new Update(
            updateId: 89,
            message: new Message(
                messageId: 203,
                date: 1_710_000_000,
                chat: new Chat(id: -100555, type: 'supergroup', title: 'Visual Lab'),
                from: new User(id: 1087968824, isBot: true, firstName: 'GroupAnonymousBot'),
                senderChat: new Chat(id: -100555, type: 'supergroup', title: 'Visual Lab'),
                text: 'anonymous admin message',
            ),
        ));

        self::assertSame('telegram_chat:-100555', $view->participantReference);
        self::assertStringContainsString('From: chat', $view->text);
    }

    public function testBuildsReadableViewForGuestMessage(): void
    {
        $view = (new TelegramUpdateViewFactory())->create(new Update(
            updateId: 90,
            guestMessage: new Message(
                messageId: 204,
                date: 1_710_000_000,
                chat: new Chat(id: -100555, type: 'supergroup', title: 'Visual Lab'),
                from: new User(id: 12, isBot: false, firstName: 'Guest'),
                text: 'hello from a guest',
            ),
        ));

        self::assertSame('telegram_user:12', $view->participantReference);
        self::assertSame(0, $view->imageAttachmentCount);
        self::assertStringContainsString('Telegram update: guest message', $view->text);
        self::assertStringContainsString("Text:\nhello from a guest", $view->text);
    }

    public function testCountsGuestMessageImageAttachment(): void
    {
        $view = (new TelegramUpdateViewFactory())->create(new Update(
            updateId: 91,
            guestMessage: new Message(
                messageId: 205,
                date: 1_710_000_000,
                chat: new Chat(id: -100555, type: 'supergroup', title: 'Visual Lab'),
                from: new User(id: 13, isBot: false, firstName: 'Photo Guest'),
                caption: 'guest photo',
                photo: [
                    new PhotoSize(fileId: 'guest-photo', fileUniqueId: 'guest-u1', width: 640, height: 480),
                ],
            ),
        ));

        self::assertSame('telegram_user:13', $view->participantReference);
        self::assertSame(1, $view->imageAttachmentCount);
        self::assertStringContainsString("Caption:\nguest photo", $view->text);
    }

    public function testReplyPreviewPrefersAnonymousSenderChatIdentity(): void
    {
        $view = (new TelegramUpdateViewFactory())->create(new Update(
            updateId: 92,
            message: new Message(
                messageId: 206,
                date: 1_710_000_000,
                chat: new Chat(id: -100555, type: 'supergroup', title: 'Visual Lab'),
                from: new User(id: 14, isBot: false, firstName: 'Alice'),
                text: 'replying',
                replyToMessage: new Message(
                    messageId: 190,
                    date: 1_709_999_900,
                    chat: new Chat(id: -100555, type: 'supergroup', title: 'Visual Lab'),
                    from: new User(
                        id: 1_087_968_824,
                        isBot: true,
                        firstName: 'GroupAnonymousBot',
                    ),
                    senderChat: new Chat(id: -100777, type: 'channel', title: 'Announcements'),
                    text: 'anonymous source',
                ),
            ),
        ));

        self::assertStringContainsString(
            'Reply to: #190 by chat channel "Announcements" (id -100777)',
            $view->text,
        );
        self::assertStringNotContainsString('GroupAnonymousBot', $view->text);
    }

    public function testStructuredContactAndLocationDetailsAreWithheld(): void
    {
        $factory = new TelegramUpdateViewFactory();
        $chat    = new Chat(id: -100555, type: 'supergroup', title: 'Visual Lab');
        $sender  = new User(id: 14, isBot: false, firstName: 'Alice');

        $contactView = $factory->create(new Update(
            updateId: 93,
            message: new Message(
                messageId: 207,
                date: 1_710_000_000,
                chat: $chat,
                from: $sender,
                contact: new Contact(
                    phoneNumber: '+1-555-0100',
                    firstName: 'Private',
                    lastName: 'Person',
                ),
            ),
        ));
        self::assertStringContainsString(
            'shared a contact (details withheld from model context)',
            $contactView->text,
        );
        self::assertStringNotContainsString('+1-555-0100', $contactView->text);
        self::assertStringNotContainsString('Private Person', $contactView->text);

        $locationView = $factory->create(new Update(
            updateId: 94,
            message: new Message(
                messageId: 208,
                date: 1_710_000_000,
                chat: $chat,
                from: $sender,
                location: new Location(
                    latitude: 56.83801,
                    longitude: 60.59747,
                    horizontalAccuracy: 2.5,
                ),
            ),
        ));
        self::assertStringContainsString(
            'shared a location (details withheld from model context)',
            $locationView->text,
        );
        self::assertStringNotContainsString('56.83801', $locationView->text);
        self::assertStringNotContainsString('60.59747', $locationView->text);
        self::assertStringNotContainsString('2.5m', $locationView->text);

        $venueView = $factory->create(new Update(
            updateId: 95,
            message: new Message(
                messageId: 209,
                date: 1_710_000_000,
                chat: $chat,
                from: $sender,
                venue: new Venue(
                    location: new Location(latitude: 55.75583, longitude: 37.61730),
                    title: 'Private Venue',
                    address: 'Secret Street 1',
                ),
            ),
        ));
        self::assertStringContainsString(
            'shared a venue (details withheld from model context)',
            $venueView->text,
        );
        self::assertStringNotContainsString('Private Venue', $venueView->text);
        self::assertStringNotContainsString('Secret Street 1', $venueView->text);
        self::assertStringNotContainsString('55.75583', $venueView->text);
        self::assertStringNotContainsString('37.6173', $venueView->text);
    }
}
