<?php

declare(strict_types=1);

namespace Bot\Memory;

use Bot\Entity\ParticipantMemory;
use Bot\Entity\ParticipantMemory\ParticipantMemoryRepository;
use Cycle\ORM\ORMInterface;

class ParticipantMemoryStore
{
    private const string CANONICAL_PARTICIPANT_REFERENCE_PATTERN
        = '/^(?:telegram_user:[1-9]\d*|telegram_chat:-?[1-9]\d*)$/';

    public function __construct(
        private readonly ORMInterface $orm,
    ) {}

    public function save(
        int $chatId,
        string $userIdentifier,
        string $memory,
        string $quote,
        string $context,
    ): string {
        $participantLabel = self::normalizeRequiredText($userIdentifier);
        $computedMemory   = self::normalizeRequiredText($memory);
        $quote            = self::normalizeRequiredText($quote);
        $context          = self::normalizeRequiredText($context);

        if ($participantLabel === '') {
            return 'Memory not saved: participant reference is required.';
        }

        if (!self::isCanonicalParticipantReference($participantLabel)) {
            return self::invalidParticipantReferenceMessage('saved');
        }

        if ($computedMemory === '') {
            return 'Memory not saved: computed memory is required.';
        }

        if ($quote === '') {
            return 'Memory not saved: quote is required.';
        }

        if ($context === '') {
            return 'Memory not saved: context is required.';
        }

        /** @var ParticipantMemoryRepository $repo */
        $repo = $this->orm->getRepository(ParticipantMemory::class);

        $participantKey = self::normalizeParticipantKey($participantLabel);
        $existing       = $repo->findExact($chatId, $participantKey, $computedMemory);
        $now            = time();

        if ($existing === null) {
            $repo->save(new ParticipantMemory(
                chatId: $chatId,
                participantKey: $participantKey,
                participantLabel: $participantLabel,
                memory: $computedMemory,
                quote: $quote,
                context: $context,
                createdAt: $now,
                updatedAt: $now,
            ));

            return sprintf(
                'Memory saved for %s: %s',
                $participantLabel,
                $computedMemory,
            );
        }

        if (
            $existing->participantLabel === $participantLabel
            && $existing->quote === $quote
            && $existing->context === $context
        ) {
            return sprintf(
                'Memory unchanged for %s: %s',
                $existing->participantLabel,
                $existing->memory,
            );
        }

        $existing->participantLabel = $participantLabel;
        $existing->quote            = $quote;
        $existing->context          = $context;
        $existing->updatedAt        = $now;

        $repo->save($existing);

        return sprintf(
            'Memory updated for %s: %s',
            $existing->participantLabel,
            $existing->memory,
        );
    }

    public function recall(
        int $chatId,
        ?string $userIdentifier = null,
        ?string $query = null,
        int $limit = 10,
    ): string {
        $participantLabel = self::normalizeOptionalText($userIdentifier);
        $participantKey   = $participantLabel === null ? null : self::normalizeParticipantKey($participantLabel);
        $needle           = self::normalizeSearchNeedle($query);
        $limit            = max(1, min($limit, 20));

        /** @var ParticipantMemoryRepository $repo */
        $repo = $this->orm->getRepository(ParticipantMemory::class);

        $records = $participantKey === null
            ? $repo->findByChatId($chatId)
            : $repo->findByParticipantKey($chatId, $participantKey);

        $records = array_values(array_filter(
            $records,
            static fn (ParticipantMemory $memory): bool => self::matches($memory, $needle),
        ));

        if ($records === []) {
            return self::buildNotFoundMessage($participantLabel, $needle);
        }

        usort($records, static fn (ParticipantMemory $left, ParticipantMemory $right): int => [$right->updatedAt, $right->id]
            <=> [$left->updatedAt, $left->id]);

        return self::formatMemories(array_slice($records, 0, $limit), $participantLabel);
    }

