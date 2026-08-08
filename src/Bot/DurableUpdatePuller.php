<?php

declare(strict_types=1);

namespace Bot\Bot;

use Async\AsyncCancellation;

use function Async\await_all;

use Async\Coroutine;

use function Async\current_coroutine;
use function Async\delay;

use Async\OperationCanceledException;

use function Async\protect;

use Async\Scope;

use function Async\timeout;

use LogicException;

use Phenogram\Bindings\Types\Interfaces\UpdateInterface;
use Phenogram\Bindings\Types\UpdateType;
use Phenogram\Framework\Exception\PhenogramException;
use Phenogram\Framework\Exception\UpdatePullingException;
use Phenogram\Framework\TelegramBot;
use Phenogram\Framework\UpdatePuller\BotStatus;
use Throwable;
use ValueError;

/**
 * Sequential long poller whose Telegram offset is the durable-acceptance cursor.
 */
final class DurableUpdatePuller
{
    private BotStatus $status = BotStatus::stopped;

    private ?Scope $updatesScope = null;

    private ?Coroutine $pollingCoroutine = null;

    private float $stopTimeout = 5.0;

    private ?float $stopDeadlineMilliseconds = null;

    public function __construct(
        private readonly TelegramBot $bot,
        private readonly float $pollingErrorTimeout = 5.0,
    ) {}

    /**
     * @param list<UpdateType>|null $allowedUpdates
     * @param ?int                  $offset
     * @param ?int                  $limit
     * @param ?int                  $timeout
     */
    public function run(
        ?int $offset = null,
        ?int $limit = 100,
        ?int $timeout = null,
        ?array $allowedUpdates = null,
    ): void {
        $offset ??= 1;
        $timeout ??= 15;

        if ($this->status !== BotStatus::stopping) {
            $this->status = BotStatus::started;
        }

        $this->pollingCoroutine = current_coroutine();

        try {
            $this->bot->logger->info(sprintf(
                'Starting durable bot polling with offset %d, limit %d, timeout %d',
                $offset,
                $limit,
                $timeout,
            ));

            if ($this->status === BotStatus::started) {
                $this->updatesScope = Scope::inherit()->asNotSafely();
                $this->updatesScope->setExceptionHandler(
                    function (Scope $scope, Coroutine $task, Throwable $exception): void {
                        if (!$exception instanceof AsyncCancellation) {
                            $this->reportUpdateError($exception);
                        }
                    },
                );

                $this->poll($offset, $limit, $timeout, $allowedUpdates);
            }
        } catch (AsyncCancellation $exception) {
            if ($this->status !== BotStatus::stopping) {
                throw $exception;
            }
        } finally {
            try {
                protect($this->drainUpdates(...));
            } finally {
                $this->pollingCoroutine         = null;
                $this->stopDeadlineMilliseconds = null;
                $this->status                   = BotStatus::stopped;
            }
        }
    }

    public function stop(float $timeout = 5.0): void
    {
        if (!is_finite($timeout) || $timeout <= 0 || $timeout > PHP_INT_MAX / 1000) {
            throw new ValueError('The stop timeout must be greater than zero');
        }

        $deadline = $this->monotonicMilliseconds() + ($timeout * 1000);

        if ($this->status === BotStatus::stopping) {
            $this->stopTimeout              = min($this->stopTimeout, $timeout);
            $this->stopDeadlineMilliseconds = min(
                $this->stopDeadlineMilliseconds ?? $deadline,
                $deadline,
            );

            return;
        }

        $this->stopTimeout              = $timeout;
        $this->stopDeadlineMilliseconds = $deadline;
        $this->status                   = BotStatus::stopping;

        $this->bot->logger->info('Stopping durable bot polling');

        $currentCoroutine = current_coroutine();
        if (
            $this->pollingCoroutine !== null
            && $this->pollingCoroutine->getId() !== $currentCoroutine->getId()
            && !$this->pollingCoroutine->isCompleted()
        ) {
            $this->pollingCoroutine->cancel(new AsyncCancellation('Durable bot stop requested'));
        }
    }

