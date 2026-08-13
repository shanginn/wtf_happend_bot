<?php

declare(strict_types=1);

namespace Bot\Entity;

use Cycle\Annotated\Annotation\Column;
use Cycle\Annotated\Annotation\Entity;
use Cycle\Annotated\Annotation\ForeignKey;
use Cycle\Annotated\Annotation\Table\Index;

#[Entity(table: 'space_evaluation_runs')]
#[ForeignKey(target: SpaceUpgradeProposal::class, innerKey: 'proposalId', action: 'NO ACTION', indexCreate: false)]
#[Index(['proposalId', 'status'])]
#[Index(['proposalId'], unique: true)]
#[Index(['suiteDigest'])]
class SpaceEvaluationRun
{
    public const string STATUS_FAILED  = 'failed';
    public const string STATUS_PASSED  = 'passed';
    public const string STATUS_RUNNING = 'running';

    public function __construct(
        #[Column(type: 'text', primary: true)]
        public string $id,
        #[Column(type: 'text')]
        public string $proposalId,
        #[Column(type: 'text')]
        public string $evaluatorVersion,
        #[Column(type: 'text')]
        public string $suiteDigest,
        #[Column(type: 'text')]
        public string $status = self::STATUS_RUNNING,
        #[Column(type: 'text')]
        public string $baselineScoreJson = '{}',
        #[Column(type: 'text')]
        public string $candidateScoreJson = '{}',
        #[Column(type: 'text')]
        public string $metricsJson = '{}',
        #[Column(type: 'text', nullable: true)]
        public ?string $artifactUri = null,
        #[Column(type: 'bigInteger')]
        public int $startedAt = 0,
        #[Column(type: 'bigInteger', nullable: true)]
        public ?int $completedAt = null,
    ) {
        $this->startedAt = $this->startedAt === 0 ? time() : $this->startedAt;
    }
}
