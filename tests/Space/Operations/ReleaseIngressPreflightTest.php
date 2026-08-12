<?php

declare(strict_types=1);

namespace Tests\Space\Operations;

use Bot\Space\Operations\ReleaseIngressPreflight;
use Cycle\Database\DatabaseInterface;
use Cycle\Database\StatementInterface;
use Mockery;
use Phenogram\Bindings\ApiInterface;
use Phenogram\Bindings\Factories\UserFactory;
use RuntimeException;
use Tests\TestCase;

final class ReleaseIngressPreflightTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testDatabaseAndTelegramCredentialsAreCheckedWithoutPolling(): void
    {
        $statement = Mockery::mock(StatementInterface::class);
        $statement->shouldReceive('fetch')->once()->andReturn(['ready' => 1]);
        $database = Mockery::mock(DatabaseInterface::class);
        $database->shouldReceive('query')->once()->with('SELECT 1 AS ready')->andReturn($statement);
        $telegram = Mockery::mock(ApiInterface::class);
        $telegram->shouldReceive('getMe')->once()->andReturn(UserFactory::make(id: 42, isBot: true));

        self::assertSame([
            'database'      => 'ready',
            'telegramBotId' => 42,
        ], (new ReleaseIngressPreflight($database, $telegram))->run());
    }

    public function testNonBotTelegramCredentialsFailClosed(): void
    {
        $statement = Mockery::mock(StatementInterface::class);
        $statement->shouldReceive('fetch')->once()->andReturn(['ready' => 1]);
        $database = Mockery::mock(DatabaseInterface::class);
        $database->shouldReceive('query')->once()->andReturn($statement);
        $telegram = Mockery::mock(ApiInterface::class);
        $telegram->shouldReceive('getMe')->once()->andReturn(UserFactory::make(id: 42, isBot: false));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('credentials do not identify a bot');

        (new ReleaseIngressPreflight($database, $telegram))->run();
    }
}
