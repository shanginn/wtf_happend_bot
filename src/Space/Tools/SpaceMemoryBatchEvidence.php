<?php

declare(strict_types=1);

namespace Bot\Space\Tools;

use LogicException;

/**
 * Extracts the host-authored evidence envelope for exactly one pending
 * Telegram batch. The model sees the same messages, but it cannot add entries
 * to this metadata envelope.
 */
final class SpaceMemoryBatchEvidence
{
    /**
     * @param list<array<string, mixed>> $messages
     * @param int                        $pendingBatchMessageCount
     *
     * @return list<array{updateId: int, participantKey: string, text: string}>
     */
    public static function fromPendingMessages(
        array $messages,
        int $pendingBatchMessageCount,
    ): array {
        $messages = array_values($messages);
        if (
            $pendingBatchMessageCount < 1
            || $pendingBatchMessageCount > count($messages)
        ) {
            throw new LogicException('Pending Space memory evidence state is inconsistent.');
        }

        $batch    = array_slice($messages, -$pendingBatchMessageCount);
        $evidence = [];
        foreach ($batch as $message) {
            if (($message['role'] ?? null) !== 'user') {
                throw new LogicException('Space memory evidence may only come from Telegram-authored messages.');
            }

            $metadata       = $message['metadata'] ?? null;
            $updateId       = is_array($metadata) ? ($metadata['telegramUpdateId'] ?? null) : null;
            $participantKey = is_array($metadata) ? ($metadata['telegramParticipant'] ?? null) : null;
            $text           = is_array($metadata) ? ($metadata['telegramMemoryEvidence'] ?? null) : null;
            if ($text === null) {
                continue;
            }
            if (
                !is_int($updateId)
                || $updateId < 0
                || !is_string($participantKey)
                || preg_match('/^(?:telegram_user:[1-9]\d*|telegram_chat:-?[1-9]\d*)$/', $participantKey) !== 1
                || !is_string($text)
                || trim($text) === ''
            ) {
                throw new LogicException('Host-authored Space memory evidence metadata is invalid.');
            }

            $evidence[] = [
                'updateId'       => $updateId,
                'participantKey' => $participantKey,
                'text'           => $text,
            ];
        }

        return $evidence;
    }
}
