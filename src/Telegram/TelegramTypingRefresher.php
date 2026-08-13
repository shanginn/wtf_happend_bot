<?php

declare(strict_types=1);

namespace Bot\Telegram;

use Async\AsyncCancellation;

use function Async\delay;
use function Async\spawn;

use Closure;
use InvalidArgumentException;
use Phenogram\Bindings\ApiInterface;
use Throwable;

/**
 * Keeps Telegram's short-lived typing action alive while one model request is
 * in flight. Pulses are deliberately ephemeral and never enter Temporal
 * history. A failed typing request is observability-only and cannot fail the
 * model request or delay its result.
 */
final readonly class TelegramTypingRefresher
{
    public const int REFRESH_INTERVAL_MILLISECONDS = 4_000;

    public function __construct(
        private ApiInterface $telegram,
        private int $refreshIntervalMilliseconds = self::REFRESH_INTERVAL_MILLISECONDS,
    ) {
        if ($refreshIntervalMilliseconds < 1) {
            throw new InvalidArgumentException('Typing refresh interval must be positive.');
        }
    }

    /**
     * @template TResult
     *
     * @param Closure(): TResult $operation
     * @param int                $chatId
     * @param ?int               $topicId
     *
     * @return TResult
     */
    public function whileTyping(int $chatId, ?int $topicId, Closure $operation): mixed
    {
        $refreshing = spawn(function () use ($chatId, $topicId): void {
            while (true) {
                $this->pulse($chatId, $topicId);
                delay($this->refreshIntervalMilliseconds);
            }
        });

        // TrueAsync spawn is queued. One zero-duration scheduler yield starts
        // the immediate pulse and resumes this coroutine as soon as Telegram
        // I/O suspends; the model request never waits for that I/O to finish.
        delay(0);

        try {
            return $operation();
        } finally {
            if (!$refreshing->isCompleted()) {
                $refreshing->cancel(new AsyncCancellation('Model completion finished'));
            }
            // Never join here. Cancellation is normally delivered to Telegram
            // I/O or the refresh delay, but even an uncancellable hung request
            // must not hold the actual model result or reply hostage.
        }
    }

    private function pulse(int $chatId, ?int $topicId): void
    {
        try {
            $this->telegram->sendChatAction(
                chatId: $chatId,
                action: 'typing',
                messageThreadId: $topicId,
            );
        } catch (AsyncCancellation $cancellation) {
            throw $cancellation;
        } catch (Throwable) {
            // Typing is best-effort UI state. Model completion and the actual
            // reply must remain independent from its failures.
        }
    }
}
