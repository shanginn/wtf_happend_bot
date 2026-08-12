<?php

declare(strict_types=1);

namespace Tests\Space\Dream;

use Bot\Space\Dream\DreamActivities;
use Bot\Space\Dream\DreamCandidate;
use Bot\Space\Dream\DreamEvidence;
use Bot\Space\Dream\DreamPolicy;
use Bot\Space\Dream\SpaceDreamInput;
use Bot\Space\Persistence\SpaceStore;
use Cycle\Database\DatabaseInterface;
use Cycle\Database\Query\InsertQuery;
use Cycle\Database\Query\UpdateQuery;
use Cycle\Database\StatementInterface;
use Cycle\Database\TableInterface;
use Cycle\ORM\ORMInterface;
use Mockery;
use PiPHP\Temporal\Contract\ModelCompletionGatewayInterface;
use PiPHP\Temporal\DTO\ModelActivityInput;
use PiPHP\Temporal\DTO\ModelActivityResult;
use ReflectionMethod;
use Tests\TestCase;

final class DreamReplayAndRollbackPolicyTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testUnsafeCandidateFailsRegardlessOfScore(): void
    {
        $failures = self::method('candidateReplayFailures')->invoke(null, [
            'caseCount'            => 3,
            'candidateWinPermille' => 1000,
            'candidateScoreMargin' => 500,
            'regressionCases'      => 0,
            'candidateUnsafeCases' => 1,
        ], new DreamPolicy(minimumReplayCases: 2));

        self::assertContains('candidate produced an unsafe held-out replay response', $failures);
    }

    public function testPersistedHighRiskCandidateFailsTheHostEvaluator(): void
    {
        $spaceId  = 'spc_0123456789abcdef0123456789abcdef01234567';
        $evidence = new DreamEvidence(
            spaceId: $spaceId,
            baselineReleaseId: 'release-1',
            baselineReleaseDigest: 'sha256:' . str_repeat('a', 64),
            items: [],
            baselineMetrics: [],
            evidenceDigest: 'sha256:' . str_repeat('b', 64),
        );
        $candidate = new DreamCandidate(
            proposalId: 'proposal-1',
            spaceId: $spaceId,
            baselineReleaseId: 'release-1',
            baselineMemoryRevision: 0,
            candidateReleaseId: 'release-2',
            candidateDigest: 'sha256:' . str_repeat('c', 64),
            releasePatch: ['prompt' => 'changed', 'memories' => []],
            capabilityDiff: [
                'networkHosts'        => [],
                'secretRefs'          => [],
                'sideEffects'         => [],
                'stateWrites'         => [],
                'hostApiCapabilities' => [],
                'crossSpaceReads'     => [],
            ],
            hypothesis: 'Risky change.',
            riskClass: 'high',
        );

        self::assertContains(
            'high-risk candidates are disabled in autonomous Dream',
            self::method('immutableCandidateFailures')->invoke(
                null,
                $candidate,
                $evidence,
                new DreamPolicy(),
            ),
        );
    }

    public function testMemoryOnlyPatchIsReplayableAndPurelySimulated(): void
    {
        $baseline = [[
            'id'                 => '11111111-1111-4111-a111-111111111111',
            'participantKey'     => 'telegram_user:7',
            'participantLabel'   => 'telegram_user:7',
            'memory'             => 'Uses Vim.',
            'quote'              => 'I use Vim',
            'context'            => 'Editor preference.',
            'confidencePermille' => 700,
        ]];
        $patch = ['memories' => [[
            'operation'         => 'update',
            'memoryId'          => $baseline[0]['id'],
            'memory'            => 'Uses Neovim.',
            'quote'             => 'I switched to Neovim',
            'context'           => 'Corrected editor preference.',
            'evidenceUpdateIds' => [10],
        ]]];

        self::assertTrue(self::method('hasReplayableChange')->invoke(null, $patch));
        $simulated = self::method('simulateMemoryPatch')->invoke(
            null,
            $baseline,
            $patch['memories'],
            'proposal-1',
        );
        self::assertSame('Uses Neovim.', $simulated[0]['memory']);
        self::assertSame('Uses Vim.', $baseline[0]['memory']);
    }

    public function testBlindReplayGeneratesBothResponsesWithoutToolsAndJudgesSafety(): void
    {
        $input = new SpaceDreamInput(
            'spc_0123456789abcdef0123456789abcdef01234567',
            '2026-08-12',
            policy: new DreamPolicy(minimumReplayCases: 1, maximumReplayCases: 1),
        );
        $candidateIsA = ((int) hexdec(substr(hash(
            'sha256',
            implode("\0", [$input->spaceId, $input->dreamDate, 'memory-only', 'case-42']),
        ), 0, 2))) % 2 === 0;
        $judge = [
            'winner' => $candidateIsA ? 'A' : 'B',
            'scoreA' => $candidateIsA ? 900 : 500,
            'scoreB' => $candidateIsA ? 500 : 900,
            'safeA'  => !$candidateIsA,
            'safeB'  => $candidateIsA,
            'reason' => 'candidate is more useful but unsafe',
        ];
        $results = [
            self::modelResult(['response' => 'baseline reply']),
            self::modelResult(['response' => 'candidate reply']),
            self::modelResult($judge),
        ];
        $calls  = [];
        $models = Mockery::mock(ModelCompletionGatewayInterface::class);
        $models->shouldReceive('complete')->times(3)->andReturnUsing(
            static function (ModelActivityInput $request) use (&$results, &$calls): ModelActivityResult {
                $calls[] = $request;

                return array_shift($results);
            },
        );
        $database   = Mockery::mock(DatabaseInterface::class);
        $activities = new DreamActivities(
            $database,
            new SpaceStore(Mockery::mock(ORMInterface::class), $database),
            $models,
            'test/model',
        );
        $evidence = [[
            'updateId'  => 42,
            'createdAt' => 1_700_000_042,
            'payload'   => ['message' => [
                'authorKind'           => 'user',
                'participantReference' => 'telegram_user:7',
                'text'                 => 'How should I configure this?',
            ]]],
        ];

        $metrics = self::method('runBlindReplay')->invoke(
            $activities,
            $input,
            'memory-only',
            $evidence,
            ['memories' => [['memory' => 'baseline fact']]],
            ['memories' => [['memory' => 'candidate fact']]],
        );

        self::assertSame(1, $metrics['caseCount']);
        self::assertSame(1, $metrics['candidateWins']);
        self::assertSame(0, $metrics['baselineUnsafeCases']);
        self::assertSame(1, $metrics['candidateUnsafeCases']);
        self::assertCount(3, $calls);
        foreach ($calls as $call) {
            self::assertSame([], $call->tools);
            self::assertStringStartsWith('space-dream:', $call->idempotencyKey);
        }
        self::assertStringContainsString('baseline fact', json_encode($calls[0]->messages, \JSON_THROW_ON_ERROR));
        self::assertStringContainsString('candidate fact', json_encode($calls[1]->messages, \JSON_THROW_ON_ERROR));
    }

    public function testUnrelatedLiveRevisionDoesNotBlockExactDreamAppendCompensation(): void
    {
        $database            = Mockery::mock(DatabaseInterface::class);
        $appliedStatement    = Mockery::mock(StatementInterface::class);
        $existingStatement   = Mockery::mock(StatementInterface::class);
        $descendantStatement = Mockery::mock(StatementInterface::class);
        $candidate           = self::memoryCandidate();
        $database->shouldReceive('query')->once()->ordered()->andReturn($appliedStatement);
        $appliedStatement->shouldReceive('fetch')->once()->andReturn(self::appliedMemoryRow($candidate));
        $database->shouldReceive('query')->once()->ordered()->andReturn($existingStatement);
        $existingStatement->shouldReceive('fetch')->once()->andReturn(false);
        $database->shouldReceive('query')->once()->ordered()->andReturn($descendantStatement);
        $descendantStatement->shouldReceive('fetchAll')->once()->andReturn([]);
        $database->shouldReceive('execute')->once()->ordered()->withArgs(
            static function (string $sql, array $values): bool {
                return str_contains($sql, 'INSERT INTO space_memory_versions')
                    && $values[2] === 13
                    && $values[8] === 'forgotten'
                    && $values[9] === 'space-dream:proposal-1:rollback-memory:0:append'
                    && $values[10] === 'applied-memory-1';
            },
        );
        $database->shouldReceive('execute')->once()->ordered()->withArgs(
            static fn (string $sql, array $values): bool => str_contains(
                $sql,
                'UPDATE space_memory_versions',
            ) && $values === ['applied-memory-1', 'spc_0123456789abcdef0123456789abcdef01234567'],
        );
        $database->shouldReceive('execute')->once()->ordered()->withArgs(
            static fn (string $sql, array $values): bool => str_contains($sql, 'UPDATE agent_spaces')
                && $values[0] === 13,
        );
        $activities = new DreamActivities(
            $database,
            new SpaceStore(Mockery::mock(ORMInterface::class), $database),
            Mockery::mock(ModelCompletionGatewayInterface::class),
        );

        $report = self::method('compensateDreamMemories')->invoke(
            $activities,
            $database,
            new SpaceDreamInput($candidate->spaceId, '2026-08-12'),
            $candidate,
            11,
            12,
        );

        self::assertSame(13, $report['revisionAfter']);
        self::assertTrue($report['fullyCompensated']);
        self::assertSame(['applied-memory-1'], $report['compensatedAppliedMemoryIds']);
        self::assertSame([], $report['skippedAppliedMemoryIds']);
        self::assertMatchesRegularExpression('/\Asha256:[a-f0-9]{64}\z/D', $report['digest']);
        self::assertTrue(self::method('memoryCompensationReportIsValid')->invoke(null, $report));
    }

    public function testDreamAppendWithLiveDescendantIsSkippedWithoutTouchingLiveMemory(): void
    {
        $database            = Mockery::mock(DatabaseInterface::class);
        $appliedStatement    = Mockery::mock(StatementInterface::class);
        $existingStatement   = Mockery::mock(StatementInterface::class);
        $descendantStatement = Mockery::mock(StatementInterface::class);
        $candidate           = self::memoryCandidate();
        $database->shouldReceive('query')->once()->ordered()->andReturn($appliedStatement);
        $appliedStatement->shouldReceive('fetch')->once()->andReturn(self::appliedMemoryRow(
            $candidate,
            status: 'superseded',
        ));
        $database->shouldReceive('query')->once()->ordered()->andReturn($existingStatement);
        $existingStatement->shouldReceive('fetch')->once()->andReturn(false);
        $database->shouldReceive('query')->once()->ordered()->andReturn($descendantStatement);
        $descendantStatement->shouldReceive('fetchAll')->once()->andReturn([['id' => 'live-memory-2']]);
        $database->shouldNotReceive('execute');
        $activities = new DreamActivities(
            $database,
            new SpaceStore(Mockery::mock(ORMInterface::class), $database),
            Mockery::mock(ModelCompletionGatewayInterface::class),
        );

        $report = self::method('compensateDreamMemories')->invoke(
            $activities,
            $database,
            new SpaceDreamInput($candidate->spaceId, '2026-08-12'),
            $candidate,
            11,
            12,
        );

        self::assertSame(12, $report['revisionAfter']);
        self::assertFalse($report['fullyCompensated']);
        self::assertSame(['applied-memory-1'], $report['skippedAppliedMemoryIds']);
        self::assertSame('dream-applied-version-has-descendant', $report['skips'][0]['reason']);
        self::assertSame(['live-memory-2'], $report['skips'][0]['relatedMemoryIds']);
        self::assertTrue(self::method('memoryCompensationReportIsValid')->invoke(null, $report));
    }

    public function testSpoofedDreamIdempotencyKeyCannotAuthorizeMemoryCompensation(): void
    {
        $database               = Mockery::mock(DatabaseInterface::class);
        $statement              = Mockery::mock(StatementInterface::class);
        $candidate              = self::memoryCandidate();
        $row                    = self::appliedMemoryRow($candidate);
        $row['provenance_json'] = '{"source":"live-tool"}';
        $database->shouldReceive('query')->once()->andReturn($statement);
        $statement->shouldReceive('fetch')->once()->andReturn($row);
        $database->shouldNotReceive('execute');
        $activities = new DreamActivities(
            $database,
            new SpaceStore(Mockery::mock(ORMInterface::class), $database),
            Mockery::mock(ModelCompletionGatewayInterface::class),
        );

        $report = self::method('compensateDreamMemories')->invoke(
            $activities,
            $database,
            new SpaceDreamInput($candidate->spaceId, '2026-08-12'),
            $candidate,
            11,
            12,
        );

        self::assertFalse($report['fullyCompensated']);
        self::assertSame('dream-applied-version-provenance-mismatch', $report['skips'][0]['reason']);
        self::assertSame(['applied-memory-1'], $report['skippedAppliedMemoryIds']);
    }

    public function testForgetRollbackRestoresBaselineWithoutSupersedingDreamTombstone(): void
    {
        $candidate           = self::forgetMemoryCandidate();
        $database            = Mockery::mock(DatabaseInterface::class);
        $appliedStatement    = Mockery::mock(StatementInterface::class);
        $existingStatement   = Mockery::mock(StatementInterface::class);
        $descendantStatement = Mockery::mock(StatementInterface::class);
        $baselineStatement   = Mockery::mock(StatementInterface::class);
        $duplicatesStatement = Mockery::mock(StatementInterface::class);
        $database->shouldReceive('query')->once()->ordered()->andReturn($appliedStatement);
        $appliedStatement->shouldReceive('fetch')->once()->andReturn(self::appliedMemoryRow(
            $candidate,
            status: 'forgotten',
        ));
        $database->shouldReceive('query')->once()->ordered()->andReturn($existingStatement);
        $existingStatement->shouldReceive('fetch')->once()->andReturn(false);
        $database->shouldReceive('query')->once()->ordered()->andReturn($descendantStatement);
        $descendantStatement->shouldReceive('fetchAll')->once()->andReturn([]);
        $database->shouldReceive('query')->once()->ordered()->andReturn($baselineStatement);
        $baselineStatement->shouldReceive('fetch')->once()->andReturn(self::baselineMemoryRow($candidate));
        $database->shouldReceive('query')->once()->ordered()->andReturn($duplicatesStatement);
        $duplicatesStatement->shouldReceive('fetchAll')->once()->andReturn([]);
        $database->shouldReceive('execute')->once()->ordered()->withArgs(
            static function (string $sql, array $values): bool {
                return str_contains($sql, 'INSERT INTO space_memory_versions')
                    && $values[2] === 13
                    && $values[8] === 'active'
                    && $values[9] === 'space-dream:proposal-1:rollback-memory:0:forget'
                    && $values[10] === 'applied-memory-1';
            },
        );
        $database->shouldReceive('execute')->once()->ordered()->withArgs(
            static fn (string $sql, array $values): bool => str_contains($sql, 'UPDATE agent_spaces')
                && !str_contains($sql, 'UPDATE space_memory_versions')
                && $values[0] === 13,
        );
        $activities = new DreamActivities(
            $database,
            new SpaceStore(Mockery::mock(ORMInterface::class), $database),
            Mockery::mock(ModelCompletionGatewayInterface::class),
        );

        $report = self::method('compensateDreamMemories')->invoke(
            $activities,
            $database,
            new SpaceDreamInput($candidate->spaceId, '2026-08-12'),
            $candidate,
            11,
            12,
        );

        self::assertSame(13, $report['revisionAfter']);
        self::assertTrue($report['fullyCompensated']);
        self::assertSame(['applied-memory-1'], $report['compensatedAppliedMemoryIds']);
    }

    public function testUpdateRollbackRestoresBaselineWhilePreservingUnrelatedRevision(): void
    {
        $candidate           = self::updateMemoryCandidate();
        $database            = Mockery::mock(DatabaseInterface::class);
        $appliedStatement    = Mockery::mock(StatementInterface::class);
        $existingStatement   = Mockery::mock(StatementInterface::class);
        $descendantStatement = Mockery::mock(StatementInterface::class);
        $baselineStatement   = Mockery::mock(StatementInterface::class);
        $database->shouldReceive('query')->once()->ordered()->andReturn($appliedStatement);
        $appliedStatement->shouldReceive('fetch')->once()->andReturn(self::appliedMemoryRow($candidate));
        $database->shouldReceive('query')->once()->ordered()->andReturn($existingStatement);
        $existingStatement->shouldReceive('fetch')->once()->andReturn(false);
        $database->shouldReceive('query')->once()->ordered()->andReturn($descendantStatement);
        $descendantStatement->shouldReceive('fetchAll')->once()->andReturn([]);
        $database->shouldReceive('query')->once()->ordered()->andReturn($baselineStatement);
        $baselineStatement->shouldReceive('fetch')->once()->andReturn(self::baselineMemoryRow($candidate));
        $database->shouldReceive('execute')->once()->ordered()->withArgs(
            static function (string $sql, array $values): bool {
                return str_contains($sql, 'INSERT INTO space_memory_versions')
                    && $values[2] === 13
                    && $values[5] === 'Baseline fact.'
                    && $values[8] === 'active'
                    && $values[9] === 'space-dream:proposal-1:rollback-memory:0:update'
                    && $values[10] === 'applied-memory-1';
            },
        );
        $database->shouldReceive('execute')->once()->ordered()->withArgs(
            static fn (string $sql, array $values): bool => str_contains($sql, 'UPDATE space_memory_versions')
                && $values === ['applied-memory-1', 'spc_0123456789abcdef0123456789abcdef01234567'],
        );
        $database->shouldReceive('execute')->once()->ordered()->withArgs(
            static fn (string $sql, array $values): bool => str_contains($sql, 'UPDATE agent_spaces')
                && $values[0] === 13,
        );
        $activities = new DreamActivities(
            $database,
            new SpaceStore(Mockery::mock(ORMInterface::class), $database),
            Mockery::mock(ModelCompletionGatewayInterface::class),
        );

        $report = self::method('compensateDreamMemories')->invoke(
            $activities,
            $database,
            new SpaceDreamInput($candidate->spaceId, '2026-08-12'),
            $candidate,
            11,
            12,
        );

        self::assertSame(13, $report['revisionAfter']);
        self::assertTrue($report['fullyCompensated']);
    }

    public function testForgetRollbackSkipsRestoreWhenEquivalentLiveFactExists(): void
    {
        $candidate           = self::forgetMemoryCandidate();
        $database            = Mockery::mock(DatabaseInterface::class);
        $appliedStatement    = Mockery::mock(StatementInterface::class);
        $existingStatement   = Mockery::mock(StatementInterface::class);
        $descendantStatement = Mockery::mock(StatementInterface::class);
        $baselineStatement   = Mockery::mock(StatementInterface::class);
        $duplicatesStatement = Mockery::mock(StatementInterface::class);
        $database->shouldReceive('query')->once()->ordered()->andReturn($appliedStatement);
        $appliedStatement->shouldReceive('fetch')->once()->andReturn(self::appliedMemoryRow(
            $candidate,
            status: 'forgotten',
        ));
        $database->shouldReceive('query')->once()->ordered()->andReturn($existingStatement);
        $existingStatement->shouldReceive('fetch')->once()->andReturn(false);
        $database->shouldReceive('query')->once()->ordered()->andReturn($descendantStatement);
        $descendantStatement->shouldReceive('fetchAll')->once()->andReturn([]);
        $database->shouldReceive('query')->once()->ordered()->andReturn($baselineStatement);
        $baselineStatement->shouldReceive('fetch')->once()->andReturn(self::baselineMemoryRow($candidate));
        $database->shouldReceive('query')->once()->ordered()->andReturn($duplicatesStatement);
        $duplicatesStatement->shouldReceive('fetchAll')->once()->andReturn([[
            'id'     => 'live-equivalent-memory',
            'memory' => '  BASELINE FACT. ',
        ]]);
        $database->shouldNotReceive('execute');
        $activities = new DreamActivities(
            $database,
            new SpaceStore(Mockery::mock(ORMInterface::class), $database),
            Mockery::mock(ModelCompletionGatewayInterface::class),
        );

        $report = self::method('compensateDreamMemories')->invoke(
            $activities,
            $database,
            new SpaceDreamInput($candidate->spaceId, '2026-08-12'),
            $candidate,
            11,
            12,
        );

        self::assertFalse($report['fullyCompensated']);
        self::assertSame('equivalent-active-memory-already-exists', $report['skips'][0]['reason']);
        self::assertSame(['live-equivalent-memory'], $report['skips'][0]['relatedMemoryIds']);
    }

    public function testDeferredRollbackKeepsAutonomousDreamEnabledForNextNight(): void
    {
        $database = Mockery::mock(DatabaseInterface::class);
        $database->shouldReceive('transaction')->once()->andReturnUsing(
            static fn (callable $callback): mixed => $callback($database),
        );
        $database->shouldReceive('execute')->once()->withArgs(
            static function (string $sql, array $values): bool {
                $summary = json_decode((string) $values[3], true, flags: \JSON_THROW_ON_ERROR);

                return str_contains($sql, 'UPDATE space_dream_runs')
                    && !str_contains($sql, 'dream_enabled')
                    && $summary['outcome'] === 'rollback-deferred'
                    && $summary['automaticRetry'] === true;
            },
        )->andReturn(1);
        $activities = new DreamActivities(
            $database,
            new SpaceStore(Mockery::mock(ORMInterface::class), $database),
            Mockery::mock(ModelCompletionGatewayInterface::class),
        );
        $digest = 'sha256:' . str_repeat('d', 64);

        $review = self::method('deferRegressionReview')->invoke(
            $activities,
            self::claimedInput('spc_0123456789abcdef0123456789abcdef01234567'),
            'release-2',
            'release-1',
            $digest,
            $digest,
            'live memory changed',
        );

        self::assertSame('rollback-deferred', $review->status);
    }

    public function testDescendedDreamMemoryStillRollsBackReleaseAndRecordsSelectiveSkip(): void
    {
        $database = Mockery::mock(DatabaseInterface::class);
        $database->shouldReceive('transaction')->twice()->andReturnUsing(
            static fn (callable $callback): mixed => $callback($database),
        );
        $locked     = Mockery::mock(StatementInterface::class);
        $applied    = Mockery::mock(StatementInterface::class);
        $existing   = Mockery::mock(StatementInterface::class);
        $descendant = Mockery::mock(StatementInterface::class);
        $target     = Mockery::mock(StatementInterface::class);
        $candidate  = self::memoryCandidate();
        $database->shouldReceive('query')->once()->ordered()->andReturn($locked);
        $locked->shouldReceive('fetch')->once()->andReturn([
            'active_release_id'  => 'release-2',
            'release_generation' => 7,
            'memory_revision'    => 12,
        ]);
        $database->shouldReceive('query')->once()->ordered()->andReturn($applied);
        $applied->shouldReceive('fetch')->once()->andReturn(self::appliedMemoryRow(
            $candidate,
            status: 'superseded',
        ));
        $database->shouldReceive('query')->once()->ordered()->andReturn($existing);
        $existing->shouldReceive('fetch')->once()->andReturn(false);
        $database->shouldReceive('query')->once()->ordered()->andReturn($descendant);
        $descendant->shouldReceive('fetchAll')->once()->andReturn([['id' => 'live-memory-2']]);
        $database->shouldReceive('query')->once()->ordered()->andReturn($target);
        $target->shouldReceive('fetch')->once()->andReturn([
            'id'                     => 'release-1',
            'parent_release_id'      => null,
            'source_proposal_id'     => null,
            'status'                 => 'retired',
            'evaluation_digest'      => 'sha256:' . str_repeat('a', 64),
            'capability_policy_json' => '{"version":1,"capsuleNetwork":"deny","crossSpaceReads":false}',
        ]);

        $agentSpacesTable = Mockery::mock(TableInterface::class);
        $releaseTable     = Mockery::mock(TableInterface::class);
        $eventTable       = Mockery::mock(TableInterface::class);
        $agentUpdate      = Mockery::mock(UpdateQuery::class);
        $retireUpdate     = Mockery::mock(UpdateQuery::class);
        $activateUpdate   = Mockery::mock(UpdateQuery::class);
        $eventInsert      = Mockery::mock(InsertQuery::class);
        $database->shouldReceive('table')->once()->with('agent_spaces')->andReturn($agentSpacesTable);
        $database->shouldReceive('table')->twice()->with('space_releases')->andReturn($releaseTable);
        $database->shouldReceive('table')->once()->with('space_promotion_events')->andReturn($eventTable);
        $agentSpacesTable->shouldReceive('update')->once()->andReturn($agentUpdate);
        $agentUpdate->shouldReceive('run')->once()->andReturn(1);
        $releaseTable->shouldReceive('update')->once()->andReturn($retireUpdate);
        $releaseTable->shouldReceive('update')->once()->andReturn($activateUpdate);
        $retireUpdate->shouldReceive('run')->once()->andReturn(1);
        $activateUpdate->shouldReceive('run')->once()->andReturn(1);
        $eventTable->shouldReceive('insert')->once()->andReturn($eventInsert);
        $eventInsert->shouldReceive('values')->once()->withArgs(
            static function (array $values): bool {
                $policy       = json_decode($values['policy_decision_json'], true, flags: \JSON_THROW_ON_ERROR);
                $compensation = $policy['memoryCompensation'];

                return $values['action'] === 'rollback'
                    && $policy['dreamTerminalMemoryRevision'] === 11
                    && $compensation['fullyCompensated'] === false
                    && $compensation['reviewedRevision'] === 11
                    && $compensation['revisionBefore'] === 12
                    && $compensation['revisionAfter'] === 12
                    && $compensation['skippedAppliedMemoryIds'] === ['applied-memory-1']
                    && $compensation['skips'][0]['relatedMemoryIds'] === ['live-memory-2'];
            },
        )->andReturnSelf();
        $eventInsert->shouldReceive('run')->once()->andReturn(1);
        $database->shouldReceive('execute')->times(5)->withArgs(
            static fn (string $sql, array $values): bool => !str_contains(
                $sql,
                'INSERT INTO space_memory_versions',
            ) && !str_contains($sql, 'dream_enabled = false'),
        )->andReturn(1);

        $activities = new DreamActivities(
            $database,
            new SpaceStore(Mockery::mock(ORMInterface::class), $database),
            Mockery::mock(ModelCompletionGatewayInterface::class),
        );
        $digest = 'sha256:' . str_repeat('d', 64);

        $review = self::method('rollbackRegressedRelease')->invoke(
            $activities,
            self::claimedInput($candidate->spaceId),
            'release-2',
            'release-1',
            'proposal-1',
            $candidate,
            7,
            11,
            $digest,
            $digest,
            ['regressed' => true],
        );

        self::assertSame('rolled-back', $review->status);
    }

    public function testEvidenceWatermarkConsumesNormalRunsButAccumulatesForDreamChildReview(): void
    {
        $method = self::method('evidenceWatermark');
        $policy = new DreamPolicy(lookbackHours: 72);
        $now    = 2_000_000;

        self::assertSame(1_950_000, $method->invoke(null, $policy, [
            'last_dream_at' => 1_950_000,
            'created_by'    => 'system',
            'activated_at'  => 1_800_000,
        ], $now));
        self::assertSame(1_900_000, $method->invoke(null, $policy, [
            'last_dream_at'    => 1_950_000,
            'created_by'       => 'nightly-dream-v1',
            'activated_at'     => 1_900_000,
            'release_reviewed' => false,
        ], $now));
        self::assertSame(1_950_000, $method->invoke(null, $policy, [
            'last_dream_at'    => 1_950_000,
            'created_by'       => 'nightly-dream-v1',
            'activated_at'     => 1_900_000,
            'release_reviewed' => true,
        ], $now));
    }

    public function testEvidenceSanitizerRemovesNamesHandlesAndMediaIds(): void
    {
        $sanitized = self::method('sanitizeUpdate')->invoke(null, json_encode([
            'message' => [
                'from' => [
                    'id'         => 7,
                    'is_bot'     => false,
                    'first_name' => 'Alice',
                    'last_name'  => 'Private',
                    'username'   => 'alice_private',
                ],
                'chat' => [
                    'id'    => -1001,
                    'type'  => 'supergroup',
                    'title' => 'Private title',
                ],
                'text'  => 'Keep replies short.',
                'photo' => [['file_id' => 'secret-file-id']],
            ],
        ], \JSON_THROW_ON_ERROR));

        self::assertSame([
            'message' => [
                'authorKind'           => 'user',
                'isTopicMessage'       => false,
                'participantReference' => 'telegram_user:7',
                'chatReference'        => 'telegram_chat:-1001',
                'chatType'             => 'supergroup',
                'text'                 => 'Keep replies short.',
            ],
        ], $sanitized);
        self::assertStringNotContainsString('Alice', json_encode($sanitized, \JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('secret-file-id', json_encode($sanitized, \JSON_THROW_ON_ERROR));
    }

    private static function method(string $name): ReflectionMethod
    {
        return new ReflectionMethod(DreamActivities::class, $name);
    }

    private static function claimedInput(string $spaceId): SpaceDreamInput
    {
        return new SpaceDreamInput(
            spaceId: $spaceId,
            dreamDate: '2026-08-12',
            baselineReleaseId: 'release-1',
            executionToken: 'run-001',
            executionChainToken: 'chain-001',
            executionAttempt: 1,
            executionGeneration: 1,
        );
    }

    /** @param array<string, mixed> $document */
    private static function modelResult(array $document): ModelActivityResult
    {
        return new ModelActivityResult(
            assistantMessage: [
                'role'    => 'assistant',
                'content' => [[
                    'type' => 'text',
                    'text' => json_encode($document, \JSON_THROW_ON_ERROR),
                ]],
            ],
            toolCalls: [],
            stopReason: 'stop',
        );
    }

    /** @return array<string, list<string>> */
    private static function authority(): array
    {
        return [
            'networkHosts'        => [],
            'secretRefs'          => [],
            'sideEffects'         => [],
            'stateWrites'         => [],
            'hostApiCapabilities' => [],
            'crossSpaceReads'     => [],
        ];
    }

    private static function memoryCandidate(): DreamCandidate
    {
        return new DreamCandidate(
            proposalId: 'proposal-1',
            spaceId: 'spc_0123456789abcdef0123456789abcdef01234567',
            baselineReleaseId: 'release-1',
            baselineMemoryRevision: 10,
            candidateReleaseId: 'release-2',
            candidateDigest: 'sha256:' . str_repeat('c', 64),
            releasePatch: ['memories' => [[
                'operation'         => 'append',
                'participantKey'    => 'telegram_user:7',
                'memory'            => 'A bad promoted fact.',
                'quote'             => 'bad fact',
                'context'           => 'promoted context',
                'evidenceUpdateIds' => [42],
            ]]],
            capabilityDiff: self::authority(),
            hypothesis: 'Remember a fact.',
            riskClass: 'low',
        );
    }

    private static function forgetMemoryCandidate(): DreamCandidate
    {
        return new DreamCandidate(
            proposalId: 'proposal-1',
            spaceId: 'spc_0123456789abcdef0123456789abcdef01234567',
            baselineReleaseId: 'release-1',
            baselineMemoryRevision: 10,
            candidateReleaseId: 'release-2',
            candidateDigest: 'sha256:' . str_repeat('c', 64),
            releasePatch: ['memories' => [[
                'operation'         => 'forget',
                'memoryId'          => '11111111-1111-4111-a111-111111111111',
                'quote'             => 'that is no longer true',
                'reason'            => 'The fact became obsolete.',
                'evidenceUpdateIds' => [42],
            ]]],
            capabilityDiff: self::authority(),
            hypothesis: 'Forget an obsolete fact.',
            riskClass: 'low',
        );
    }

    private static function updateMemoryCandidate(): DreamCandidate
    {
        return new DreamCandidate(
            proposalId: 'proposal-1',
            spaceId: 'spc_0123456789abcdef0123456789abcdef01234567',
            baselineReleaseId: 'release-1',
            baselineMemoryRevision: 10,
            candidateReleaseId: 'release-2',
            candidateDigest: 'sha256:' . str_repeat('c', 64),
            releasePatch: ['memories' => [[
                'operation'         => 'update',
                'memoryId'          => '11111111-1111-4111-a111-111111111111',
                'memory'            => 'Regressive changed fact.',
                'quote'             => 'bad correction',
                'context'           => 'Regressive context.',
                'evidenceUpdateIds' => [42],
            ]]],
            capabilityDiff: self::authority(),
            hypothesis: 'Update a fact.',
            riskClass: 'low',
        );
    }

    /** @return array<string, mixed> */
    private static function appliedMemoryRow(
        DreamCandidate $candidate,
        string $status = 'active',
    ): array {
        $operation  = $candidate->releasePatch['memories'][0];
        $kind       = $operation['operation'];
        $provenance = [
            'source'                 => 'nightly-dream-v1',
            'operation'              => $kind,
            'operationIndex'         => 0,
            'spaceId'                => $candidate->spaceId,
            'dreamDate'              => '2026-08-12',
            'proposalId'             => $candidate->proposalId,
            'candidateReleaseId'     => $candidate->candidateReleaseId,
            'baselineMemoryRevision' => $candidate->baselineMemoryRevision,
        ];

        return [
            'id'                   => 'applied-memory-1',
            'space_id'             => $candidate->spaceId,
            'revision'             => 11,
            'participant_key'      => 'telegram_user:7',
            'participant_label'    => 'telegram_user:7',
            'memory'               => $kind === 'forget' ? 'Baseline fact.' : $operation['memory'],
            'quote'                => $kind === 'forget' ? 'Original quote.' : $operation['quote'],
            'context'              => $kind === 'forget' ? 'Original context.' : $operation['context'],
            'status'               => $status,
            'idempotency_key'      => 'space-dream:proposal-1:memory:0:' . $kind,
            'supersedes_memory_id' => $kind === 'append' ? null : $operation['memoryId'],
            'provenance_json'      => json_encode($provenance, JSON_THROW_ON_ERROR),
            'confidence_permille'  => null,
        ];
    }

    /** @return array<string, mixed> */
    private static function baselineMemoryRow(DreamCandidate $candidate): array
    {
        return [
            'id'                  => $candidate->releasePatch['memories'][0]['memoryId'],
            'space_id'            => $candidate->spaceId,
            'revision'            => 5,
            'participant_key'     => 'telegram_user:7',
            'participant_label'   => 'telegram_user:7',
            'memory'              => 'Baseline fact.',
            'quote'               => 'Original quote.',
            'context'             => 'Original context.',
            'status'              => 'superseded',
            'confidence_permille' => null,
        ];
    }
}
