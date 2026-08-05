<?php

declare(strict_types=1);

namespace Bot\Bot;

use Async\AsyncCancellation;

use function Async\await;
use function Async\protect;

use function Async\spawn;

use Closure;
use LogicException;
use Phenogram\Bindings\Types\UpdateType;
use Phenogram\Framework\TelegramBot;

final class DurableTelegramBot extends TelegramBot
{
    private ?Closure $stopDurablePulling = null;

    /**
     * @param list<UpdateType>|null $allowedUpdates
     * @param ?int                  $offset
     * @param ?int                  $limit
     * @param ?int                  $timeout
     * @param float                 $poolingErrorTimeout
     */
    public function run(
        ?int $offset = null,
        ?int $limit = 100,
        ?int $timeout = null,
        ?array $allowedUpdates = null,
        float $poolingErrorTimeout = 5.0,
    ): void {
        $updatePuller = new DurableUpdatePuller($this, $poolingErrorTimeout);

        $this->stopDurablePulling = $updatePuller->stop(...);
        $pulling                  = spawn(fn () => $updatePuller->run(
            offset: $offset,
            limit: $limit,
            timeout: $timeout,
            allowedUpdates: $allowedUpdates,
        ));

        try {
            await($pulling);
        } finally {
            if (!$pulling->isCompleted()) {
                protect(static function () use ($pulling): void {
                    $pulling->cancel(new AsyncCancellation('Durable bot run cancelled'));

                    try {
                        await($pulling);
                    } catch (AsyncCancellation) {
                    }
                });
            }

            $this->stopDurablePulling = null;
        }
    }

    public function stop(float $timeout = 5.0): void
    {
        if ($this->stopDurablePulling === null) {
            throw new LogicException('Pulling is not running');
        }

        ($this->stopDurablePulling)($timeout);
    }
}
