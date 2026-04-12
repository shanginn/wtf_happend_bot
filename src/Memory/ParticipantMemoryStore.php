<?php

declare(strict_types=1);

namespace Bot\Memory;

use Bot\Entity\ParticipantMemory;
use Bot\Llm\Tools\Memory\RecallMemory;
use Bot\Llm\Tools\Memory\SaveMemory;
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
                $memory->participantLabel,
                $memory->memory,
                $memory->quote,
                $memory->context,
                date('Y-m-d', $memory->updatedAt),
            );

            $lines[] = $line;
        }

        return implode("\n", $lines);
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
