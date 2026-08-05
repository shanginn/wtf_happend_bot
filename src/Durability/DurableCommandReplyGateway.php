<?php

declare(strict_types=1);

namespace Bot\Durability;

use Closure;
use InvalidArgumentException;
use UnexpectedValueException;

/**
 * Executes one Telegram control command and sends its direct reply at most once.
 *
 * A persisted claim with no result is intentionally treated as an ambiguous
 * external outcome. A retry then completes without repeating the Temporal
 * mutation or Telegram send, allowing the durable ingress cursor to advance
 * only after either confirmation or a conservative ambiguity decision.
 */
final readonly class DurableCommandReplyGateway
{
    private const int RESULT_VERSION = 1;

    public function __construct(
        private IdempotencyLedgerInterface $ledger,
    ) {}

    /**
     * @param Closure(): string     $resolveReply
     * @param Closure(string): void $sendReply
     * @param int                   $updateId
     * @param string                $action
     * @param int                   $chatId
     * @param ?int                  $messageThreadId
     * @param int                   $messageId
     */
    public function execute(
        int $updateId,
        string $action,
        int $chatId,
        ?int $messageThreadId,
        int $messageId,
        Closure $resolveReply,
        Closure $sendReply,
    ): void {
        if (preg_match('/^[a-z][a-z0-9_-]*$/D', $action) !== 1) {
            throw new InvalidArgumentException('A durable command action must be a stable lowercase identifier.');
        }

        $identity = self::identity(
            updateId: $updateId,
            action: $action,
            chatId: $chatId,
            messageThreadId: $messageThreadId,
            messageId: $messageId,
        );
        $idempotencyKey = self::commandKey($action, $identity);
        $commandClaim   = $this->ledger->claim($idempotencyKey, $identity);

        if ($commandClaim->result === null) {
            if (!$commandClaim->acquired) {
                return;
            }

            $replyText = $resolveReply();
            if ($replyText === '') {
                throw new UnexpectedValueException('A durable command reply cannot be empty.');
            }

            $this->ledger->complete($commandClaim, [
                'replyText' => $replyText,
                'version'   => self::RESULT_VERSION,
            ]);
        } else {
            $replyText = self::replyText($commandClaim->result);
        }

        $replyClaim = $this->ledger->claim(
            "{$idempotencyKey}:reply",
            "{$identity}:reply",
        );
        if (!$replyClaim->acquired) {
            return;
        }

        $sendReply($replyText);

        $this->ledger->complete($replyClaim, [
            'confirmed' => true,
            'version'   => self::RESULT_VERSION,
        ]);
    }

    private static function identity(
        int $updateId,
        string $action,
        int $chatId,
        ?int $messageThreadId,
        int $messageId,
    ): string {
        $encoded = json_encode([
            'action'          => $action,
            'chatId'          => $chatId,
            'messageId'       => $messageId,
            'messageThreadId' => $messageThreadId,
            'updateId'        => $updateId,
        ], \JSON_THROW_ON_ERROR);

        return 'telegram-control:' . hash('sha256', $encoded);
    }

    private static function commandKey(string $action, string $identity): string
    {
        return "telegram-control:{$action}:" . hash('sha256', $identity);
    }

    /**
     * @param array<string, mixed> $result
     */
    private static function replyText(array $result): string
    {
        if (($result['version'] ?? null) !== self::RESULT_VERSION) {
            throw new UnexpectedValueException('Stored durable command result has an unsupported version.');
        }

        $replyText = $result['replyText'] ?? null;
        if (!is_string($replyText) || $replyText === '') {
            throw new UnexpectedValueException('Stored durable command result has no reply text.');
        }

        return $replyText;
    }
}
