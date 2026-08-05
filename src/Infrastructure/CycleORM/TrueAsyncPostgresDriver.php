<?php

declare(strict_types=1);

namespace Bot\Infrastructure\CycleORM;

use Async\Coroutine;
use Cycle\Database\Driver\PDOInterface;
use Cycle\Database\Driver\PDOStatementInterface;
use Cycle\Database\Driver\Postgres\PostgresDriver;
use Cycle\Database\Driver\Statement;
use Cycle\Database\Exception\StatementException;
use Cycle\Database\Query\Interpolator;
use Cycle\Database\StatementInterface;
use PDO;
use PDOStatement;
use Throwable;

/**
 * Makes Cycle's mutable transaction state safe for a shared TrueAsync PDO pool.
 */
final class TrueAsyncPostgresDriver extends PostgresDriver
{
    /** @var array<int, int> */
    private array $transactionLevels = [];

    private ?Coroutine $connectionTask = null;

    /**
     * Cycle initializes PDO lazily. Share the first connection task so concurrent
     * cold-start queries create one native pool rather than competing pools.
     */
    public function connect(): void
    {
        if ($this->isConnected()) {
            return;
        }

        if (!extension_loaded('true_async') || $this->coroutineId() === 0) {
            parent::connect();

            return;
        }

        $task = $this->connectionTask ??= \Async\spawn(
            fn (): PDO|PDOInterface => $this->createPDO(),
        );

        try {
            $this->pdo ??= \Async\await($task);
        } finally {
            if ($this->connectionTask === $task) {
                $this->connectionTask = null;
            }
        }
    }

    public function getTransactionLevel(): int
    {
        return $this->transactionLevels[$this->coroutineId()] ?? 0;
    }

    public function beginTransaction(?string $isolationLevel = null): bool
    {
        $level = $this->getTransactionLevel() + 1;
        $this->setTransactionLevel($level);

        if ($level === 1) {
            $this->logger?->info('Begin transaction');

            try {
                $ok = $this->getPDO()->beginTransaction();
                if ($isolationLevel !== null) {
                    $this->setIsolationLevel($isolationLevel);
                }

                return $ok;
            } catch (Throwable $e) {
                $e = $this->mapException($e, 'BEGIN TRANSACTION');

                if (
                    $e instanceof StatementException\ConnectionException
                    && $this->config->reconnect
                ) {
                    $this->disconnect();
                    $this->setTransactionLevel(1);

                    try {
                        return $this->getPDO()->beginTransaction();
                    } catch (Throwable $e) {
                        $this->setTransactionLevel(0);

                        throw $this->mapException($e, 'BEGIN TRANSACTION');
                    }
                }

                $this->setTransactionLevel(0);

                throw $e;
            }
        }

        $this->createSavepoint($level);

        return true;
    }

    public function commitTransaction(): bool
    {
        $level = $this->getTransactionLevel();

        if (!$this->getPDO()->inTransaction()) {
            $this->logger?->warning(
                sprintf('Attempt to commit a transaction that has not yet begun. Transaction level: %d', $level),
            );
            $this->setTransactionLevel(0);

            return $level !== 0;
        }

        --$level;
        $this->setTransactionLevel($level);

        if ($level === 0) {
            $this->logger?->info('Commit transaction');

            try {
                return $this->getPDO()->commit();
            } catch (Throwable $e) {
                throw $this->mapException($e, 'COMMIT TRANSACTION');
            }
        }

        $this->releaseSavepoint($level + 1);

        return true;
    }

    public function rollbackTransaction(): bool
    {
        $level = $this->getTransactionLevel();

        if (!$this->getPDO()->inTransaction()) {
            $this->logger?->warning(
                sprintf('Attempt to rollback a transaction that has not yet begun. Transaction level: %d', $level),
            );
            $this->setTransactionLevel(0);

            return false;
        }

        --$level;
        $this->setTransactionLevel($level);

        if ($level === 0) {
            $this->logger?->info('Rollback transaction');

            try {
                return $this->getPDO()->rollBack();
            } catch (Throwable $e) {
                throw $this->mapException($e, 'ROLLBACK TRANSACTION');
            }
        }

        $this->rollbackSavepoint($level + 1);

        return true;
    }

    public function disconnect(): void
    {
        parent::disconnect();
        $this->transactionLevels = [];
    }

    /**
     * Roll back and forget state left by a failed or cancelled activity.
     */
    public function finalizeCurrentCoroutine(): void
    {
        $id = $this->coroutineId();

        try {
            if ($this->isConnected() && $this->getPDO()->inTransaction()) {
                $this->logger?->warning('Rolling back a transaction left open by an async operation');
                $this->getPDO()->rollBack();
            }
        } catch (Throwable $e) {
            $this->logger?->error('Failed to clean up async transaction: ' . $e->getMessage());
        } finally {
            unset($this->transactionLevels[$id]);
        }
    }

    protected function prepare(string $query): PDOStatement|PDOStatementInterface
    {
        return $this->getPDO()->prepare($query);
    }

    protected function statement(string $query, iterable $parameters = [], bool $retry = true): StatementInterface
    {
        $queryStart = microtime(true);

        try {
            $statement = $this->bindParameters($this->prepare($query), $parameters);
            $statement->execute();

            return new Statement($statement);
        } catch (Throwable $e) {
            $e = $this->mapException($e, Interpolator::interpolate($query, $parameters));

            if (
                $retry
                && $this->getTransactionLevel() === 0
                && $this->config->reconnect
                && $e instanceof StatementException\ConnectionException
            ) {
                $this->disconnect();

                return $this->statement($query, $parameters, false);
            }

            throw $e;
        } finally {
            if ($this->logger !== null) {
                $queryString = $this->config->options['logInterpolatedQueries']
                    ? Interpolator::interpolate($query, $parameters, $this->config->options)
                    : $query;

                $contextParameters = $this->config->options['logQueryParameters']
                    ? $parameters
                    : [];

                $context = $this->defineLoggerContext(
                    $queryStart,
                    $statement ?? null,
                    $contextParameters,
                );

                if (isset($e)) {
                    $this->logger->error($queryString, $context);
                    $this->logger->alert($e->getMessage());
                } else {
                    $this->logger->info($queryString, $context);
                }
            }
        }
    }

    private function coroutineId(): int
    {
        if (!function_exists('\Async\current_coroutine')) {
            return 0;
        }

        try {
            return \Async\current_coroutine()->getId();
        } catch (Throwable) {
            return 0;
        }
    }

    private function setTransactionLevel(int $level): void
    {
        $id = $this->coroutineId();

        if ($level === 0) {
            unset($this->transactionLevels[$id]);

            return;
        }

        $this->transactionLevels[$id] = $level;
    }
}
