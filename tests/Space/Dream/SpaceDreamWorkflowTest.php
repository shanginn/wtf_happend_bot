<?php

declare(strict_types=1);

namespace Tests\Space\Dream;

use Bot\Space\Dream\DreamActivitiesInterface;
use Bot\Space\Dream\DreamCandidate;
use Bot\Space\Dream\DreamEvaluation;
use Bot\Space\Dream\DreamEvidence;
use Bot\Space\Dream\DreamRegressionReview;
use Bot\Space\Dream\SpaceDreamInput;
use Bot\Space\Dream\SpaceDreamWorkflow;
use Mockery;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;
use Tests\TestCase;

final class SpaceDreamWorkflowTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testPassingSameAuthorityCandidatePromotesAutomatically(): void
    {
        $activities = Mockery::mock(DreamActivitiesInterface::class);
        $activities->shouldReceive('harvestEvidence')->once()->andReturn(self::evidence());
        $activities->shouldReceive('reviewActiveRelease')->once()->andReturn(self::stableReview());
        $activities->shouldReceive('buildCandidate')->once()->andReturn(self::candidate());
        $activities->shouldReceive('evaluateCandidate')->once()->andReturn(self::evaluation(true, true));
        $activities->shouldReceive('promoteCandidate')->once()->andReturn(true);
        $activities->shouldNotReceive('stageCandidate', 'recordNoop', 'recordFailure');

        $outcome = self::workflow($activities)->run(self::input());

        self::assertSame('promoted', $outcome->status);
    }

    public function testFailedAndAuthorityExpandingCandidatesAreTerminallyRejected(): void
    {
        foreach ([self::evaluation(false, false), self::evaluation(true, false)] as $evaluation) {
            $activities = Mockery::mock(DreamActivitiesInterface::class);
            $activities->shouldReceive('harvestEvidence')->once()->andReturn(self::evidence());
            $activities->shouldReceive('reviewActiveRelease')->once()->andReturn(self::stableReview());
            $activities->shouldReceive('buildCandidate')->once()->andReturn(self::candidate());
            $activities->shouldReceive('evaluateCandidate')->once()->andReturn($evaluation);
            $activities->shouldReceive('stageCandidate')->once();
            $activities->shouldNotReceive('promoteCandidate', 'recordFailure');

            $outcome = self::workflow($activities)->run(self::input());
            self::assertSame('rejected', $outcome->status);
            self::assertNotSame('awaiting-approval', $outcome->status);
        }
    }

    public function testRollbackOutcomeStopsCandidateGeneration(): void
    {
        $activities = Mockery::mock(DreamActivitiesInterface::class);
        $activities->shouldReceive('harvestEvidence')->once()->andReturn(self::evidence());
        $activities->shouldReceive('reviewActiveRelease')->once()->andReturn(new DreamRegressionReview(
            status: DreamRegressionReview::STATUS_ROLLED_BACK,
            fromReleaseId: 'release-1',
            toReleaseId: 'release-0',
            evaluationDigest: 'sha256:' . str_repeat('d', 64),
            reason: 'confirmed regression',
        ));
        $activities->shouldNotReceive(
            'buildCandidate',
            'evaluateCandidate',
            'promoteCandidate',
            'stageCandidate',
            'recordNoop',
            'recordFailure',
        );

        $outcome = self::workflow($activities)->run(self::input());
        self::assertSame('rolled-back', $outcome->status);
        self::assertSame('release-0', $outcome->candidateReleaseId);
    }

    public function testTerminalActivityFailureIsRecordedAndRethrown(): void
    {
        $activities = Mockery::mock(DreamActivitiesInterface::class);
        $activities->shouldReceive('harvestEvidence')->once()->andReturn(self::evidence());
        $activities->shouldReceive('reviewActiveRelease')->once()->andThrow(
            new RuntimeException('replay service failed'),
        );
        $activities->shouldReceive('recordFailure')
            ->once()
            ->withArgs(static fn (
                SpaceDreamInput $input,
                string $reason,
                string $digest,
            ): bool => $input->spaceId === self::input()->spaceId
                && str_contains($reason, 'replay service failed')
                && preg_match('/\Asha256:[a-f0-9]{64}\z/D', $digest) === 1);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('replay service failed');
        self::workflow($activities)->run(self::input());
    }

    private static function workflow(DreamActivitiesInterface $activities): SpaceDreamWorkflow
    {
        $activities->shouldReceive('claimDreamRun')
            ->once()
            ->andReturnUsing(static fn (SpaceDreamInput $input): SpaceDreamInput => $input->claim(
                'release-1',
                1,
            ));
        $workflow = (new ReflectionClass(SpaceDreamWorkflow::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(SpaceDreamWorkflow::class, 'activities'))->setValue($workflow, $activities);

        return $workflow;
    }

    private static function input(): SpaceDreamInput
    {
        return new SpaceDreamInput(
            'spc_0123456789abcdef0123456789abcdef01234567',
            '2026-08-12',
            executionToken: 'run-001',
            executionChainToken: 'chain-001',
            executionAttempt: 1,
        );
    }

    private static function evidence(): DreamEvidence
    {
        return new DreamEvidence(
            spaceId: self::input()->spaceId,
            baselineReleaseId: 'release-1',
            baselineReleaseDigest: 'sha256:' . str_repeat('a', 64),
            items: array_map(static fn (int $id): array => [
                'updateId'  => $id,
                'createdAt' => 1_700_000_000 + $id,
                'payload'   => ['message' => ['authorKind' => 'user', 'text' => 'message ' . $id]],
            ], range(1, 6)),
            baselineMetrics: ['evidenceItems' => 6],
            evidenceDigest: 'sha256:' . str_repeat('b', 64),
        );
    }

    private static function stableReview(): DreamRegressionReview
    {
        return new DreamRegressionReview(
            status: DreamRegressionReview::STATUS_STABLE,
            fromReleaseId: 'release-1',
            toReleaseId: null,
            evaluationDigest: null,
            reason: 'stable',
        );
    }

    private static function candidate(): DreamCandidate
    {
        return new DreamCandidate(
            proposalId: 'proposal-1',
            spaceId: self::input()->spaceId,
            baselineReleaseId: 'release-1',
            baselineMemoryRevision: 0,
            candidateReleaseId: 'release-2',
            candidateDigest: 'sha256:' . str_repeat('c', 64),
            releasePatch: ['prompt' => 'improved', 'memories' => []],
            capabilityDiff: [
                'networkHosts'        => [],
                'secretRefs'          => [],
                'sideEffects'         => [],
                'stateWrites'         => [],
                'hostApiCapabilities' => [],
                'crossSpaceReads'     => [],
            ],
            hypothesis: 'Improve responses.',
            riskClass: 'low',
        );
    }

    private static function evaluation(bool $passed, bool $sameAuthority): DreamEvaluation
    {
        return new DreamEvaluation(
            evaluationId: 'evaluation-1',
            evaluationDigest: 'sha256:' . str_repeat('d', 64),
            passed: $passed,
            sameAuthority: $sameAuthority,
            baselineMetrics: [],
            candidateMetrics: [],
            failedGates: $passed ? [] : ['failed host gate'],
        );
    }
}
