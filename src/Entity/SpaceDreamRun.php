<?php

declare(strict_types=1);

namespace Bot\Entity;

use Cycle\Annotated\Annotation\Column;
use Cycle\Annotated\Annotation\Entity;
use Cycle\Annotated\Annotation\ForeignKey;
use Cycle\Annotated\Annotation\Table\Index;

#[Entity(table: 'space_dream_runs')]
#[ForeignKey(target: Space::class, innerKey: 'spaceId', action: 'NO ACTION', indexCreate: false)]
#[ForeignKey(target: SpaceRelease::class, innerKey: 'baselineReleaseId', action: 'NO ACTION', indexCreate: false)]
#[ForeignKey(target: SpaceRelease::class, innerKey: 'proposedReleaseId', action: 'NO ACTION', indexCreate: false)]
#[Index(['spaceId', 'dreamDate'], unique: true)]
#[Index(['status', 'startedAt'])]
class SpaceDreamRun
{
    public const string STATUS_COMPLETED = 'completed';
    public const string STATUS_FAILED    = 'failed';
    public const string STATUS_NOOP      = 'noop';
    public const string STATUS_RUNNING   = 'running';
    public const string STATUS_SCHEDULED = 'scheduled';

    public function __construct(
        #[Column(type: 'text', primary: true)]
        public string $id,
        #[Column(type: 'text')]
        public string $spaceId,
        #[Column(type: 'text')]
        public string $baselineReleaseId,
        #[Column(type: 'text')]
        public string $dreamDate,
        #[Column(type: 'text')]
        public string $executionToken,
        #[Column(type: 'text')]
        public string $executionChainToken,
        #[Column(type: 'integer')]
        public int $executionAttempt,
        #[Column(type: 'bigInteger')]
        public int $executionGeneration,
        #[Column(type: 'text')]
        public string $status = self::STATUS_SCHEDULED,
        #[Column(type: 'text')]
        public string $trigger = 'nightly',
        #[Column(type: 'bigInteger', nullable: true)]
        public ?int $evidenceFrom = null,
        #[Column(type: 'bigInteger', nullable: true)]
        public ?int $evidenceTo = null,
        #[Column(type: 'text', nullable: true)]
        public ?string $proposedReleaseId = null,
        #[Column(type: 'text')]
        public string $summaryJson = '{}',
        #[Column(type: 'bigInteger')]
        public int $createdAt = 0,
        #[Column(type: 'bigInteger', nullable: true)]
        public ?int $startedAt = null,
        #[Column(type: 'bigInteger', nullable: true)]
        public ?int $heartbeatAt = null,
        #[Column(type: 'bigInteger', nullable: true)]
        public ?int $completedAt = null,
    ) {
        $this->createdAt = $this->createdAt === 0 ? time() : $this->createdAt;
    }
}
