<?php

declare(strict_types=1);

namespace Bot\Space\Operations;

use Bot\Config\TemporalExecutionIdentity;
use Cycle\Database\DatabaseInterface;
use RuntimeException;

/**
 * One globally fenced release pointer. Only the explicit prepare operation can
 * change desired_release_id; periodic reconcilers can never resurrect an old
 * release merely because an old CronJob is still running.
 */
final readonly class HostReleaseStateStore
{
    public function __construct(private DatabaseInterface $database) {}

    public function prepare(string $releaseId, ?int $now = null): string
    {
        TemporalExecutionIdentity::assertReleaseId($releaseId);
        $now ??= time();

        return $this->database->transaction(function (DatabaseInterface $database) use (
            $releaseId,
            $now,
        ): string {
            $database->query("SELECT pg_advisory_xact_lock(hashtext('host-release-control'))")->fetch();
            if ($this->wasAborted($database, $releaseId)) {
                throw new RuntimeException('This immutable host release was durably aborted and cannot be prepared again.');
            }
            $row = $database->query('SELECT * FROM host_release_control WHERE singleton = true')->fetch();
            if (!is_array($row)) {
                $database->execute(<<<'SQL'
                    INSERT INTO host_release_control (
                        singleton, desired_release_id, active_release_id, phase, generation,
                        created_at, updated_at, authorized_at, activated_at, last_aborted_release_id,
                        reconciliation_owner, reconciliation_lease_until
                    ) VALUES (true, ?, NULL, 'prepared', 1, ?, ?, NULL, NULL, NULL, NULL, NULL)
                    SQL, [$releaseId, $now, $now]);

                return 'prepared';
            }

            if ((string) $row['desired_release_id'] === $releaseId) {
                if ((string) $row['phase'] === 'aborted') {
                    throw new RuntimeException('This immutable host release was durably aborted and cannot be prepared again.');
                }

                return (string) $row['phase'];
            }
            if (!in_array((string) $row['phase'], ['active', 'aborted'], true)) {
                throw new RuntimeException('Another host release is already awaiting cutover.');
            }
            if ($row['reconciliation_lease_until'] !== null
                && (int) $row['reconciliation_lease_until'] >= $now
            ) {
                throw new RuntimeException('The active host release is still being reconciled; retry preparation.');
            }

            $database->execute(<<<'SQL'
                UPDATE host_release_control
                SET desired_release_id = ?, phase = 'prepared', generation = generation + 1,
                    updated_at = ?, authorized_at = NULL,
                    reconciliation_owner = NULL, reconciliation_lease_until = NULL
                WHERE singleton = true
                SQL, [$releaseId, $now]);

            return 'prepared';
        });
    }

    public function authorize(string $releaseId, ?int $now = null): string
    {
        TemporalExecutionIdentity::assertReleaseId($releaseId);
        $now ??= time();

        return $this->database->transaction(function (DatabaseInterface $database) use ($releaseId, $now): string {
            $database->query("SELECT pg_advisory_xact_lock(hashtext('host-release-control'))")->fetch();
            $affected = $database->execute(<<<'SQL'
                UPDATE host_release_control
                SET phase = 'authorized', authorized_at = COALESCE(authorized_at, ?), updated_at = ?
                WHERE singleton = true AND desired_release_id = ? AND phase IN ('prepared', 'authorized')
                SQL, [$now, $now, $releaseId]);
            if ($affected === 1) {
                return 'authorized';
            }
            if ($this->isActive($releaseId)) {
                return 'active';
            }

            throw new RuntimeException('Only the currently prepared host release can be authorized.');
        });
    }

    /**
     * The only compensating transition. It is deliberately unavailable after
     * authorization: at that point Temporal retirement may already be in
     * progress and recovery must converge forward instead of reviving it.
     *
     * @param string $releaseId
     * @param ?int   $now
     */
    public function abortPrepared(string $releaseId, ?int $now = null): string
    {
        TemporalExecutionIdentity::assertReleaseId($releaseId);
        $now ??= time();

        return $this->database->transaction(function (DatabaseInterface $database) use ($releaseId, $now): string {
            $database->query("SELECT pg_advisory_xact_lock(hashtext('host-release-control'))")->fetch();
            $row = $database->query('SELECT * FROM host_release_control WHERE singleton = true')->fetch();
            if (!is_array($row) || (string) $row['desired_release_id'] !== $releaseId) {
                if (is_array($row) && (string) ($row['active_release_id'] ?? '') === $releaseId) {
                    // A successor may already be prepared while this release
                    // still serves traffic. Never let a stale controller
                    // tombstone the last known-good rollback target.
                    return 'retired';
                }
                if ($this->wasAborted($database, $releaseId)) {
                    return 'aborted';
                }

                $generation = is_array($row) ? (int) $row['generation'] + 1 : 1;
                $database->execute(<<<'SQL'
                    INSERT INTO host_release_abortions (release_id, aborted_at, generation)
                    VALUES (?, ?, ?)
                    ON CONFLICT (release_id) DO NOTHING
                    SQL, [$releaseId, $now, $generation]);

                return 'aborted';
            }
            if ((string) $row['phase'] !== 'prepared') {
                return (string) $row['phase'];
            }
            $database->execute(<<<'SQL'
                INSERT INTO host_release_abortions (release_id, aborted_at, generation)
                VALUES (?, ?, ?)
                ON CONFLICT (release_id) DO NOTHING
                SQL, [$releaseId, $now, (int) $row['generation']]);
            $updated = $row['active_release_id'] === null
                ? $database->execute(<<<'SQL'
                    UPDATE host_release_control
                    SET phase = 'aborted', last_aborted_release_id = ?, generation = generation + 1,
                        updated_at = ?, authorized_at = NULL, reconciliation_owner = NULL,
                        reconciliation_lease_until = NULL
                    WHERE singleton = true AND desired_release_id = ? AND phase = 'prepared'
                    SQL, [$releaseId, $now, $releaseId])
                : $database->execute(<<<'SQL'
                    UPDATE host_release_control
                    SET desired_release_id = active_release_id, phase = 'active',
                        last_aborted_release_id = ?, generation = generation + 1,
                        updated_at = ?, authorized_at = NULL, reconciliation_owner = NULL,
                        reconciliation_lease_until = NULL
                    WHERE singleton = true AND desired_release_id = ? AND phase = 'prepared'
                    SQL, [$releaseId, $now, $releaseId]);

            return $updated === 1 ? 'aborted' : ($this->status($releaseId) ?? 'missing');
        });
    }

    public function markActive(string $releaseId, string $owner, ?int $now = null): void
    {
        $now ??= time();
        $this->database->transaction(function (DatabaseInterface $database) use ($releaseId, $owner, $now): void {
            $database->query("SELECT pg_advisory_xact_lock(hashtext('host-release-control'))")->fetch();
            $affected = $database->execute(<<<'SQL'
                UPDATE host_release_control
                SET active_release_id = desired_release_id, phase = 'active',
                    activated_at = ?, updated_at = ?
                WHERE singleton = true
                    AND desired_release_id = ?
                    AND phase IN ('ingress-retired', 'active')
                    AND reconciliation_owner = ?
                    AND reconciliation_lease_until >= ?
                SQL, [$now, $now, $releaseId, $owner, $now]);
            if ($affected !== 1) {
                throw new RuntimeException('Host release cannot become active before cutover authorization.');
            }
        });
    }

    public function confirmIngressRetired(string $releaseId, ?int $now = null): string
    {
        TemporalExecutionIdentity::assertReleaseId($releaseId);
        $now ??= time();

        return $this->database->transaction(function (DatabaseInterface $database) use ($releaseId, $now): string {
            $database->query("SELECT pg_advisory_xact_lock(hashtext('host-release-control'))")->fetch();
            $affected = $database->execute(<<<'SQL'
                UPDATE host_release_control
                SET phase = 'ingress-retired', updated_at = ?
                WHERE singleton = true
                    AND desired_release_id = ?
                    AND phase IN ('authorized', 'ingress-retired')
                SQL, [$now, $releaseId]);
            if ($affected === 1) {
                return 'ingress-retired';
            }
            if ($this->isActive($releaseId)) {
                return 'active';
            }

            throw new RuntimeException('Ingress retirement can only confirm an authorized release.');
        });
    }

    public function status(string $releaseId): ?string
    {
        TemporalExecutionIdentity::assertReleaseId($releaseId);
        if ($this->wasAborted($this->database, $releaseId)) {
            return 'aborted';
        }
        $row = $this->database->query(<<<'SQL'
            SELECT desired_release_id, active_release_id, phase, last_aborted_release_id
            FROM host_release_control
            WHERE singleton = true
            SQL)->fetch();
        if (!is_array($row)) {
            return null;
        }
        if ((string) $row['desired_release_id'] === $releaseId) {
            return (string) $row['phase'];
        }

        return (string) ($row['active_release_id'] ?? '') === $releaseId ? 'retired' : 'stale';
    }

    public function acquireReconciliationLease(
        string $releaseId,
        string $owner,
        ?int $now = null,
        int $leaseSeconds = 1_800,
    ): bool {
        TemporalExecutionIdentity::assertReleaseId($releaseId);
        if ($owner === '' || $leaseSeconds < 30) {
            throw new RuntimeException('Release reconciliation lease is invalid.');
        }
        $now ??= time();

        return $this->database->transaction(function (DatabaseInterface $database) use ($releaseId, $owner, $now, $leaseSeconds): bool {
            $database->query("SELECT pg_advisory_xact_lock(hashtext('host-release-control'))")->fetch();
            $affected = $database->execute(<<<'SQL'
                UPDATE host_release_control
                SET reconciliation_owner = ?, reconciliation_lease_until = ?, updated_at = ?
                WHERE singleton = true
                    AND desired_release_id = ?
                    AND phase IN ('ingress-retired', 'active')
                    AND (
                        reconciliation_owner IS NULL
                        OR reconciliation_lease_until < ?
                        OR reconciliation_owner = ?
                    )
                SQL, [$owner, $now + $leaseSeconds, $now, $releaseId, $now, $owner]);

            return $affected === 1;
        });
    }

    public function releaseReconciliationLease(string $releaseId, string $owner, ?int $now = null): void
    {
        $now ??= time();
        $this->database->transaction(function (DatabaseInterface $database) use ($releaseId, $owner, $now): void {
            $database->query("SELECT pg_advisory_xact_lock(hashtext('host-release-control'))")->fetch();
            $database->execute(<<<'SQL'
                UPDATE host_release_control
                SET reconciliation_owner = NULL, reconciliation_lease_until = NULL, updated_at = ?
                WHERE singleton = true AND desired_release_id = ? AND reconciliation_owner = ?
                SQL, [$now, $releaseId, $owner]);
        });
    }

    public function isActive(string $releaseId): bool
    {
        $row = $this->database->query(<<<'SQL'
            SELECT active_release_id, desired_release_id, phase
            FROM host_release_control
            WHERE singleton = true
            SQL)->fetch();

        return is_array($row)
            && (string) $row['phase'] === 'active'
            && hash_equals((string) $row['desired_release_id'], $releaseId)
            && hash_equals((string) $row['active_release_id'], $releaseId);
    }

    private function wasAborted(DatabaseInterface $database, string $releaseId): bool
    {
        $row = $database->query(<<<'SQL'
            SELECT EXISTS(
                SELECT 1 FROM host_release_abortions WHERE release_id = ?
            ) AS aborted
            SQL, [$releaseId])->fetch();

        return is_array($row) && filter_var($row['aborted'] ?? false, FILTER_VALIDATE_BOOL);
    }
}
