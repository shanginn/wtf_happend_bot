<?php

declare(strict_types=1);

namespace Tests\Space\Tools;

use Bot\Space\Tools\SpaceMemoryBatchEvidence;
use Tests\TestCase;

final class SpaceMemoryBatchEvidenceTest extends TestCase
{
    public function testExtractsOnlyAuthoredEvidenceFromThePendingBatch(): void
    {
        $evidence = SpaceMemoryBatchEvidence::fromPendingMessages([
            self::message(1, 6, 'old evidence'),
            self::message(2, 7, 'I prefer concise replies.'),
            [
                'role'     => 'user',
                'metadata' => [
                    'telegramUpdateId'    => 3,
                    'telegramParticipant' => 'telegram_user:8',
                ],
            ],
        ], 2);

        self::assertSame([[
            'updateId'       => 2,
            'participantKey' => 'telegram_user:7',
            'text'           => 'I prefer concise replies.',
        ]], $evidence);
    }

    public function testAcceptsHostAuthoredChannelEvidence(): void
    {
        self::assertSame([[
            'updateId'       => 4,
            'participantKey' => 'telegram_chat:-10042',
            'text'           => 'Channel prefers release notes.',
        ]], SpaceMemoryBatchEvidence::fromPendingMessages([[
            'role'     => 'user',
            'metadata' => [
                'telegramUpdateId'       => 4,
                'telegramParticipant'    => 'telegram_chat:-10042',
                'telegramMemoryEvidence' => 'Channel prefers release notes.',
            ],
        ]], 1));
    }

    /** @return array<string, mixed> */
    private static function message(int $updateId, int $actorId, string $text): array
    {
        return [
            'role'     => 'user',
            'metadata' => [
                'telegramUpdateId'       => $updateId,
                'telegramParticipant'    => 'telegram_user:' . $actorId,
                'telegramMemoryEvidence' => $text,
            ],
        ];
    }
}
