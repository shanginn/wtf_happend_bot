<?php

declare(strict_types=1);

namespace Bot\Space\Tools;

use Bot\Space\Persistence\SpaceId;
use Bot\Telegram\TelegramChatAuthorizationPolicy;
use InvalidArgumentException;
use RuntimeException;
use UnexpectedValueException;

/**
 * Host-owned authorization and evidence for one live Space memory tool call.
 *
 * Self-service mutations are limited to the participants who authored the
 * current batch. Cross-participant mutations require a fresh Telegram
 * owner/administrator check for every actor in that batch.
 */
final readonly class SpaceMemoryMutationAuthority
{
    /** @var list<int> */
    private array $actorUserIds;

    /** @var list<string> */
    private array $actorParticipantKeys;

    private bool $channelSelfAuthority;

    /** @var list<array{updateId: int, participantKey: string, text: string}> */
    private array $evidence;

    /**
     * @param list<mixed>                     $actorUserIds
     * @param list<array<string, mixed>>      $evidence
     * @param string                          $spaceId
     * @param string                          $batchId
     * @param int                             $chatId
     * @param string                          $chatType
     * @param bool                            $actorIdentityComplete
     * @param TelegramChatAuthorizationPolicy $authorization
     */
    public function __construct(
        private string $spaceId,
        private string $batchId,
        private int $chatId,
        private string $chatType,
        array $actorUserIds,
        bool $actorIdentityComplete,
        array $evidence,
        private TelegramChatAuthorizationPolicy $authorization,
    ) {
        SpaceId::assert($spaceId);
        if (trim($batchId) === '') {
            throw new UnexpectedValueException('Space memory tool context is missing batchId.');
        }
        if ($chatId === 0 || !in_array($chatType, ['private', 'group', 'supergroup', 'channel'], true)) {
            throw new UnexpectedValueException('Space memory tool context has invalid Telegram routing.');
        }
        $actors = [];
        foreach ($actorUserIds as $actorUserId) {
            if (!is_int($actorUserId) || $actorUserId <= 0 || isset($actors[$actorUserId])) {
                throw new UnexpectedValueException('Space memory tool actor metadata is invalid.');
            }
            $actors[$actorUserId] = true;
        }
        ksort($actors, \SORT_NUMERIC);
        $this->actorUserIds  = array_keys($actors);
        $userParticipantKeys = array_map(
            static fn (int $actorUserId): string => 'telegram_user:' . $actorUserId,
            $this->actorUserIds,
        );

        if (!array_is_list($evidence) || count($evidence) > 100) {
            throw new UnexpectedValueException('Space memory tool evidence metadata is invalid.');
        }
        $trustedEvidence = [];
        $seenUpdateIds   = [];
        foreach ($evidence as $item) {
            if (!is_array($item)) {
                throw new UnexpectedValueException('Space memory tool evidence item is invalid.');
            }
            $updateId       = $item['updateId'] ?? null;
            $participantKey = $item['participantKey'] ?? null;
            $text           = $item['text'] ?? null;
            if (
                !is_int($updateId)
                || $updateId < 0
                || isset($seenUpdateIds[$updateId])
                || !is_string($participantKey)
                || preg_match('/^(?:telegram_user:[1-9]\d*|telegram_chat:-?[1-9]\d*)$/', $participantKey) !== 1
                || !is_string($text)
                || trim($text) === ''
            ) {
                throw new UnexpectedValueException('Space memory tool evidence item is invalid.');
            }

            $seenUpdateIds[$updateId] = true;
            $trustedEvidence[]        = [
                'updateId'       => $updateId,
                'participantKey' => $participantKey,
                'text'           => $text,
            ];
        }
        $this->evidence = $trustedEvidence;

        $channelParticipantKey      = 'telegram_chat:' . $this->chatId;
        $evidenceParticipantKeys    = array_values(array_unique(array_column($trustedEvidence, 'participantKey')));
        $this->channelSelfAuthority = $this->chatType === 'channel'
            && !$actorIdentityComplete
            && $this->actorUserIds === []
            && $evidenceParticipantKeys === [$channelParticipantKey];

        if ($this->channelSelfAuthority) {
            $this->actorParticipantKeys = [$channelParticipantKey];
        } else {
            if (!$actorIdentityComplete || $this->actorUserIds === []) {
                throw new RuntimeException(
                    'Space memory mutation denied: the current Telegram batch has incomplete actor identity.',
                );
            }
            foreach ($evidenceParticipantKeys as $participantKey) {
                if (!in_array($participantKey, $userParticipantKeys, true)) {
                    throw new UnexpectedValueException('Space memory tool evidence item is invalid.');
                }
            }
            $this->actorParticipantKeys = $userParticipantKeys;
        }
    }

    /**
     * @param array<string, mixed>            $metadata
     * @param TelegramChatAuthorizationPolicy $authorization
     */
    public static function fromMetadata(
        array $metadata,
        TelegramChatAuthorizationPolicy $authorization,
    ): self {
        return new self(
            spaceId: self::requiredMetadataString($metadata, 'spaceId'),
            batchId: self::requiredMetadataString($metadata, 'batchId'),
            chatId: self::requiredMetadataInteger($metadata, 'chatId'),
            chatType: self::requiredMetadataString($metadata, 'chatType'),
            actorUserIds: self::requiredMetadataArray($metadata, 'actorUserIds'),
            actorIdentityComplete: ($metadata['actorIdentityComplete'] ?? null) === true,
            evidence: self::requiredMetadataArray($metadata, 'memoryEvidence'),
            authorization: $authorization,
        );
    }

    /**
     * @param string $spaceId
     * @param string $targetParticipantKey
     * @param string $quote
     * @param ?array $persistedAuthority
     *
     * @return array{
     *     spaceId: string,
     *     batchId: string,
     *     authorization: 'self'|'telegram-admin',
     *     targetParticipantKey: string,
     *     actorParticipantKeys: list<string>,
     *     evidence: list<array{updateId: int, participantKey: string, sha256: string}>
     * }
     */
    public function authorizeEvidence(
        string $spaceId,
        string $targetParticipantKey,
        string $quote,
        ?array $persistedAuthority = null,
    ): array {
        SpaceId::assert($spaceId);
        if ($spaceId !== $this->spaceId) {
            throw new RuntimeException('Space memory mutation authority belongs to another Space.');
        }
        $targetParticipantKey = self::participant($targetParticipantKey);
        $quote                = trim($quote);
        if ($quote === '') {
            throw new InvalidArgumentException('Space memory evidence quote must not be empty.');
        }

        $selfMutation = in_array($targetParticipantKey, $this->actorParticipantKeys, true);
        $matches      = array_values(array_filter(
            $this->evidence,
            static fn (array $item): bool => (!$selfMutation || $item['participantKey'] === $targetParticipantKey)
                && str_contains($item['text'], $quote),
        ));
        if ($matches === []) {
            throw new InvalidArgumentException(
                $selfMutation
                    ? 'Space memory evidence quote must occur verbatim in a current update from the target participant.'
                    : 'Space memory evidence quote must occur verbatim in the current Telegram batch.',
            );
        }

        $authorization = 'self';
        if (!$selfMutation) {
            $authorization = 'telegram-admin';
        }

        $provenance = [
            'spaceId'              => $this->spaceId,
            'batchId'              => $this->batchId,
            'authorization'        => $authorization,
            'targetParticipantKey' => $targetParticipantKey,
            'actorParticipantKeys' => $this->actorParticipantKeys,
            'evidence'             => array_map(
                static fn (array $item): array => [
                    'updateId'       => $item['updateId'],
                    'participantKey' => $item['participantKey'],
                    'sha256'         => 'sha256:' . hash('sha256', $item['text']),
                ],
                $matches,
            ),
        ];

        // A crash can happen after the append-only row commits but before the
        // tool activity result is persisted. An exact host-written provenance
        // match makes that retry deterministic even if Telegram is temporarily
        // unavailable or the administrator role has since changed.
        if ($persistedAuthority !== null) {
            if ($persistedAuthority !== $provenance) {
                throw new RuntimeException(
                    'Persisted Space memory authority does not match the current tool batch.',
                );
            }

            return $provenance;
        }

        if (!$selfMutation && (
            $this->channelSelfAuthority
            || !in_array($this->chatType, ['group', 'supergroup', 'channel'], true)
            || !$this->authorization->areActorsAuthorized(
                chatId: $this->chatId,
                chatType: $this->chatType,
                actorUserIds: $this->actorUserIds,
            )
        )) {
            throw new RuntimeException(
                'Space memory mutation denied: only an authenticated Telegram owner or administrator '
                . 'may modify another participant\'s memories.',
            );
        }

        return $provenance;
    }

    /** @param array<string, mixed> $metadata */
    private static function requiredMetadataString(array $metadata, string $key): string
    {
        $value = $metadata[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new UnexpectedValueException("Space memory tool context is missing {$key}.");
        }

        return $value;
    }

    /** @param array<string, mixed> $metadata */
    private static function requiredMetadataInteger(array $metadata, string $key): int
    {
        $value = $metadata[$key] ?? null;
        if (!is_int($value)) {
            throw new UnexpectedValueException("Space memory tool context is missing integer {$key}.");
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $metadata
     * @param string               $key
     *
     * @return array<mixed>
     */
    private static function requiredMetadataArray(array $metadata, string $key): array
    {
        $value = $metadata[$key] ?? null;
        if (!is_array($value)) {
            throw new UnexpectedValueException("Space memory tool context is missing {$key}.");
        }

        return $value;
    }

    private static function participant(string $participant): string
    {
        $participant = mb_strtolower(trim($participant));
        if (preg_match('/^(?:telegram_user:[1-9]\d*|telegram_chat:-?[1-9]\d*)$/', $participant) !== 1) {
            throw new InvalidArgumentException('Space memory participant reference is invalid.');
        }

        return $participant;
    }
}