    public function update(
        int $chatId,
        string $memory,
        string $quote,
        string $context,
        ?int $memoryId = null,
        ?string $userIdentifier = null,
        ?string $currentMemory = null,
        ?string $query = null,
    ): string {
        if ($memoryId !== null && $memoryId <= 0) {
            return 'Memory not updated: memory_id must be a positive integer.';
        }

        $computedMemory   = self::normalizeRequiredText($memory);
        $quote            = self::normalizeRequiredText($quote);
        $context          = self::normalizeRequiredText($context);
        $participantLabel = self::normalizeOptionalText($userIdentifier);
        $currentMemory    = self::normalizeOptionalText($currentMemory);
        $needle           = self::normalizeSearchNeedle($query);

        if (
            $memoryId === null
            && $participantLabel !== null
            && !self::isCanonicalParticipantReference($participantLabel)
        ) {
            return self::invalidParticipantReferenceMessage('updated');
        }

        if ($computedMemory === '') {
            return 'Memory not updated: corrected memory is required.';
        }

        if ($quote === '') {
            return 'Memory not updated: quote is required.';
        }

        if ($context === '') {
            return 'Memory not updated: context is required.';
        }

        if ($memoryId === null && $currentMemory === null && $needle === null) {
            return 'Memory not updated: pass memory_id, current_memory, or a narrow query selector.';
        }

        /** @var ParticipantMemoryRepository $repo */
        $repo = $this->orm->getRepository(ParticipantMemory::class);

        $target = $this->findSingleTarget(
            repo: $repo,
            chatId: $chatId,
            memoryId: $memoryId,
            participantLabel: $participantLabel,
            currentMemory: $currentMemory,
            needle: $needle,
            operation: 'updated',
        );

        if (!$target instanceof ParticipantMemory) {
            return $target;
        }

        if (
            $target->memory === $computedMemory
            && $target->quote === $quote
            && $target->context === $context
        ) {
            return sprintf(
                'Memory unchanged for %s (%s): %s',
                $target->participantLabel,
                self::formatMemoryId($target),
                $target->memory,
            );
        }

        $target->memory    = $computedMemory;
        $target->quote     = $quote;
        $target->context   = $context;
        $target->updatedAt = time();

        $repo->save($target);

        return sprintf(
            'Memory updated for %s (%s): %s',
            $target->participantLabel,
            self::formatMemoryId($target),
            $target->memory,
        );
    }

    public function forget(
        int $chatId,
        ?int $memoryId = null,
        ?string $userIdentifier = null,
        ?string $query = null,
        bool $forgetAllForParticipant = false,
    ): string {
        if ($memoryId !== null && $memoryId <= 0) {
            return 'Memory not forgotten: memory_id must be a positive integer.';
        }

        $participantLabel = self::normalizeOptionalText($userIdentifier);
        $needle           = self::normalizeSearchNeedle($query);

        if (
            $memoryId === null
            && $participantLabel !== null
            && !self::isCanonicalParticipantReference($participantLabel)
        ) {
            return self::invalidParticipantReferenceMessage('forgotten');
        }

        /** @var ParticipantMemoryRepository $repo */
        $repo = $this->orm->getRepository(ParticipantMemory::class);

        if ($memoryId !== null) {
            $target = $this->findSingleTarget(
                repo: $repo,
                chatId: $chatId,
                memoryId: $memoryId,
                participantLabel: $participantLabel,
                currentMemory: null,
                needle: $needle,
                operation: 'forgotten',
            );

            if (!$target instanceof ParticipantMemory) {
                return $target;
            }

            $summary = sprintf(
                'Memory forgotten for %s (%s): %s',
                $target->participantLabel,
                self::formatMemoryId($target),
                $target->memory,
            );
            $repo->delete($target);

            return $summary;
        }

        if ($forgetAllForParticipant) {
            if ($participantLabel === null) {
                return 'Memory not forgotten: participant reference is required when forget_all_for_participant is true.';
            }

            $records = $this->findCandidates($repo, $chatId, $participantLabel);
            $records = self::filterByNeedle($records, $needle);

            if ($records === []) {
                return self::buildNotFoundMessage($participantLabel, $needle);
            }

            foreach ($records as $memory) {
                $repo->delete($memory);
            }

            return sprintf(
                '%d memories forgotten for %s.',
                count($records),
                $participantLabel,
            );
        }

        if ($needle === null) {
            return 'Memory not forgotten: pass memory_id, a narrow query, or set forget_all_for_participant for an explicit broad deletion.';
        }

        $target = $this->findSingleTarget(
            repo: $repo,
            chatId: $chatId,
            memoryId: null,
            participantLabel: $participantLabel,
            currentMemory: null,
            needle: $needle,
            operation: 'forgotten',
        );

        if (!$target instanceof ParticipantMemory) {
            return $target;
        }

        $summary = sprintf(
            'Memory forgotten for %s (%s): %s',
            $target->participantLabel,
            self::formatMemoryId($target),
            $target->memory,
        );
        $repo->delete($target);

        return $summary;
    }

