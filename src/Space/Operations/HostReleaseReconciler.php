<?php

declare(strict_types=1);

namespace Bot\Space\Operations;

use Bot\Space\Dream\DreamScheduleManager;

final readonly class HostReleaseReconciler
{
    public function __construct(
        private HostReleaseStateStore $states,
        private DreamScheduleManager $schedules,
        private LegacyWorkflowTerminator $workflows,
    ) {}

    /**
     * @param string $releaseId
     * @param string $dreamTaskQueue
     * @param string $dreamTimeZone
     * @param int    $dreamHour
     * @param int    $dreamMinute
     * @param int    $dreamJitterMinutes
     *
     * @return array{releaseId: string, status: string, scheduleQuiesced: bool, terminated: int}
     */
    public function reconcile(
        string $releaseId,
        string $dreamTaskQueue,
        string $dreamTimeZone,
        int $dreamHour,
        int $dreamMinute,
        int $dreamJitterMinutes,
    ): array {
        $status = $this->states->status($releaseId);
        if ($status === null || $status === 'stale' || $status === 'retired') {
            return [
                'releaseId'        => $releaseId,
                'status'           => 'stale',
                'scheduleQuiesced' => false,
                'terminated'       => 0,
            ];
        }
        if ($status === 'prepared' || $status === 'authorized') {
            return [
                'releaseId'        => $releaseId,
                'status'           => $status,
                'scheduleQuiesced' => false,
                'terminated'       => 0,
            ];
        }
        $owner = bin2hex(random_bytes(16));
        if (!$this->states->acquireReconciliationLease($releaseId, $owner)) {
            return [
                'releaseId'        => $releaseId,
                'status'           => 'reconciling',
                'scheduleQuiesced' => false,
                'terminated'       => 0,
            ];
        }

        try {
            // Active runs only heal and verify desired state. Quiescing the
            // already-current schedule on every Cron tick would introduce a
            // nightly race around the configured Dream time.
            $scheduleQuiesced = false;
            $terminatedCount  = 0;
            if ($status === 'ingress-retired') {
                $scheduleQuiesced = $this->schedules->quiesce($releaseId);
                $terminated       = $this->workflows->run(true);
                $terminatedCount  = count($terminated->executions);
            }
            $this->schedules->install(
                taskQueue: $dreamTaskQueue,
                hostReleaseId: $releaseId,
                timeZone: $dreamTimeZone,
                hour: $dreamHour,
                minute: $dreamMinute,
                jitterMinutes: $dreamJitterMinutes,
            );
            if ($status === 'ingress-retired') {
                $this->states->markActive($releaseId, $owner);
            }

            return [
                'releaseId'        => $releaseId,
                'status'           => 'active',
                'scheduleQuiesced' => $scheduleQuiesced,
                'terminated'       => $terminatedCount,
            ];
        } finally {
            $this->states->releaseReconciliationLease($releaseId, $owner);
        }
    }
}
