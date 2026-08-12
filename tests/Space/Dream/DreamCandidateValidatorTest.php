<?php

declare(strict_types=1);

namespace Tests\Space\Dream;

use Bot\Space\Dream\DreamCandidateValidator;
use Bot\Space\Dream\DreamPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class DreamCandidateValidatorTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function privateDataProvider(): iterable
    {
        yield 'api key' => ['api_key=sk-abcdefghijklmnopqrstuvwxyz123456'];
        yield 'card' => ['card 4111 1111 1111 1111'];
        yield 'email' => ['contact alice@example.com'];
    }

    public function testAcceptsBoundedNoCodePatch(): void
    {
        self::assertSame([], DreamCandidateValidator::violations([
            'prompt'      => 'Be concise and verify current facts.',
            'personality' => ['tone' => 'dry'],
            'skills'      => [[
                'name'        => 'incident_summary',
                'description' => 'Summarize an incident.',
                'body'        => 'Lead with impact, then evidence and actions.',
                'enabled'     => true,
            ]],
            'memories' => [],
        ], new DreamPolicy()));
    }

    public function testRejectsAnyCapsuleFieldInNoCodeDream(): void
    {
        foreach ([[], [['source' => 'process.exit(0)']]] as $capsules) {
            $violations = DreamCandidateValidator::violations(
                ['capsules' => $capsules],
                new DreamPolicy(),
            );
            self::assertContains('release patch contains an unsupported top-level field', $violations);
            self::assertContains('executable capsules are disabled in no-code Dream', $violations);
        }
    }

    public function testCountsPromptPersonalityAndMemoryAgainstNightlyBudget(): void
    {
        $patch = [
            'prompt'      => 'A complete overlay.',
            'personality' => ['tone' => 'brief'],
            'memories'    => [[
                'operation'         => 'append',
                'participantKey'    => 'telegram_user:7',
                'memory'            => 'Alice prefers concise replies.',
                'quote'             => 'Please keep replies short',
                'context'           => 'Alice stated a durable response preference.',
                'evidenceUpdateIds' => [10],
            ]],
        ];

        self::assertSame(3, DreamCandidateValidator::editCount($patch));
        self::assertContains(
            'candidate exceeds the nightly edit budget',
            DreamCandidateValidator::violations($patch, new DreamPolicy(maximumEdits: 2)),
        );
    }

    #[DataProvider('privateDataProvider')]
    public function testRejectsPrivateDataBeforeTheModelGate(string $value): void
    {
        $violations = DreamCandidateValidator::violations([
            'memories' => [[
                'operation'         => 'append',
                'participantKey'    => 'telegram_user:7',
                'memory'            => $value,
                'quote'             => 'supporting quote',
                'context'           => 'context',
                'evidenceUpdateIds' => [10],
            ]],
        ], new DreamPolicy());

        self::assertContains('memory operation 0 contains private or sensitive data', $violations);
    }

    public function testRejectsPrivateDataAcrossAllReleaseFields(): void
    {
        $violations = DreamCandidateValidator::violations([
            'prompt'      => 'Contact alice@example.com.',
            'personality' => ['note' => 'card 4111 1111 1111 1111'],
            'skills'      => [[
                'name'        => 'unsafe_skill',
                'description' => 'Call +1 202 555 0100.',
                'body'        => 'The patient was diagnosed with cancer.',
            ]],
            'memories' => [],
        ], new DreamPolicy());

        self::assertContains('prompt contains private or sensitive data', $violations);
        self::assertContains('personality contains private or sensitive data', $violations);
        self::assertContains('skill 0 contains private or sensitive data', $violations);
    }

    public function testAuthorityRequiresExactEmptySchema(): void
    {
        $authority = [
            'networkHosts'        => [],
            'secretRefs'          => [],
            'sideEffects'         => [],
            'stateWrites'         => [],
            'hostApiCapabilities' => [],
            'crossSpaceReads'     => [],
        ];
        self::assertTrue(DreamCandidateValidator::isSameAuthority($authority));
        self::assertFalse(DreamCandidateValidator::isSameAuthority([]));
        self::assertFalse(DreamCandidateValidator::isSameAuthority([
            ...$authority,
            'unexpected' => [],
        ]));
        self::assertFalse(DreamCandidateValidator::isSameAuthority([
            ...$authority,
            'networkHosts' => ['example.com'],
        ]));
    }

    public function testRejectsUnsafeOrUnboundedHypothesisBeforePersistence(): void
    {
        self::assertContains(
            'hypothesis must be a non-empty string',
            DreamCandidateValidator::hypothesisViolations(['not' => 'a string']),
        );
        self::assertContains(
            'hypothesis exceeds the byte limit',
            DreamCandidateValidator::hypothesisViolations(str_repeat('x', 501)),
        );
        self::assertContains(
            'hypothesis contains private or sensitive data',
            DreamCandidateValidator::hypothesisViolations('Remember пароль: supersecret123'),
        );
    }

    public function testSkillSchemaIsExactAndBounded(): void
    {
        $violations = DreamCandidateValidator::violations([
            'skills' => [[
                'name'        => 'incident_summary',
                'description' => str_repeat('d', 501),
                'body'        => 'Summarize incidents.',
                'enabled'     => 1,
                'extra'       => 'model-controlled ambiguity',
            ]],
        ], new DreamPolicy());

        self::assertContains(
            'skill 0 must contain exactly name, description, body, and enabled',
            $violations,
        );
        self::assertContains('skill 0 description exceeds the byte limit', $violations);
        self::assertContains('skill 0 enabled must be a boolean', $violations);
    }

    public function testResultingSkillSetMustFitRuntimeBudgetsBeforePersistence(): void
    {
        $current = [];
        for ($index = 0; $index < 20; ++$index) {
            $current[] = [
                'name'        => 'skill_' . $index,
                'description' => 'description',
                'body'        => 'body',
                'enabled'     => true,
            ];
        }
        self::assertSame(
            ['candidate resulting release exceeds the skill count limit'],
            DreamCandidateValidator::resultingSkillViolations($current, [[
                'name'        => 'new_skill',
                'description' => 'description',
                'body'        => 'body',
                'enabled'     => true,
            ]]),
        );

        $current = array_map(static fn (int $index): array => [
            'name'        => 'existing_' . $index,
            'description' => 'description',
            'body'        => str_repeat('b', 7_000),
            'enabled'     => true,
        ], range(1, 3));
        $patch = array_map(static fn (int $index): array => [
            'name'        => 'added_' . $index,
            'description' => 'description',
            'body'        => str_repeat('b', 7_500),
            'enabled'     => true,
        ], range(1, 4));
        self::assertSame(
            ['candidate resulting release exceeds the enabled skill byte limit'],
            DreamCandidateValidator::resultingSkillViolations($current, $patch),
        );
    }
}
