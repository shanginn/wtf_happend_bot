<?php

declare(strict_types=1);

namespace Bot\Space\Dream;

/**
 * Host-owned validation and canonicalization for nightly memory mutations.
 *
 * The model may propose a mutation, but every mutation must cite one or more
 * author-visible Telegram updates and an exact quote from those updates. The
 * contextual gate also prevents a Dream from targeting a memory outside the
 * revision it observed while mining the candidate.
 */
final class DreamMemoryPatch
{
    public const int MAX_OPERATIONS     = 4;
    public const int MAX_EVIDENCE_ITEMS = 5;
    public const int MAX_MEMORY_BYTES   = 1_000;
    public const int MAX_QUOTE_BYTES    = 1_000;
    public const int MAX_CONTEXT_BYTES  = 2_000;
    public const int MAX_REASON_BYTES   = 1_000;

    /**
     * @param mixed $operations
     *
     * @return list<string>
     */
    public static function structuralViolations(mixed $operations): array
    {
        if (!is_array($operations) || !array_is_list($operations)) {
            return ['memories must be a list'];
        }

        $violations = [];
        if (count($operations) > self::MAX_OPERATIONS) {
            $violations[] = 'candidate exceeds the nightly memory operation budget';
        }

        foreach ($operations as $index => $operation) {
            if (!is_array($operation) || array_is_list($operation)) {
                $violations[] = "memory operation {$index} must be an object";

                continue;
            }

            $kind = $operation['operation'] ?? null;
            if (!is_string($kind) || !in_array($kind, ['append', 'update', 'forget'], true)) {
                $violations[] = "memory operation {$index} has an invalid operation";

                continue;
            }

            $allowed = match ($kind) {
                'append' => [
                    'operation',
                    'participantKey',
                    'memory',
                    'quote',
                    'context',
                    'evidenceUpdateIds',
                    'confidencePermille',
                ],
                'update' => [
                    'operation',
                    'memoryId',
                    'memory',
                    'quote',
                    'context',
                    'evidenceUpdateIds',
                    'confidencePermille',
                ],
                'forget' => [
                    'operation',
                    'memoryId',
                    'quote',
                    'reason',
                    'evidenceUpdateIds',
                ],
            };
            foreach (array_keys($operation) as $field) {
                if (!is_string($field) || !in_array($field, $allowed, true)) {
                    $violations[] = "memory operation {$index} contains an unsupported field";

                    break;
                }
            }

            if ($kind === 'append') {
                $participantKey = $operation['participantKey'] ?? null;
                if (!is_string($participantKey)
                    || preg_match('/\A(?:telegram_user:[1-9]\d*|telegram_chat:-?[1-9]\d*)\z/D', $participantKey) !== 1
                ) {
                    $violations[] = "memory operation {$index} has an invalid participant key";
                }
            } else {
                $memoryId = $operation['memoryId'] ?? null;
                if (!is_string($memoryId)
                    || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/D', $memoryId) !== 1
                ) {
                    $violations[] = "memory operation {$index} has an invalid memory id";
                }
            }

            if ($kind !== 'forget') {
                self::boundedStringViolation(
                    $violations,
                    $operation['memory'] ?? null,
                    self::MAX_MEMORY_BYTES,
                    "memory operation {$index} has an invalid memory",
                );
                self::boundedStringViolation(
                    $violations,
                    $operation['context'] ?? null,
                    self::MAX_CONTEXT_BYTES,
                    "memory operation {$index} has an invalid context",
                );

                $confidence = $operation['confidencePermille'] ?? null;
                if ($confidence !== null && (!is_int($confidence) || $confidence < 0 || $confidence > 1000)) {
                    $violations[] = "memory operation {$index} has invalid confidence";
                }
            } else {
                self::boundedStringViolation(
                    $violations,
                    $operation['reason'] ?? null,
                    self::MAX_REASON_BYTES,
                    "memory operation {$index} has an invalid reason",
                );
            }

            self::boundedStringViolation(
                $violations,
                $operation['quote'] ?? null,
                self::MAX_QUOTE_BYTES,
                "memory operation {$index} has an invalid evidence quote",
            );
            $evidenceIds = $operation['evidenceUpdateIds'] ?? null;
            if (!is_array($evidenceIds)
                || !array_is_list($evidenceIds)
                || count($evidenceIds) < 1
                || count($evidenceIds) > self::MAX_EVIDENCE_ITEMS
                || array_filter($evidenceIds, static fn (mixed $id): bool => !is_int($id) || $id < 1) !== []
                || count(array_unique($evidenceIds, \SORT_REGULAR)) !== count($evidenceIds)
            ) {
                $violations[] = "memory operation {$index} has invalid evidence update ids";
            }
        }

        return array_values(array_unique($violations));
    }

