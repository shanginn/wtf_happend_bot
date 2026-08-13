<?php

declare(strict_types=1);

namespace Tests\Entity;

use Bot\Entity\UpdateRecord\UpdateRecordRepository;
use Cycle\Database\Injection\Fragment;
use ReflectionMethod;
use Tests\TestCase;

final class UpdateRecordRepositorySearchContractTest extends TestCase
{
    public function testSemanticPrefilterUsesOnlyTruthfulDirectMessageFields(): void
    {
        $predicate = (new ReflectionMethod(UpdateRecordRepository::class, 'directTextPredicate'))
            ->invoke(null, 'voice message');
        self::assertInstanceOf(Fragment::class, $predicate);
        $sql = $predicate->getTokens()['fragment'];

        self::assertStringNotContainsString('effective_message', $sql);
        foreach ([
            '{message}',
            '{edited_message}',
            '{channel_post}',
            '{edited_channel_post}',
            '{business_message}',
            '{edited_business_message}',
            '{guest_message}',
        ] as $path) {
            self::assertStringContainsString($path, $sql);
        }
        self::assertStringContainsString("jsonb_typeof(payload->'photo') = 'array'", $sql);
        self::assertStringContainsString("jsonb_array_length(payload->'photo') > 0", $sql);
        self::assertStringContainsString("payload->'delete_chat_photo' = 'true'::jsonb", $sql);
        foreach (['voice message', 'dice roll', 'forwarded story', 'completed a successful payment'] as $phrase) {
            self::assertStringContainsString($phrase, $sql);
        }
    }
}