    /**
     * @param list<UpdateType>|null $allowedUpdates
     * @param int                   $offset
     * @param ?int                  $limit
     * @param int                   $timeout
     */
    private function poll(
        int $offset,
        ?int $limit,
        int $timeout,
        ?array $allowedUpdates,
    ): void {
        $allowedUpdateValues = $allowedUpdates === null
            ? null
            : array_map(
                static fn (UpdateType $type): string => $type->value,
                $allowedUpdates,
            );

        while ($this->status === BotStatus::started) {
            $this->bot->logger->debug('Polling updates', [
                'offset'         => $offset,
                'limit'          => $limit,
                'allowedUpdates' => $allowedUpdateValues,
                'timeout'        => $timeout,
            ]);

            try {
                $updates = $this->bot->api->getUpdates(
                    offset: $offset,
                    limit: $limit,
                    timeout: $timeout,
                    allowedUpdates: $allowedUpdateValues,
                );
            } catch (AsyncCancellation $exception) {
                throw $exception;
            } catch (Throwable $exception) {
                if ($this->status !== BotStatus::started) {
                    break;
                }

                $this->reportPollingError($exception);
                $this->backOff();

                continue;
            }

            if ($this->status !== BotStatus::started) {
                break;
            }

            usort(
                $updates,
                static fn (UpdateInterface $left, UpdateInterface $right): int => $left->updateId <=> $right->updateId,
            );

            foreach ($updates as $update) {
                if ($this->status !== BotStatus::started) {
                    break 2;
                }

                if ($update->updateId < $offset) {
                    continue;
                }

                // Point at the current update without confirming it. Telegram
                // confirms it only when the next offset is greater than its id.
                $offset = $update->updateId;

                try {
                    $this->dispatchUpdate($update);
                } catch (AsyncCancellation $exception) {
                    throw $exception;
                } catch (Throwable $exception) {
                    $this->reportUpdateError($exception);
                    $this->backOff();

                    continue 2;
                }

                $offset = $update->updateId + 1;
            }
        }
    }

    private function dispatchUpdate(UpdateInterface $update): void
    {
        if ($this->updatesScope === null) {
            throw new LogicException('Update handler scope is not available');
        }

        [, $errors] = await_all($this->bot->handleUpdate($update, $this->updatesScope));

        foreach ($errors as $error) {
            if ($error instanceof AsyncCancellation) {
                throw $error;
            }

            throw $error;
        }
    }

    private function drainUpdates(): void
    {
        if ($this->updatesScope === null) {
            return;
        }

        $this->bot->logger->info(
            "Waiting for all requests to complete for a maximum of {$this->stopTimeout} seconds, then cancelling them.",
        );

        $completed = false;
        while (($remainingMilliseconds = $this->remainingStopMilliseconds()) > 0) {
            try {
                $this->updatesScope->awaitCompletion(
                    timeout(min(10, $remainingMilliseconds)),
                );
                $completed = true;

                break;
            } catch (OperationCanceledException) {
            }
        }

        if (!$completed) {
            $this->updatesScope->cancel(new AsyncCancellation('Durable bot stop timeout'));

            try {
                $this->updatesScope->awaitAfterCancellation(
                    function (Throwable $exception, Scope $scope): void {
                        if (!$exception instanceof AsyncCancellation) {
                            $this->reportUpdateError($exception);
                        }
                    },
                    timeout($this->secondsToMilliseconds($this->stopTimeout)),
                );
            } catch (OperationCanceledException $exception) {
                $this->bot->logger->error(
                    'Timed out while cancelling update handlers',
                    ['exception' => $exception],
                );
            }
        }

        $this->updatesScope->dispose();
        $this->updatesScope = null;
    }

    private function reportPollingError(Throwable $exception): void
    {
        $message = "Error while polling updates: '{$exception->getMessage()}'.";
        if ($this->pollingErrorTimeout !== 0.0) {
            $message .= " Waiting for {$this->pollingErrorTimeout} seconds until next pull";
        }

        $this->reportError(new UpdatePullingException(
            message: $message,
            previous: $exception,
        ));
    }

    private function reportUpdateError(Throwable $exception): void
    {
        $this->reportError(new PhenogramException(
            message: sprintf(
                'Error while handling update; it will be retried: %s',
                $exception->getMessage(),
            ),
            previous: $exception,
        ));
    }

    private function reportError(Throwable $exception): void
    {
        try {
            ($this->bot->errorHandler)($exception, $this->bot);
        } catch (Throwable $handlerException) {
            $this->bot->logger->error(
                'The bot error handler failed',
                ['exception' => $handlerException],
            );
        }
    }

    private function backOff(): void
    {
        if ($this->pollingErrorTimeout !== 0.0) {
            delay($this->secondsToMilliseconds($this->pollingErrorTimeout));
        }
    }

    private function secondsToMilliseconds(float $seconds): int
    {
        return max(1, (int) ceil($seconds * 1000));
    }

    private function remainingStopMilliseconds(): int
    {
        $this->stopDeadlineMilliseconds ??= $this->monotonicMilliseconds() + ($this->stopTimeout * 1000);

        return max(
            0,
            (int) ceil($this->stopDeadlineMilliseconds - $this->monotonicMilliseconds()),
        );
    }

    private function monotonicMilliseconds(): float
    {
        return hrtime(true) / 1_000_000;
    }
}
