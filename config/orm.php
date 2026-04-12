<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Cycle\Database;
use Cycle\Database\Config;
use Cycle\Schema\Generator\Migrations\GenerateMigrations;
use Spiral\Core\Container;
use Spiral\Tokenizer\ClassLocator;
use Symfony\Component\Finder\Finder;
use Cycle\Schema;
use Cycle\Annotated;
use Cycle\Annotated\Locator\TokenizerEmbeddingLocator;
use Cycle\Annotated\Locator\TokenizerEntityLocator;
use Cycle\ORM;
use Cycle\Migrations;

$dbHost = getenv('DB_HOST') ?? 'db';
$dbPort = getenv('DB_PORT') ?? '5432';
$dbName = getenv('DB_DATABASE') ?? throw new InvalidArgumentException('DB_DATABASE is not set');
$dbUser = getenv('DB_USERNAME') ?? throw new InvalidArgumentException('DB_USERNAME is not set');
$dbPassword = getenv('DB_PASSWORD') ?? throw new InvalidArgumentException('DB_PASSWORD is not set');


$dbal = new Database\DatabaseManager(
    new Config\DatabaseConfig([
        'default' => 'default',
        'databases' => [
            'default' => ['connection' => 'postgres']
        ],
        'connections' => [
            'postgres' => new Config\PostgresDriverConfig(
                connection: new Config\Postgres\TcpConnectionConfig(
                    database: $dbName,
                    host: $dbHost,
                    port: (int)$dbPort,
                    user: $dbUser,
                    password: $dbPassword
                ),
                queryCache: true,
            ),
        ]
    ])
);

$config = new Migrations\Config\MigrationConfig([
    'directory' => __DIR__ . '/../migrations/', // where to store migrations
    'table'     => 'migrations',                // database table to store migration status
    'safe'      => true                         // When set to true no confirmation will be requested on migration run.
]);

$migrator = new Migrations\Migrator($config, $dbal, new Migrations\FileRepository($config));

// Init migration table
$migrator->configure();

$migrate = function () use ($migrator) {
    while(($migrated = $migrator->run()) !== null) {
        $status = match($migrated->getState()->getStatus()) {
            Migrations\State::STATUS_UNDEFINED => 'undefined',
            Migrations\State::STATUS_PENDING => 'pending',
            Migrations\State::STATUS_EXECUTED => 'executed',
            default => 'unknown',
        };

        echo "{$migrated->getState()->getName()} migrated to {$status}\n";
    }
};

if (getenv('SKIP_MIGRATIONS') !== 'true') {
    $migrate();
}

$finder = (new Finder())->files()->in([__DIR__ . '/../src/Entity']);
$classLocator = new ClassLocator($finder);

$embeddingLocator = new TokenizerEmbeddingLocator($classLocator);
$entityLocator = new TokenizerEntityLocator($classLocator);

$skipMigrations = getenv('SKIP_MIGRATIONS') === 'true';

$generators = [
    new Schema\Generator\ResetTables(),
    new Annotated\Embeddings($embeddingLocator),
    new Annotated\Entities($entityLocator),
    new Annotated\TableInheritance(),
    new Annotated\MergeColumns(),
    new Schema\Generator\GenerateRelations(),
    new Schema\Generator\GenerateModifiers(),
    new Schema\Generator\ValidateEntities(),
    new Schema\Generator\RenderTables(),
    new Schema\Generator\RenderRelations(),
    new Schema\Generator\RenderModifiers(),
    new Schema\Generator\ForeignKeys(),
    new Annotated\MergeIndexes(),
];

if (!$skipMigrations) {
    $generators[] = new GenerateMigrations($migrator->getRepository(), $migrator->getConfig());
}

$generators[] = new Schema\Generator\GenerateTypecast();

$schema = (new Schema\Compiler())->compile(new Schema\Registry($dbal), $generators);

$container = new Container();

$orm = new ORM\ORM(new ORM\Factory($dbal, factory: $container), new ORM\Schema($schema));

if (getenv('SKIP_MIGRATIONS') !== 'true') {
    $migrate();
}

return [$container, $orm];