    /**
     * @param list<array<string, mixed>> $operations
     * @param list<array<string, mixed>> $evidenceItems
     * @param list<array{
     *     id: string,
     *     participantKey: string,
     *     participantLabel: string,
     *     memory: string,
     *     quote: string,
     *     context: string,
     *     confidencePermille: ?int
     * }> $activeMemories
     *
     * @return list<string>
     */
    public static function contextualViolations(
        array $operations,
        array $evidenceItems,
        array $activeMemories,
    ): array {
        $violations   = [];
        $evidenceById = [];
        foreach ($evidenceItems as $item) {
            $updateId = $item['updateId'] ?? null;
            if (is_int($updateId) && $updateId > 0) {
                $evidenceById[$updateId] = $item;
            }
        }

        $memoryById    = [];
        $existingFacts = [];
        foreach ($activeMemories as $memory) {
            $memoryById[$memory['id']]                                                  = $memory;
            $existingFacts[self::factKey($memory['participantKey'], $memory['memory'])] = true;
        }

        $targeted = [];
        $appended = [];
        foreach ($operations as $index => $operation) {
            if (!is_array($operation)) {
                continue;
            }
            $kind        = $operation['operation'] ?? null;
            $evidenceIds = is_array($operation['evidenceUpdateIds'] ?? null)
                ? $operation['evidenceUpdateIds']
                : [];
            $referenced = [];
            foreach ($evidenceIds as $updateId) {
                if (!is_int($updateId) || !isset($evidenceById[$updateId])) {
                    $violations[] = "memory operation {$index} cites evidence outside the author set";

                    continue;
                }
                $referenced[] = $evidenceById[$updateId];
            }

            $quote = is_string($operation['quote'] ?? null)
                ? trim($operation['quote'])
                : '';
            if ($quote !== '' && !self::quoteOccursIn($quote, $referenced)) {
                $violations[] = "memory operation {$index} quote is absent from its cited evidence";
            }

            if ($kind === 'append') {
                $participantKey = is_string($operation['participantKey'] ?? null)
                    ? $operation['participantKey']
                    : '';
                if ($participantKey !== '' && !self::participantOccursIn($participantKey, $referenced)) {
                    $violations[] = "memory operation {$index} participant is absent from its cited evidence";
                }
                if ($participantKey !== '' && $quote !== ''
                    && !self::quoteIsAuthoredBy($quote, $participantKey, $referenced)
                ) {
                    $violations[] = "memory operation {$index} evidence quote is not authored by its target participant";
                }
                $memory  = is_string($operation['memory'] ?? null) ? trim($operation['memory']) : '';
                $factKey = self::factKey($participantKey, $memory);
                if (isset($existingFacts[$factKey]) || isset($appended[$factKey])) {
                    $violations[] = "memory operation {$index} duplicates an active fact";
                }
                $appended[$factKey] = true;

                continue;
            }

            if ($kind !== 'update' && $kind !== 'forget') {
                continue;
            }
            $memoryId = is_string($operation['memoryId'] ?? null) ? $operation['memoryId'] : '';
            if (!isset($memoryById[$memoryId])) {
                $violations[] = "memory operation {$index} targets memory outside the pinned baseline";

                continue;
            }
            if (isset($targeted[$memoryId])) {
                $violations[] = "memory operation {$index} repeats a memory target";
            }
            $targeted[$memoryId] = true;
            $target              = $memoryById[$memoryId];
            if ($quote !== ''
                && !self::quoteIsAuthoredBy($quote, $target['participantKey'], $referenced)
            ) {
                $violations[] = "memory operation {$index} evidence quote is not authored by its target participant";
            }

            if ($kind === 'update') {
                $memory  = is_string($operation['memory'] ?? null) ? trim($operation['memory']) : '';
                $context = is_string($operation['context'] ?? null) ? trim($operation['context']) : '';
                if ($memory === $target['memory'] && $context === $target['context']) {
                    $violations[] = "memory operation {$index} does not change its target";
                }
            }
        }

        return array_values(array_unique($violations));
    }

