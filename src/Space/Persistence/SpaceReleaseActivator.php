<?php

declare(strict_types=1);

namespace Bot\Space\Persistence;

use Bot\Entity\SpacePromotionEvent;
use Bot\Entity\SpaceRelease;
use Bot\Space\Runtime\SpaceCapabilityPolicy;
use Cycle\Database\DatabaseInterface;
use RuntimeException;
use Throwable;

/**
 * Moves a Space's active-release pointer without ever mutating release content.
 */
final readonly class SpaceReleaseActivator
{
    public function __construct(
        private DatabaseInterface $database,
    ) {}

    public function compareAndSwap(
        string $spaceId,
        ?string $expectedReleaseId,
        int $expectedGeneration,
        string $targetReleaseId,
        string $action,
        string $actor,
        ?string $proposalId = null,
        string $policyDecisionJson = '{}',
        ?int $now = null,
    ): SpaceActivationResult {
        SpaceId::assert($spaceId);

        if (!in_array($action, [SpacePromotionEvent::ACTION_PROMOTE, SpacePromotionEvent::ACTION_ROLLBACK], true)) {
            throw new RuntimeException(sprintf('Unsupported release activation action "%s".', $action));
        }
        if ($expectedGeneration < 0 || trim($actor) === '') {
            throw new RuntimeException('Release activation requires a valid generation and actor.');
        }

        try {
            $policy = json_decode($policyDecisionJson, true, flags: \JSON_THROW_ON_ERROR);
        } catch (Throwable $error) {
            throw new RuntimeException('Release activation policy decision must be valid JSON.', previous: $error);
        }
        if (!is_array($policy) || ($policy !== [] && array_is_list($policy))) {
            throw new RuntimeException('Release activation policy decision must be a JSON object.');
        }

        $now ??= time();

        return $this->database->transaction(function (DatabaseInterface $database) use (
            $spaceId,
            $expectedReleaseId,
            $expectedGeneration,
            $targetReleaseId,
            $action,
            $actor,
            $proposalId,
            $policyDecisionJson,
            $now,
        ): SpaceActivationResult {
            $target = $database->query(<<<'SQL'
                SELECT id, parent_release_id, source_proposal_id, status,
                    evaluation_digest, capability_policy_json
                FROM space_releases
                WHERE id = ? AND space_id = ?
                FOR UPDATE
                SQL, [$targetReleaseId, $spaceId])->fetch();
            if (!is_array($target)) {
                throw new RuntimeException('The target release does not belong to the Space.');
            }
            if ($targetReleaseId === $expectedReleaseId) {
                throw new RuntimeException('Release activation must move to a different immutable release.');
            }
            SpaceCapabilityPolicy::assertFixed((string) $target['capability_policy_json']);
            if ($action === SpacePromotionEvent::ACTION_PROMOTE) {
                if ($expectedReleaseId === null
                    || (string) $target['parent_release_id'] !== $expectedReleaseId
                    || (string) $target['status'] !== SpaceRelease::STATUS_EVALUATED
                    || $target['evaluation_digest'] === null
                    || $proposalId === null
                    || (string) $target['source_proposal_id'] !== $proposalId
                ) {
                    throw new RuntimeException('Only an evaluated direct child with its matching proposal can be promoted.');
                }
                $approved = (int) $database->query(<<<'SQL'
                    SELECT COUNT(*)
                    FROM space_upgrade_proposals AS proposal
                    JOIN space_evaluation_runs AS evaluation
                        ON evaluation.proposal_id = proposal.id
                        AND evaluation.status = 'passed'
                        AND evaluation.metrics_json::jsonb ->> 'evaluationDigest' = ?
                    WHERE proposal.id = ?
                        AND proposal.space_id = ?
                        AND proposal.baseline_release_id = ?
                        AND proposal.candidate_release_id = ?
                        AND proposal.status = 'proposed'
                    SQL, [
                    (string) $target['evaluation_digest'],
                    $proposalId,
                    $spaceId,
                    $expectedReleaseId,
                    $targetReleaseId,
                ])->fetchColumn();
                if ($approved < 1) {
                    throw new RuntimeException('The host evaluator has not approved this proposal.');
                }
            } elseif ((string) $target['status'] !== SpaceRelease::STATUS_RETIRED) {
                throw new RuntimeException('Rollback targets must be retired known-good releases.');
            }

            $nextGeneration = $expectedGeneration + 1;
            $activated      = $database->table('agent_spaces')->update(
                [
                    'active_release_id'  => $targetReleaseId,
                    'release_generation' => $nextGeneration,
                    'updated_at'         => $now,
                ],
                [
                    'id'                 => $spaceId,
                    'active_release_id'  => $expectedReleaseId,
                    'release_generation' => $expectedGeneration,
                ],
            )->run();

            if ($activated !== 1) {
                return SpaceActivationResult::conflict($expectedGeneration);
            }

            if ($expectedReleaseId !== null && $expectedReleaseId !== $targetReleaseId) {
                $database->table('space_releases')->update(
                    ['status' => SpaceRelease::STATUS_RETIRED],
                    ['id' => $expectedReleaseId, 'space_id' => $spaceId],
                )->run();
            }

            $database->table('space_releases')->update(
                ['status' => SpaceRelease::STATUS_ACTIVE, 'activated_at' => $now],
                ['id' => $targetReleaseId, 'space_id' => $spaceId],
            )->run();

            $database->table('space_promotion_events')->insert()->values([
                'id'                        => SpaceRecordId::new(),
                'space_id'                  => $spaceId,
                'proposal_id'               => $proposalId,
                'from_release_id'           => $expectedReleaseId,
                'to_release_id'             => $targetReleaseId,
                'action'                    => $action,
                'release_generation_before' => $expectedGeneration,
                'release_generation_after'  => $nextGeneration,
                'actor'                     => $actor,
                'policy_decision_json'      => $policyDecisionJson,
                'created_at'                => $now,
            ])->run();

            return SpaceActivationResult::activated($nextGeneration);
        });
    }
}
