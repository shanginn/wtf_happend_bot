<?php

declare(strict_types=1);

namespace Tests\Space\Workflow;

use Bot\Space\Tools\SpaceMemoryBatchEvidence;
use Bot\Space\Workflow\SpaceTelegramAgentMessageMapper;
use Bot\Telegram\InputMessageView;
use Tests\TestCase;

final class SpaceTelegramAgentMessageMapperTest extends TestCase
{
    public function testTelegramViewMapsToPortableSpaceMessage(): void
    {
        $message = SpaceTelegramAgentMessageMapper::map(new InputMessageView(
            text: "From: Alice\n\nText:\nWhat happened?",
            participantReference: 'telegram_user:7',
            imageAttachmentCount: 1,
            updateId: 42,
            memoryEvidenceText: 'What happened?',
        ))->toArray();

        self::assertSame('user', $message['role']);
        self::assertSame('telegram_user:7', $message['name']);
        self::assertStringContainsString(
            'Participant reference: telegram_user:7',
            $message['content'][0]['text'],
        );
        self::assertSame(1, $message['metadata']['imageAttachmentCount']);
        self::assertSame(42, $message['metadata']['telegramUpdateId']);
        self::assertSame('What happened?', $message['metadata']['telegramMemoryEvidence']);
    }

    public function testChannelPostSurvivesTheWholeDurableMemoryEnvelopePath(): void
    {
        $message = SpaceTelegramAgentMessageMapper::map(new InputMessageView(
            text: "From: channel Release News\n\nText:\nWe publish concise release notes.",
            participantReference: 'telegram_chat:-10042',
            imageAttachmentCount: 0,
            updateId: 77,
            memoryEvidenceText: 'We publish concise release notes.',
        ))->toArray();

        self::assertSame('telegram_chat:-10042', $message['name']);
        self::assertSame([[
            'updateId'       => 77,
            'participantKey' => 'telegram_chat:-10042',
            'text'           => 'We publish concise release notes.',
        ]], SpaceMemoryBatchEvidence::fromPendingMessages([$message], 1));
    }
}
