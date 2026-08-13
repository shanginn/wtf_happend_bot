<?php

declare(strict_types=1);

namespace Bot\Space\Tools;

use Bot\Space\Persistence\SpaceId;
use Bot\Space\Publication\SpaceCapabilityPublicationRejected;
use Bot\Telegram\TelegramChatAuthorizationPolicy;
use UnexpectedValueException;

/**
 * Binds one persistent Space capability mutation to the exact Telegram update
 * that requested it and to that update's freshly verified owner/admin actor.
 */
final readonly class SpaceCapabilityMutationAuthority
{
    /** @var list<int> */
    private array $actorUserIds;

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
            throw new UnexpectedValueException('Space capability context is missing batchId.');
        }
        if ($chatId === 0 || !in_array($chatType, ['private', 'group', 'supergroup'], true)) {
            throw new UnexpectedValueException('Space capability context has invalid Telegram routing.');
        }
        if (!$actorIdentityComplete || !array_is_list($actorUserIds) || $actorUserIds === []) {
            throw new SpaceCapabilityPublicationRejected(
                'Space capability publication denied: the current Telegram batch has incomplete actor identity.',
            );
        }

        $actors = [];
        foreach ($actorUserIds as $actorUserId) {
            if (!is_int($actorUserId) || $actorUserId <= 0 || isset($actors[$actorUserId])) {
                throw new UnexpectedValueException('Space capability actor metadata is invalid.');
            }
            $actors[$actorUserId] = true;
        }
        ksort($actors, \SORT_NUMERIC);
        $this->actorUserIds = array_keys($actors);

        if (!array_is_list($evidence) || $evidence === [] || count($evidence) > 100) {
            throw new UnexpectedValueException('Space capability evidence metadata is invalid.');
        }
        $trustedEvidence = [];
        $seenUpdateIds   = [];
        foreach ($evidence as $item) {
            if (!is_array($item)) {
                throw new UnexpectedValueException('Space capability evidence item is invalid.');
            }
            $updateId       = $item['updateId'] ?? null;
            $participantKey = $item['participantKey'] ?? null;
            $text           = $item['text'] ?? null;
            if (!is_int($updateId)
                || $updateId < 0
                || isset($seenUpdateIds[$updateId])
                || !is_string($participantKey)
                || preg_match('/\Atelegram_user:([1-9]\d*)\z/D', $participantKey) !== 1
                || !is_string($text)
                || trim($text) === ''
            ) {
                throw new UnexpectedValueException('Space capability evidence item is invalid.');
            }
            $seenUpdateIds[$updateId] = true;
            $trustedEvidence[]        = [
                'updateId'       => $updateId,
                'participantKey' => $participantKey,
                'text'           => $text,
            ];
        }
        $this->evidence = $trustedEvidence;
    }

    /** @param array<string, mixed> $metadata */
    public static function fromMetadata(
        array $metadata,
        TelegramChatAuthorizationPolicy $authorization,
    ): self {
        return new self(
            spaceId: self::requiredString($metadata, 'spaceId'),
            batchId: self::requiredString($metadata, 'batchId'),
            chatId: self::requiredInteger($metadata, 'chatId'),
            chatType: self::requiredString($metadata, 'chatType'),
            actorUserIds: self::requiredArray($metadata, 'actorUserIds'),
            actorIdentityComplete: ($metadata['actorIdentityComplete'] ?? null) === true,
            evidence: self::requiredArray($metadata, 'memoryEvidence'),
            authorization: $authorization,
        );
    }

    /**
     * @param array<string, mixed>|null $persistedAuthority
     * @param int                       $requestUpdateId
     * @param string                    $requestQuote
     * @param string                    $kind
     * @param string                    $name
     *
     * @return array{
     *   spaceId: string,
     *   batchId: string,
     *   authorization: 'private-owner'|'telegram-admin',
     *   actorParticipantKey: string,
     *   requestUpdateId: int,
     *   requestSha256: string,
     *   quoteSha256: string
     * }
     */
    public function authorize(
        int $requestUpdateId,
        string $requestQuote,
        string $kind,
        string $name,
        ?array $persistedAuthority = null,
    ): array {
        $requestQuote = trim($requestQuote);
        if ($requestUpdateId < 0 || $requestQuote === '') {
            throw new SpaceCapabilityPublicationRejected(
                'Space capability publication requires an exact current request reference.',
            );
        }

        $matching = array_values(array_filter(
            $this->evidence,
            static fn (array $item): bool => $item['updateId'] === $requestUpdateId,
        ));
        if (count($matching) !== 1 || !str_contains($matching[0]['text'], $requestQuote)) {
            throw new SpaceCapabilityPublicationRejected(
                'Space capability publication denied: the quoted request must occur in the selected current update.',
            );
        }

        $evidence = $matching[0];
        self::assertExplicitMutationRequest($evidence['text'], $kind, $name);
        $participantKey = $evidence['participantKey'];
        $actorUserId    = (int) substr($participantKey, strlen('telegram_user:'));
        if (!in_array($actorUserId, $this->actorUserIds, true)) {
            throw new UnexpectedValueException(
                'Space capability request actor is inconsistent with host-authored batch metadata.',
            );
        }

        $provenance = [
            'spaceId'             => $this->spaceId,
            'batchId'             => $this->batchId,
            'authorization'       => $this->chatType === 'private' ? 'private-owner' : 'telegram-admin',
            'actorParticipantKey' => $participantKey,
            'requestUpdateId'     => $requestUpdateId,
            'requestSha256'       => 'sha256:' . hash('sha256', $evidence['text']),
            'quoteSha256'         => 'sha256:' . hash('sha256', $requestQuote),
        ];
        if ($persistedAuthority !== null) {
            if ($persistedAuthority !== $provenance) {
                throw new SpaceCapabilityPublicationRejected(
                    'Persisted Space capability authority does not match the current request.',
                );
            }

            return $provenance;
        }

        if (!$this->authorization->areActorsAuthorized(
            chatId: $this->chatId,
            chatType: $this->chatType,
            actorUserIds: [$actorUserId],
        )) {
            throw new SpaceCapabilityPublicationRejected(
                'Space capability publication denied: only the requesting chat owner or administrator may publish it.',
            );
        }

        return $provenance;
    }

    /**
     * Return only the trusted Telegram text whose hashes were authorized above.
     * The publication tool uses this instead of model-authored instructions so
     * adjacent batch messages cannot alter the persistent capability body.
     *
     * @param array<string, mixed> $provenance
     */
    public function authorizedRequestText(array $provenance): string
    {
        $requestUpdateId = $provenance['requestUpdateId'] ?? null;
        $requestSha256   = $provenance['requestSha256'] ?? null;
        if (!is_int($requestUpdateId) || !is_string($requestSha256)) {
            throw new UnexpectedValueException('Space capability authority provenance is incomplete.');
        }

        foreach ($this->evidence as $item) {
            if ($item['updateId'] !== $requestUpdateId) {
                continue;
            }
            if ('sha256:' . hash('sha256', $item['text']) !== $requestSha256) {
                throw new UnexpectedValueException(
                    'Space capability authority does not match its trusted request text.',
                );
            }

            return trim($item['text']);
        }

        throw new UnexpectedValueException('Space capability authority refers to an unknown request.');
    }

    private static function assertExplicitMutationRequest(string $text, string $kind, string $name): void
    {
        $text = mb_strtolower($text);
        $name = mb_strtolower(trim($name));
        if (!in_array($kind, ['skill', 'command'], true) || $name === '') {
            throw new SpaceCapabilityPublicationRejected(
                'Space capability publication has an invalid requested capability identity.',
            );
        }

        // Persistent prompt changes require an unambiguously affirmative
        // request. Mixed or negated wording is intentionally fail-closed: the
        // administrator can restate it plainly, while the model cannot turn a
        // prohibition or discussion into durable authority.
        $negativeIntent = preg_match(
            '/(?<![\pL\d_])(?:не|никогда|против|кроме|исключая|без|never|no|not|except|excluding|without|do\s+not|don[’\']t)(?![\pL\d_])/iu',
            $text,
        ) === 1;
        if ($negativeIntent) {
            throw new SpaceCapabilityPublicationRejected(
                'Space capability publication denied: the selected update explicitly negates publication.',
            );
        }

        $mutationVerb = preg_match(
            '/\A\s*(?:(?:бот|bot)[,!:]?\s+)?(?:добавь|добавьте|создай|создайте|обнови|обновите|измени|измените|сделай|сделайте|научи|научись|add|create|update|change|teach)(?![\pL\d_])/iu',
            $text,
        ) === 1;
        $kindNoun = $kind === 'command'
            ? preg_match('/(?<![\pL\d_])(?:команд[а-яё]*|command)(?![\pL\d_])/iu', $text) === 1
            : preg_match('/(?<![\pL\d_])(?:навык[а-яё]*|скилл[а-яё]*|правил[а-яё]*|поведени[а-яё]*|skill|behavior|rule)(?![\pL\d_])/iu', $text) === 1;
        if (!$mutationVerb || !$kindNoun) {
            throw new SpaceCapabilityPublicationRejected(
                'Space capability publication denied: the selected update is not an explicit publication request.',
            );
        }

        if ($kind === 'command') {
            preg_match_all('/(?<![\pL\d_])\/([a-z][a-z0-9_]{0,31})(?![\pL\d_])/iu', $text, $matches);
            $commandNames = array_values(array_unique(array_map(
                static fn (string $value): string => mb_strtolower($value),
                is_array($matches[1] ?? null) ? $matches[1] : [],
            )));
            if ($commandNames !== [$name]) {
                throw new SpaceCapabilityPublicationRejected(
                    'Space capability publication denied: the request must name exactly one slash command.',
                );
            }
        } else {
            preg_match_all(
                '/(?<![\pL\d_])(?:навык[а-яё]*|скилл[а-яё]*|правил[а-яё]*|поведени[а-яё]*|skill|behavior|rule)(?![\pL\d_])\s+\/?([a-z0-9][a-z0-9_-]{0,63})(?![\pL\d_])/iu',
                $text,
                $matches,
            );
            $skillNames = array_values(array_unique(array_map(
                static fn (string $value): string => mb_strtolower($value),
                is_array($matches[1] ?? null) ? $matches[1] : [],
            )));
            if ($skillNames !== [$name]) {
                throw new SpaceCapabilityPublicationRejected(
                    'Space capability publication denied: the request must name exactly one skill target.',
                );
            }
        }

        $mentioned = preg_match(
            '/(?:\/|(?<![\pL\d_]))' . preg_quote($name, '/') . '(?![\pL\d_])/iu',
            $text,
        ) === 1;
        if (!$mentioned) {
            throw new SpaceCapabilityPublicationRejected(
                'Space capability publication denied: the selected update does not name this capability.',
            );
        }
    }

    /** @param array<string, mixed> $metadata */
    private static function requiredString(array $metadata, string $key): string
    {
        $value = $metadata[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new UnexpectedValueException("Space capability context is missing {$key}.");
        }

        return $value;
    }

    /** @param array<string, mixed> $metadata */
    private static function requiredInteger(array $metadata, string $key): int
    {
        $value = $metadata[$key] ?? null;
        if (!is_int($value)) {
            throw new UnexpectedValueException("Space capability context is missing {$key}.");
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $metadata
     * @param string               $key
     *
     * @return list<mixed>
     */
    private static function requiredArray(array $metadata, string $key): array
    {
        $value = $metadata[$key] ?? null;
        if (!is_array($value) || !array_is_list($value)) {
            throw new UnexpectedValueException("Space capability context is missing {$key}.");
        }

        return $value;
    }
}
