<?php

declare(strict_types=1);

namespace Bot\Llm\Runtime;

use Bot\Entity\RuntimeSkill;
use Closure;
use Cycle\Database\DatabaseInterface;
use Cycle\ORM\ORM;
use Cycle\ORM\ORMInterface;

/**
 * Serializes chat-scoped capability mutations across independent topic workflows.
 */
final readonly class RuntimeCapabilityMutationLock
{
    private const string POSTGRES_LOCK_SQL = <<<'SQL'
        SELECT pg_advisory_xact_lock(?)
        SQL;

    public function __construct(
        private ORMInterface $orm,
        private ?DatabaseInterface $databaseOverride = null,
    ) {}

    /**
     * @template TResult
     *
     * @param Closure(): TResult $mutation
     * @param int                $chatId
     *
     * @return TResult
     */
    public function synchronized(int $chatId, Closure $mutation): mixed
    {
        $database = $this->database();
        if ($database === null || strcasecmp($database->getType(), 'Postgres') !== 0) {
            return $mutation();
        }

        return $database->transaction(
            static function (DatabaseInterface $database) use ($chatId, $mutation): mixed {
                $database->execute(
                    self::POSTGRES_LOCK_SQL,
                    [$chatId],
                );

                return $mutation();
            },
        );
    }

    private function database(): ?DatabaseInterface
    {
        if ($this->databaseOverride !== null) {
            return $this->databaseOverride;
        }

        // Production uses Cycle's concrete ORM. The fallback keeps isolated
        // repository unit tests and non-Postgres development adapters portable.
        if (!$this->orm instanceof ORM) {
            return null;
        }

        return $this->orm->getSource(RuntimeSkill::class)->getDatabase();
    }
}
