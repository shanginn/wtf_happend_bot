<?php

declare(strict_types=1);

namespace Tests\Space\Dream;

use Bot\Space\Dream\DreamActivities;
use Bot\Space\Dream\DreamCandidate;
use Bot\Space\Dream\SpaceDreamInput;
use Bot\Space\Persistence\SpaceStore;
use Cycle\Database\DatabaseInterface;
use Cycle\Database\StatementInterface;
use Cycle\ORM\ORMInterface;
use Mockery;
use PiPHP\Temporal\Contract\ModelCompletionGatewayInterface;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

final class DreamPersistenceIdentityTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testProposalFingerprintCoversMemoryAuthorityAndRisk(): void
    {
        $input               = self::input();
        $proposalJson        = new ReflectionMethod(DreamActivities::class, 'proposalJson');
        $fingerprint         = new ReflectionMethod(DreamActivities::class, 'fingerprint');
        $digest              = 'sha256:' . str_repeat('e', 64);
        $baseline            = self::candidate();
        $baselineFingerprint = $fingerprint->invoke(
            null,
            $proposalJson->invoke(null, $input, $digest, $baseline),
        );

        foreach ([
            self::candidate(releasePatch: ['memories' => [[
                'operation' => 'forget',
                'memoryId'  => '11111111-1111-4111-a111-111111111111',
            ]]]),
            self::candidate(capabilityDiff: self::authority(['networkHosts' => ['example.com']])),
            self::candidate(riskClass: 'high'),
        ] as $changed) {
            self::assertNotSame(
                $baselineFingerprint,
                $fingerprint->invoke(null, $proposalJson->invoke(null, $input, $digest, $changed)),
            );
        }
    }

    public function testPersistedProposalRejectsAChangedRetryDto(): void
    {
        $input          = self::input();
        $persisted      = self::candidate();
        $incoming       = self::candidate(riskClass: 'high');
        $evidenceDigest = 'sha256:' . str_repeat('e', 64);
        $proposalJson   = (new ReflectionMethod(DreamActivities::class, 'proposalJson'))->invoke(
            null,
            $input,
            $evidenceDigest,
            $persisted,
        );
        $canonicalJson = new ReflectionMethod(DreamActivities::class, 'canonicalJson');
        $database      = Mockery::mock(DatabaseInterface::class);
        $statement     = Mockery::mock(StatementInterface::class);
        $database->shouldReceive('query')->once()->andReturn($statement);
        $statement->shouldReceive('fetch')->once()->andReturn([
            'id'                   => $persisted->proposalId,
            'space_id'             => $persisted->spaceId,
            'dream_run_id'         => (new ReflectionMethod(DreamActivities::class, 'dreamRunId'))->invoke(null, $input),
            'baseline_release_id'  => $persisted->baselineReleaseId,
            'candidate_release_id' => $persisted->candidateReleaseId,
            'hypothesis'           => $persisted->hypothesis,
            'risk_class'           => $persisted->riskClass,
            'proposal_fingerprint' => (new ReflectionMethod(DreamActivities::class, 'fingerprint'))->invoke(
                null,
                $proposalJson,
            ),
            'proposal_json'               => $proposalJson,
            'requested_capabilities_json' => $canonicalJson->invoke(null, $persisted->capabilityDiff),
            'evidence_json'               => $canonicalJson->invoke(null, [
                'schemaVersion'          => 1,
                'digest'                 => $evidenceDigest,
                'baselineMemoryRevision' => $persisted->baselineMemoryRevision,
                'memoryPatchDigest'      => (new ReflectionMethod(DreamActivities::class, 'fingerprint'))->invoke(
                    null,
                    $canonicalJson->invoke(null, []),
                ),
            ]),
            'persisted_candidate_digest' => $persisted->candidateDigest,
        ]);
        $activities = new DreamActivities(
            $database,
            new SpaceStore(Mockery::mock(ORMInterface::class), $database),
            Mockery::mock(ModelCompletionGatewayInterface::class),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('idempotency key names different candidate content');
        (new ReflectionMethod(DreamActivities::class, 'persistedCandidate'))->invoke(
            $activities,
            $input,
            $incoming,
            $evidenceDigest,
        );
    }

    private static function input(): SpaceDreamInput
    {
        return new SpaceDreamInput(
            'spc_0123456789abcdef0123456789abcdef01234567',
            '2026-08-12',
        );
    }

    /** @param array<string, mixed> $releasePatch @param array<string, mixed>|null $capabilityDiff */
    private static function candidate(
        array $releasePatch = ['memories' => []],
        ?array $capabilityDiff = null,
        string $riskClass = 'low',
    ): DreamCandidate {
        return new DreamCandidate(
            proposalId: '00000000-0000-4000-8000-000000000001',
            spaceId: 'spc_0123456789abcdef0123456789abcdef01234567',
            baselineReleaseId: '00000000-0000-4000-8000-000000000002',
            baselineMemoryRevision: 7,
            candidateReleaseId: '00000000-0000-4000-8000-000000000003',
            candidateDigest: 'sha256:' . str_repeat('c', 64),
            releasePatch: $releasePatch,
            capabilityDiff: $capabilityDiff ?? self::authority(),
            hypothesis: 'Improve response quality.',
            riskClass: $riskClass,
        );
    }

    /** @param array<string, list<string>> $overrides @return array<string, list<string>> */
    private static function authority(array $overrides = []): array
    {
        return [
            'networkHosts'        => [],
            'secretRefs'          => [],
            'sideEffects'         => [],
            'stateWrites'         => [],
            'hostApiCapabilities' => [],
            'crossSpaceReads'     => [],
            ...$overrides,
        ];
    }
}