    /**
     * @param array<ParticipantMemory> $records
     * @param ?string                  $needle
     *
     * @return array<ParticipantMemory>
     */
    private static function filterByNeedle(array $records, ?string $needle): array
    {
        return array_values(array_filter(
            $records,
            static fn (ParticipantMemory $memory): bool => self::matches($memory, $needle),
        ));
    }

    private static function matches(ParticipantMemory $memory, ?string $needle): bool
    {
        if ($needle === null) {
            return true;
        }

        $haystack = self::searchableText($memory);

        foreach (preg_split('/\s+/', $needle) ?: [] as $token) {
            if ($token !== '' && !str_contains($haystack, $token)) {
                return false;
            }
        }

        return true;
    }

    private static function searchableText(ParticipantMemory $memory): string
    {
        return mb_strtolower(implode("\n", array_filter([
            $memory->participantKey,
            $memory->participantLabel,
            $memory->memory,
            $memory->quote,
            $memory->context,
        ])));
    }

    private static function formatMemories(array $records, ?string $participantLabel): string
    {
        $lines = [
            $participantLabel === null
                ? 'Relevant memories:'
                : 'Memories for ' . $participantLabel . ':',
        ];

        foreach ($records as $memory) {
            $line = sprintf(
                '- %s | memory: %s | quote: %s | context: %s | updated: %s',
                self::formatMemoryReference($memory),
                $memory->memory,
                $memory->quote,
                $memory->context,
                date('Y-m-d', $memory->updatedAt),
            );

            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<ParticipantMemory> $records
     */
    private static function formatMemoryReferences(array $records): string
    {
        $references = array_map(
            static fn (ParticipantMemory $memory): string => self::formatMemoryReference($memory) . ': ' . $memory->memory,
            array_slice($records, 0, 5),
        );

        if (count($records) > 5) {
            $references[] = sprintf('and %d more', count($records) - 5);
        }

        return implode('; ', $references);
    }

    private static function formatMemoryReference(ParticipantMemory $memory): string
    {
        return sprintf('%s %s', self::formatMemoryId($memory), $memory->participantLabel);
    }

    private static function formatMemoryId(ParticipantMemory $memory): string
    {
        return isset($memory->id) ? '#' . $memory->id : '#unknown';
    }

    private static function buildNotFoundMessage(
        ?string $participantLabel,
        ?string $needle,
    ): string {
        $parts = [];

        if ($participantLabel !== null) {
            $parts[] = 'for ' . $participantLabel;
        }

        if ($needle !== null) {
            $parts[] = 'matching "' . $needle . '"';
        }

        if ($parts === []) {
            return 'No memories found.';
        }

        return 'No memories found ' . implode(' ', $parts) . '.';
    }

    private static function normalizeParticipantKey(string $value): string
    {
        $normalized = mb_strtolower(trim($value));

        if ($normalized === '') {
            return '';
        }

        if (preg_match('/^telegram_(?:user|chat):-?\d+$/', $normalized) === 1) {
            return $normalized;
        }

        if (preg_match('/^-?\d+$/', $normalized) === 1) {
            return 'telegram_user:' . ltrim($normalized, '+');
        }

        if (preg_match('/^user_(-?\d+)$/', $normalized, $matches) === 1) {
            return 'telegram_user:' . $matches[1];
        }

        if (preg_match('/^chat_(-?\d+)$/', $normalized, $matches) === 1) {
            return 'telegram_chat:' . $matches[1];
        }

        return '@' . ltrim(
            preg_replace('/\s+/', '_', $normalized) ?? $normalized,
            '@',
        );
    }

    private static function isCanonicalParticipantReference(string $value): bool
    {
        return preg_match(self::CANONICAL_PARTICIPANT_REFERENCE_PATTERN, $value) === 1;
    }

    private static function invalidParticipantReferenceMessage(string $operation): string
    {
        return sprintf(
            'Memory not %s: participant reference must be telegram_user:<positive id> '
            . 'or telegram_chat:<non-zero signed id>.',
            $operation,
        );
    }

    private static function normalizeRequiredText(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    }

    private static function normalizeOptionalText(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = self::normalizeRequiredText($value);

        return $normalized === '' ? null : $normalized;
    }

    private static function normalizeSearchNeedle(?string $value): ?string
    {
        $normalized = self::normalizeOptionalText($value);

        return $normalized === null ? null : mb_strtolower($normalized);
    }

    private function findSingleTarget(
        object $repo,
        int $chatId,
        ?int $memoryId,
        ?string $participantLabel,
        ?string $currentMemory,
        ?string $needle,
        string $operation,
    ): ParticipantMemory|string {
        if ($memoryId !== null) {
            $memory = $repo->findById($chatId, $memoryId);

            if (!$memory instanceof ParticipantMemory) {
                return sprintf('Memory not %s: no memory found with id #%d.', $operation, $memoryId);
            }

            if (
                $participantLabel !== null
                && $memory->participantKey !== self::normalizeParticipantKey($participantLabel)
            ) {
                return sprintf(
                    'Memory not %s: memory #%d does not belong to %s.',
                    $operation,
                    $memoryId,
                    $participantLabel,
                );
            }

            if ($currentMemory !== null && self::normalizeRequiredText($memory->memory) !== $currentMemory) {
                return sprintf(
                    'Memory not %s: memory #%d does not match current_memory.',
                    $operation,
                    $memoryId,
                );
            }

            if ($needle !== null && !self::matches($memory, $needle)) {
                return sprintf(
                    'Memory not %s: memory #%d does not match query.',
                    $operation,
                    $memoryId,
                );
            }

            return $memory;
        }

        $records = $this->findCandidates($repo, $chatId, $participantLabel);

        if ($currentMemory !== null) {
            $records = array_values(array_filter(
                $records,
                static fn (ParticipantMemory $memory): bool => self::normalizeRequiredText($memory->memory) === $currentMemory,
            ));
        }

        $records = self::filterByNeedle($records, $needle);

        if ($records === []) {
            return self::buildNotFoundMessage($participantLabel, $needle ?? $currentMemory);
        }

        if (count($records) > 1) {
            return sprintf(
                'Memory not %s: selector matched multiple memories. Recall memories first and retry with memory_id. Matches: %s',
                $operation,
                self::formatMemoryReferences($records),
            );
        }

        return $records[0];
    }

    /**
     * @param object  $repo
     * @param int     $chatId
     * @param ?string $participantLabel
     *
     * @return array<ParticipantMemory>
     */
    private function findCandidates(object $repo, int $chatId, ?string $participantLabel): array
    {
        if ($participantLabel === null) {
            return $repo->findByChatId($chatId);
        }

        return $repo->findByParticipantKey($chatId, self::normalizeParticipantKey($participantLabel));
    }
}