    /**
     * @param list<array<string, mixed>> $operations
     *
     * @return list<array<string, mixed>>
     */
    public static function canonicalize(array $operations): array
    {
        $canonical = [];
        foreach ($operations as $operation) {
            $kind        = (string) $operation['operation'];
            $evidenceIds = $operation['evidenceUpdateIds'];
            sort($evidenceIds, \SORT_NUMERIC);

            if ($kind === 'append') {
                $normalized = [
                    'operation'         => 'append',
                    'participantKey'    => trim((string) $operation['participantKey']),
                    'memory'            => trim((string) $operation['memory']),
                    'quote'             => trim((string) $operation['quote']),
                    'context'           => trim((string) $operation['context']),
                    'evidenceUpdateIds' => array_values($evidenceIds),
                ];
                if (array_key_exists('confidencePermille', $operation)) {
                    $normalized['confidencePermille'] = $operation['confidencePermille'];
                }
                $canonical[] = $normalized;

                continue;
            }

            if ($kind === 'update') {
                $normalized = [
                    'operation'         => 'update',
                    'memoryId'          => trim((string) $operation['memoryId']),
                    'memory'            => trim((string) $operation['memory']),
                    'quote'             => trim((string) $operation['quote']),
                    'context'           => trim((string) $operation['context']),
                    'evidenceUpdateIds' => array_values($evidenceIds),
                ];
                if (array_key_exists('confidencePermille', $operation)) {
                    $normalized['confidencePermille'] = $operation['confidencePermille'];
                }
                $canonical[] = $normalized;

                continue;
            }

            $canonical[] = [
                'operation'         => 'forget',
                'memoryId'          => trim((string) $operation['memoryId']),
                'quote'             => trim((string) $operation['quote']),
                'reason'            => trim((string) $operation['reason']),
                'evidenceUpdateIds' => array_values($evidenceIds),
            ];
        }

        return $canonical;
    }

    /** @param list<string> $violations */
    private static function boundedStringViolation(
        array &$violations,
        mixed $value,
        int $maximumBytes,
        string $violation,
    ): void {
        if (!is_string($value) || trim($value) === '' || strlen($value) > $maximumBytes) {
            $violations[] = $violation;
        }
    }

    private static function factKey(string $participantKey, string $memory): string
    {
        return $participantKey . "\0" . mb_strtolower(trim($memory));
    }

    /**
     * @param list<array<string, mixed>> $referenced
     * @param string                     $quote
     */
    private static function quoteOccursIn(string $quote, array $referenced): bool
    {
        foreach ($referenced as $item) {
            if (self::valueContainsQuote($item['payload'] ?? null, $quote)) {
                return true;
            }
        }

        return false;
    }

    private static function valueContainsQuote(mixed $value, string $quote): bool
    {
        if (is_string($value)) {
            return str_contains($value, $quote);
        }
        if (!is_array($value)) {
            return false;
        }
        foreach ($value as $child) {
            if (self::valueContainsQuote($child, $quote)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array<string, mixed>> $referenced
     * @param string                     $participantKey
     */
    private static function participantOccursIn(string $participantKey, array $referenced): bool
    {
        $participants = [];
        foreach ($referenced as $item) {
            self::collectParticipants($item['payload'] ?? null, $participants);
        }

        return isset($participants[$participantKey]);
    }

    /**
     * A quote author is derived only from the sanitized Telegram message
     * envelope that contains the quote. Group membership, a chat reference in
     * another item, or another participant's cited message grants no authority
     * to mutate the target participant's durable memory.
     *
     * @param list<array<string, mixed>> $referenced
     * @param string                     $quote
     * @param string                     $participantKey
     */
    private static function quoteIsAuthoredBy(
        string $quote,
        string $participantKey,
        array $referenced,
    ): bool {
        foreach ($referenced as $item) {
            if (self::payloadContainsAuthoredQuote(
                $item['payload'] ?? null,
                $quote,
                $participantKey,
            )) {
                return true;
            }
        }

        return false;
    }

    private static function payloadContainsAuthoredQuote(
        mixed $value,
        string $quote,
        string $participantKey,
    ): bool {
        if (!is_array($value)) {
            return false;
        }

        $authorKind = $value['authorKind'] ?? null;
        if ($authorKind === 'user') {
            $author = $value['participantReference'] ?? null;
        } elseif ($authorKind === 'channel') {
            $author = $value['chatReference'] ?? null;
        } else {
            $author = null;
        }
        if (is_string($author) && hash_equals($participantKey, $author)) {
            foreach (['text', 'caption'] as $field) {
                if (is_string($value[$field] ?? null) && str_contains($value[$field], $quote)) {
                    return true;
                }
            }
        }

        foreach ($value as $child) {
            if (self::payloadContainsAuthoredQuote($child, $quote, $participantKey)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, true> $participants */
    private static function collectParticipants(mixed $value, array &$participants): void
    {
        if (!is_array($value)) {
            return;
        }

        foreach (['participantReference', 'chatReference'] as $field) {
            $reference = $value[$field] ?? null;
            if (is_string($reference)
                && preg_match('/\A(?:telegram_user:[1-9]\d*|telegram_chat:-?[1-9]\d*)\z/D', $reference) === 1
            ) {
                $participants[$reference] = true;
            }
        }

        foreach ($value as $child) {
            self::collectParticipants($child, $participants);
        }
    }
}
