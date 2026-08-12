<?php

declare(strict_types=1);

namespace Tests\Space\Dream;

use Bot\Entity\SpaceRelease;
use Bot\Space\Dream\DreamActivities;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

final class DreamCandidateSeedTest extends TestCase
{
    public function testRefusesCapsulesInBaselineOrPatch(): void
    {
        foreach ([
            [self::baseline(manifestJson: '{"capsules":[{"name":"bad"}]}'), []],
            [self::baseline(), ['capsules' => [['name' => 'bad']]]],
        ] as [$baseline, $patch]) {
            try {
                self::candidateSeed()->invoke(null, $baseline, $patch, []);
                self::fail('Expected no-code candidate construction to fail closed.');
            } catch (RuntimeException $error) {
                self::assertSame('Executable capsules are disabled in no-code Dream.', $error->getMessage());
            }
        }
    }

    public function testCandidateForcesNoCodeManifestAndStableSkillDigest(): void
    {
        $seed = self::candidateSeed()->invoke(null, self::baseline(), [
            'skills' => [[
                'name'        => 'concise',
                'description' => '  Keep it short.  ',
                'body'        => "\n Be concise. \n",
                'enabled'     => true,
            ]],
        ], []);
        $manifest = json_decode($seed->manifestJson, true, flags: \JSON_THROW_ON_ERROR);

        self::assertSame([], $manifest['capsules']);
        self::assertArrayNotHasKey('capsuleRuntimeImageBuildId', $manifest);
        self::assertNull($seed->artifactDigest);
        self::assertSame('sha256:' . hash('sha256', json_encode([[
            'name'        => 'concise',
            'description' => 'Keep it short.',
            'body'        => 'Be concise.',
            'enabled'     => true,
        ]], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)), $manifest['skillsDigest']);
    }

    public function testSemanticNoopDetectionRejectsIdenticalReleaseFields(): void
    {
        $method = new ReflectionMethod(DreamActivities::class, 'patchHasEffectiveChange');
        $skills = [[
            'name'        => 'concise',
            'description' => 'Keep it short.',
            'body'        => 'Be concise.',
            'enabled'     => true,
        ]];
        self::assertFalse($method->invoke(null, self::baseline(prompt: 'same'), [
            'prompt'      => 'same',
            'personality' => [],
            'skills'      => $skills,
            'memories'    => [],
        ], $skills));
        self::assertTrue($method->invoke(null, self::baseline(prompt: 'same'), [
            'prompt'   => 'better',
            'memories' => [],
        ], $skills));
    }

    private static function candidateSeed(): ReflectionMethod
    {
        return new ReflectionMethod(DreamActivities::class, 'candidateSeed');
    }

    private static function baseline(
        string $prompt = '',
        string $manifestJson = '{"capsules":[]}',
    ): SpaceRelease {
        return new SpaceRelease(
            id: 'baseline-release',
            spaceId: 'spc_0123456789abcdef0123456789abcdef01234567',
            parentReleaseId: null,
            sourceProposalId: null,
            sequence: 1,
            status: SpaceRelease::STATUS_ACTIVE,
            releaseDigest: 'sha256:' . str_repeat('a', 64),
            model: 'test/model',
            prompt: $prompt,
            manifestJson: $manifestJson,
        );
    }
}
