<?php

declare(strict_types=1);

namespace Tests\Space\Persistence;

use Bot\Space\Persistence\SqlBoolean;
use PDO;
use PHPUnit\Framework\TestCase;

final class SqlBooleanTest extends TestCase
{
    public function testEncodedValuesRemainBooleansInSqlite(): void
    {
        $database = new PDO('sqlite::memory:');
        $database->exec('CREATE TABLE flags (enabled boolean NOT NULL)');

        $insert = $database->prepare('INSERT INTO flags (enabled) VALUES (?)');
        $insert->execute([SqlBoolean::encode(false)]);
        $insert->execute([SqlBoolean::encode(true)]);

        self::assertSame(0, SqlBoolean::encode(false));
        self::assertSame(1, SqlBoolean::encode(true));
        self::assertSame(
            [
                ['enabled' => 0, 'storage_type' => 'integer'],
                ['enabled' => 1, 'storage_type' => 'integer'],
            ],
            $database->query(
                'SELECT enabled, typeof(enabled) AS storage_type FROM flags ORDER BY rowid',
            )->fetchAll(PDO::FETCH_ASSOC),
        );
    }
}
