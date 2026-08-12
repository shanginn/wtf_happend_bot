<?php

declare(strict_types=1);

namespace Tests\Space\Dream;

use Bot\Space\Dream\DreamMemoryPatch;
use Tests\TestCase;

final class DreamMemoryPatchTest extends TestCase
{
    public function testAcceptsBoundedMutationsCitingExactAuthorEvidence(): void
    {
        $memoryId   = '11111111-1111-4111-a111-111111111111';
        $operations = [[
            'operation'          => 'append',
            'participantKey'     => 'telegram_user:7',
            'memory'             => 'Alice prefers dark mode.',
            'quote'              => 'I prefer dark mode',
            'context'            => 'Alice stated a durable UI preference.',
            'evidenceUpdateIds'  => [11],
            'confidencePermille' => 900,
        ], [
            'operation'         => 'update',
            'memoryId'          => $memoryId,
            'memory'            => 'Alice uses Neovim.',
            'quote'             => 'I switched to Neovim',
            'context'           => 'Alice corrected her editor preference.',
            'evidenceUpdateIds' => [12],
        ]];

        self::assertSame([], DreamMemoryPatch::structuralViolations($operations));
        self::assertSame([], DreamMemoryPatch::contextualViolations(
            $operations,
            [
                self::evidence(11, 7, 'I prefer dark mode for every project.'),
                self::evidence(12, 7, 'I switched to Neovim yesterday.'),
            ],
            [self::memory($memoryId, 'Alice uses Vim.')],
        ));
    }

    public function testRejectsUncitedQuoteUnknownParticipantAndUnpinnedTarget(): void
    {
        $operations = [[
            'operation'         => 'append',
            'participantKey'    => 'telegram_user:8',
            'memory'            => 'Bob prefers Rust.',
            'quote'             => 'Bob prefers Rust',
            'context'           => 'A claimed preference.',
            'evidenceUpdateIds' => [11],
        ], [
            'operation'         => 'forget',
            'memoryId'          => '22222222-2222-4222-a222-222222222222',
            'quote'             => 'Forget that',
            'reason'            => 'The participant explicitly corrected the fact.',
            'evidenceUpdateIds' => [99],
        ]];

        $violations = DreamMemoryPatch::contextualViolations(
            $operations,
            [self::evidence(11, 7, 'I prefer dark mode.')],
            [],
        );

        self::assertContains('memory operation 0 quote is absent from its cited evidence', $violations);
        self::assertContains('memory operation 0 participant is absent from its cited evidence', $violations);
        self::assertContains('memory operation 1 cites evidence outside the author set', $violations);
        self::assertContains('memory operation 1 quote is absent from its cited evidence', $violations);
        self::assertContains('memory operation 1 targets memory outside the pinned baseline', $violations);
    }

    public function testRejectsAppendThatCombinesTargetAndQuoteAcrossDifferentAuthors(): void
    {
        $violations = DreamMemoryPatch::contextualViolations([[
            'operation'         => 'append',
            'participantKey'    => 'telegram_user:7',
            'memory'            => 'Alice prefers Rust.',
            'quote'             => 'I prefer Rust',
            'context'           => 'Claimed editor preference.',
            'evidenceUpdateIds' => [11, 12],
        ]], [
            self::evidence(11, 7, 'I am Alice, but said nothing about Rust.'),
            self::evidence(12, 8, 'I prefer Rust.'),
        ], []);

        self::assertNotContains('memory operation 0 quote is absent from its cited evidence', $violations);
        self::assertNotContains('memory operation 0 participant is absent from its cited evidence', $violations);
        self::assertContains(
            'memory operation 0 evidence quote is not authored by its target participant',
            $violations,
        );
    }

    public function testRejectsCrossAuthorUpdateAndForgetOfPinnedMemories(): void
    {
        $firstMemoryId  = '11111111-1111-4111-a111-111111111111';
        $secondMemoryId = '22222222-2222-4222-a222-222222222222';
        $violations     = DreamMemoryPatch::contextualViolations([[
            'operation'         => 'update',
            'memoryId'          => $firstMemoryId,
            'memory'            => 'Alice now prefers Rust.',
            'quote'             => 'Alice now prefers Rust',
            'context'           => 'Bob made the claim.',
            'evidenceUpdateIds' => [12],
        ], [
            'operation'         => 'forget',
            'memoryId'          => $secondMemoryId,
            'quote'             => 'That is no longer true',
            'reason'            => 'Bob requested deletion of Alice memory.',
            'evidenceUpdateIds' => [13],
        ]], [
            self::evidence(12, 8, 'Alice now prefers Rust.'),
            self::evidence(13, 8, 'That is no longer true.'),
        ], [
            self::memory($firstMemoryId, 'Alice uses Vim.'),
            self::memory($secondMemoryId, 'Alice prefers dark mode.'),
        ]);

        self::assertContains(
            'memory operation 0 evidence quote is not authored by its target participant',
            $violations,
        );
        self::assertContains(
            'memory operation 1 evidence quote is not authored by its target participant',
            $violations,
        );
    }

    public function testCanonicalizationTrimsValuesAndSortsEvidenceIds(): void
    {
        self::assertSame([[
            'operation'         => 'forget',
            'memoryId'          => '11111111-1111-4111-a111-111111111111',
            'quote'             => 'No longer true',
            'reason'            => 'Explicit correction',
            'evidenceUpdateIds' => [3, 9],
        ]], DreamMemoryPatch::canonicalize([[
            'operation'         => 'forget',
            'memoryId'          => ' 11111111-1111-4111-a111-111111111111 ',
            'quote'             => ' No longer true ',
            'reason'            => ' Explicit correction ',
            'evidenceUpdateIds' => [9, 3],
        ]]));
    }

    /** @return array<string, mixed> */
    private static function evidence(int $updateId, int $participantId, string $text): array
    {
        return [
            'updateId'  => $updateId,
            'createdAt' => 1_700_000_000 + $updateId,
            'payload'   => [
                'message' => [
                    'authorKind'           => 'user',
                    'participantReference' => 'telegram_user:' . $participantId,
                    'text'                 => $text,
                ],
            ],
        ];
    }

    /**
     * @param string $id
     * @param string $memory
     *
     * @return array{
     *     id: string,
     *     participantKey: string,
     *     participantLabel: string,
     *     memory: string,
     *     quote: string,
     *     context: string,
     *     confidencePermille: ?int
     * }
     */
    private static function memory(string $id, string $memory): array
    {
        return [
            'id'                 => $id,
            'participantKey'     => 'telegram_user:7',
            'participantLabel'   => 'telegram_user:7',
            'memory'             => $memory,
            'quote'              => 'Earlier evidence',
            'context'            => 'Earlier context',
            'confidencePermille' => 700,
        ];
    }
}
