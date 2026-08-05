<?php

declare(strict_types=1);

namespace Tests\Bot;

use function Async\delay;

use Bot\Bot\DurableTelegramBot;
use Phenogram\Bindings\ApiInterface;
use Phenogram\Bindings\Types\Interfaces\UpdateInterface;
use Phenogram\Bindings\Types\Update;
use PHPUnit\Framework\TestCase;

use Psr\Log\NullLogger;
use RuntimeException;
use Throwable;

final class DurableTelegramBotTest extends TestCase
{
    public function testNextOffsetIsNotPolledUntilEveryHandlerCompletes(): void
    {
        $api                    = $this->createMock(ApiInterface::class);
        $firstHandlerCompleted  = false;
        $secondHandlerCompleted = false;
        $poll                   = 0;
        $bot                    = null;

        $api
            ->expects(self::exactly(2))
            ->method('getUpdates')
            ->willReturnCallback(
                static function (?int $offset) use (
                    &$poll,
                    &$bot,
                    &$firstHandlerCompleted,
                    &$secondHandlerCompleted,
                ): array {
                    ++$poll;

                    if ($poll === 1) {
                        self::assertSame(1, $offset);

                        return [new Update(updateId: 10)];
                    }

                    self::assertTrue($firstHandlerCompleted);
                    self::assertTrue($secondHandlerCompleted);
                    self::assertSame(11, $offset);
                    $bot->stop();

                    return [];
                },
            );

        $bot = new DurableTelegramBot('token', $api, new NullLogger());
        $bot->addHandler(static function () use (&$firstHandlerCompleted): void {
            delay(5);
            $firstHandlerCompleted = true;
        });
        $bot->addHandler(static function () use (&$secondHandlerCompleted): void {
            delay(15);
            $secondHandlerCompleted = true;
        });

        $bot->run(timeout: 0, poolingErrorTimeout: 0.0);
    }

    public function testFailedUpdateIsRetriedBeforeLaterUpdateAndOffsetDoesNotSkipIt(): void
    {
        $api            = $this->createMock(ApiInterface::class);
        $poll           = 0;
        $attempts       = [];
        $handled        = [];
        $reportedErrors = [];
        $bot            = null;

        $api
            ->expects(self::exactly(3))
            ->method('getUpdates')
            ->willReturnCallback(
                static function (?int $offset) use (&$poll, &$bot): array {
                    ++$poll;

                    return match ($poll) {
                        1 => self::firstBatch($offset),
                        2 => self::retryBatch($offset),
                        3 => self::stopAfterAcceptedBatch($bot, $offset),
                    };
                },
            );

        $bot               = new DurableTelegramBot('token', $api, new NullLogger());
        $bot->errorHandler = static function (Throwable $error) use (&$reportedErrors): void {
            $reportedErrors[] = $error;
        };
        $bot->addHandler(
            static function (UpdateInterface $update) use (&$attempts, &$handled): void {
                $attempts[$update->updateId] = ($attempts[$update->updateId] ?? 0) + 1;

                if ($update->updateId === 20 && $attempts[$update->updateId] === 1) {
                    throw new RuntimeException('Temporal handoff failed');
                }

                $handled[] = $update->updateId;
            },
        );

        $bot->run(timeout: 0, poolingErrorTimeout: 0.0);

        self::assertSame([20 => 2, 21 => 1], $attempts);
        self::assertSame([20, 21], $handled);
        self::assertCount(1, $reportedErrors);
        self::assertSame(
            'Error while handling update; it will be retried: Temporal handoff failed',
            $reportedErrors[0]->getMessage(),
        );
    }

    public function testUpdatesAreHandledInAscendingIdOrder(): void
    {
        $api     = $this->createMock(ApiInterface::class);
        $poll    = 0;
        $handled = [];
        $bot     = null;

        $api
            ->expects(self::exactly(2))
            ->method('getUpdates')
            ->willReturnCallback(
                static function (?int $offset) use (&$poll, &$bot): array {
                    ++$poll;

                    if ($poll === 1) {
                        self::assertSame(1, $offset);

                        return [
                            new Update(updateId: 32),
                            new Update(updateId: 30),
                            new Update(updateId: 31),
                        ];
                    }

                    self::assertSame(33, $offset);
                    $bot->stop();

                    return [];
                },
            );

        $bot = new DurableTelegramBot('token', $api, new NullLogger());
        $bot->addHandler(
            static function (UpdateInterface $update) use (&$handled): void {
                $handled[] = $update->updateId;
            },
        );

        $bot->run(timeout: 0, poolingErrorTimeout: 0.0);

        self::assertSame([30, 31, 32], $handled);
    }

    /**
     * @param ?int $offset
     *
     * @return list<Update>
     */
    private static function firstBatch(?int $offset): array
    {
        self::assertSame(1, $offset);

        return [
            new Update(updateId: 20),
            new Update(updateId: 21),
        ];
    }

    /**
     * @param ?int $offset
     *
     * @return list<Update>
     */
    private static function retryBatch(?int $offset): array
    {
        self::assertSame(20, $offset);

        return [
            new Update(updateId: 20),
            new Update(updateId: 21),
        ];
    }

    /**
     * @param DurableTelegramBot $bot
     * @param ?int               $offset
     *
     * @return list<Update>
     */
    private static function stopAfterAcceptedBatch(DurableTelegramBot $bot, ?int $offset): array
    {
        self::assertSame(22, $offset);
        $bot->stop();

        return [];
    }
}
