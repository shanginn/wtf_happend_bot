<?php

declare(strict_types=1);

namespace Bot\Memory;

use Bot\Entity\ParticipantMemory;
use Bot\Llm\Tools\Memory\ForgetMemory;
use Bot\Llm\Tools\Memory\RecallMemory;
use Bot\Llm\Tools\Memory\SaveMemory;
use Bot\Llm\Tools\Memory\UpdateMemory;
use Cycle\ORM\ORMInterface;

class ParticipantMemoryStore
{
    public function __construct(
        private readonly ORMInterface $orm,
    ) {}

    public function save(int $chatId, SaveMemory $memory): string
    {
        $participantLabel = self::normalizeRequiredText($memory->userIdentifier);
        $computedMemory = self::normalizeRequiredText($memory->memory);
        $quote = self::normalizeRequiredText($memory->quote);
        $context = self::normalizeRequiredText($memory->context);

        if ($participantLabel === '') {
            return 'Memory not saved: participant reference is required.';
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

        /** @var \Bot\Entity\ParticipantMemory\ParticipantMemoryRepository $repo */
        $repo = $this->orm->getRepository(ParticipantMemory::class);

        $participantKey = self::normalizeParticipantKey($participantLabel);
        $existing = $repo->findExact($chatId, $participantKey, $computedMemory);
        $now = time();

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
        $existing->quote = $quote;
        $existing->context = $context;
        $existing->updatedAt = $now;

        $repo->save($existing);

        return sprintf(
            'Memory updated for %s: %s',
            $existing->participantLabel,
            $existing->memory,
        );
    }

    public function recall(int $chatId, RecallMemory $query): string
    {
        $participantLabel = self::normalizeOptionalText($query->userIdentifier);
        $participantKey = $participantLabel === null ? null : self::normalizeParticipantKey($participantLabel);
        $needle = self::normalizeSearchNeedle($query->query);
        $limit = max(1, min($query->limit, 20));

        /** @var \Bot\Entity\ParticipantMemory\ParticipantMemoryRepository $repo */
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

    public function update(int $chatId, UpdateMemory $request): string
    {
        if ($request->memoryId !== null && $request->memoryId <= 0) {
            return 'Memory not updated: memory_id must be a positive integer.';
        }

        $computedMemory = self::normalizeRequiredText($request->memory);
        $quote = self::normalizeRequiredText($request->quote);
        $context = self::normalizeRequiredText($request->context);
        $participantLabel = self::normalizeOptionalText($request->userIdentifier);
        $currentMemory = self::normalizeOptionalText($request->currentMemory);
        $needle = self::normalizeSearchNeedle($request->query);

        if ($computedMemory === '') {
            return 'Memory not updated: corrected memory is required.';
        }

        if ($quote === '') {
            return 'Memory not updated: quote is required.';
        }

        if ($context === '') {
            return 'Memory not updated: context is required.';
        }

        if ($request->memoryId === null && $currentMemory === null && $needle === null) {
            return 'Memory not updated: pass memory_id, current_memory, or a narrow query selector.';
        }

        /** @var \Bot\Entity\ParticipantMemory\ParticipantMemoryRepository $repo */
        $repo = $this->orm->getRepository(ParticipantMemory::class);

        $target = $this->findSingleTarget(
            repo: $repo,
            chatId: $chatId,
            memoryId: $request->memoryId,
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

        $target->memory = $computedMemory;
        $target->quote = $quote;
        $target->context = $context;
        $target->updatedAt = time();

        $repo->save($target);

        return sprintf(
            'Memory updated for %s (%s): %s',
            $target->participantLabel,
            self::formatMemoryId($target),
            $target->memory,
        );
    }

    public function forget(int $chatId, ForgetMemory $request): string
    {
        if ($request->memoryId !== null && $request->memoryId <= 0) {
            return 'Memory not forgotten: memory_id must be a positive integer.';
        }

        $participantLabel = self::normalizeOptionalText($request->userIdentifier);
        $needle = self::normalizeSearchNeedle($request->query);

        /** @var \Bot\Entity\ParticipantMemory\ParticipantMemoryRepository $repo */
        $repo = $this->orm->getRepository(ParticipantMemory::class);

        if ($request->memoryId !== null) {
            $target = $this->findSingleTarget(
                repo: $repo,
                chatId: $chatId,
                memoryId: $request->memoryId,
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

        if ($request->forgetAllForParticipant) {
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
     * @return array<ParticipantMemory>
     */
    private function findCandidates(object $repo, int $chatId, ?string $participantLabel): array
    {
        if ($participantLabel === null) {
            return $repo->findByChatId($chatId);
        }

        return $repo->findByParticipantKey($chatId, self::normalizeParticipantKey($participantLabel));
    }

    /**
     * @param array<ParticipantMemory> $records
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

        if (preg_match('/^-?\d+$/', $normalized) === 1) {
            return 'user_' . ltrim($normalized, '+');
        }

        if (str_starts_with($normalized, '@')) {
            return $normalized;
        }

        return preg_replace('/\s+/', '_', $normalized) ?? $normalized;
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
}
