<?php

declare(strict_types=1);

namespace Tests\Space\Persistence;

use Bot\Entity\Space;
use Bot\Entity\Space\SpaceReleaseRepository;
use Bot\Entity\Space\SpaceRepository;
use Bot\Entity\SpaceBinding;
use Bot\Entity\SpaceDreamRun;
use Bot\Entity\SpaceEvaluationRun;
use Bot\Entity\SpaceMemoryVersion;
use Bot\Entity\SpacePromotionEvent;
use Bot\Entity\SpaceRelease;
use Bot\Entity\SpaceSandboxJob;
use Bot\Entity\SpaceSkillVersion;
use Bot\Entity\SpaceUpgradeProposal;
use Bot\Infrastructure\CycleORM\CycleOrmScope;
use Bot\Space\Persistence\SpaceStore;
use Cycle\Annotated;
use Cycle\Annotated\Locator\TokenizerEmbeddingLocator;
use Cycle\Annotated\Locator\TokenizerEntityLocator;
use Cycle\Database\Config\DatabaseConfig;
use Cycle\Database\Config\SQLiteDriverConfig;
use Cycle\Database\DatabaseInterface;
use Cycle\Database\DatabaseManager;
use Cycle\ORM;
use Cycle\ORM\EntityProxyInterface;
use Cycle\Schema;
use Spiral\Tokenizer\ClassLocator;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

final class SpaceOrmProxyHydrationTest extends TestCase
{
    public function testAllSpaceEntitiesSupportTheProductionProxyMapper(): void
    {
        [$scope] = self::bootProductionStyleOrm();
        $orm     = $scope->current()->orm;

        foreach (self::spaceEntityClasses() as $offset => $class) {
            $id     = 'proxy-entity-' . $offset;
            $entity = $orm->make($class, ['id' => $id]);

            self::assertInstanceOf($class, $entity);
            self::assertInstanceOf(EntityProxyInterface::class, $entity);
            self::assertSame($id, $entity->id);
        }

        $scope->finalizeCurrent();
    }

    public function testCurrentReleaseHydratesThroughTheRepositoryAfterTheHeapIsCleared(): void
    {
        [$scope, $database] = self::bootProductionStyleOrm();
        $context            = $scope->current();
        $spaceId            = 'spc_' . str_repeat('1', 40);
        $releaseId          = 'release-proxy-regression';

        /** @var SpaceRepository $spaces */
        $spaces = $context->orm->getRepository(Space::class);
        $space  = new Space(id: $spaceId, createdAt: 100, updatedAt: 100);
        $spaces->save($space);

        /** @var SpaceReleaseRepository $releases */
        $releases = $context->orm->getRepository(SpaceRelease::class);
        $releases->create(new SpaceRelease(
            id: $releaseId,
            spaceId: $spaceId,
            parentReleaseId: null,
            sourceProposalId: null,
            sequence: 1,
            status: SpaceRelease::STATUS_ACTIVE,
            releaseDigest: 'sha256:' . str_repeat('a', 64),
            model: 'test-model',
            prompt: 'Hydrate me through the real repository.',
            createdAt: 101,
            activatedAt: 102,
        ));

        $space->activeReleaseId  = $releaseId;
        $space->releaseGeneration = 1;
        $space->updatedAt         = 102;
        $spaces->save($space);
        $context->clean();

        $release = (new SpaceStore($context->orm, $database))->currentRelease($spaceId);

        self::assertInstanceOf(SpaceRelease::class, $release);
        self::assertInstanceOf(EntityProxyInterface::class, $release);
        self::assertSame($releaseId, $release->id);
        self::assertSame('Hydrate me through the real repository.', $release->prompt);

        $scope->finalizeCurrent();
    }

    /**
     * This mirrors config/orm.php: annotations are compiled into the regular
     * Cycle Mapper schema, and the generated tables are materialized in an
     * isolated SQLite database for a real persist/fetch round trip.
     *
     * @return array{CycleOrmScope, DatabaseInterface}
     */
    private static function bootProductionStyleOrm(): array
    {
        $databaseManager = new DatabaseManager(new DatabaseConfig([
            'default' => 'default',
            'databases' => [
                'default' => ['connection' => 'sqlite'],
            ],
            'connections' => [
                'sqlite' => new SQLiteDriverConfig(),
            ],
        ]));
        $classLocator = new ClassLocator(
            (new Finder())->files()->in([dirname(__DIR__, 3) . '/src/Entity']),
        );
        $renderTables = new Schema\Generator\RenderTables();
        $generators   = [
            new Schema\Generator\ResetTables(),
            new Annotated\Embeddings(new TokenizerEmbeddingLocator($classLocator)),
            new Annotated\Entities(new TokenizerEntityLocator($classLocator)),
            new Annotated\TableInheritance(),
            new Annotated\MergeColumns(),
            new Schema\Generator\GenerateRelations(),
            new Schema\Generator\GenerateModifiers(),
            new Schema\Generator\ValidateEntities(),
            $renderTables,
            new Schema\Generator\RenderRelations(),
            new Schema\Generator\RenderModifiers(),
            new Schema\Generator\ForeignKeys(),
            new Annotated\MergeIndexes(),
            new Schema\Generator\GenerateTypecast(),
        ];
        $schema = (new Schema\Compiler())->compile(
            new Schema\Registry($databaseManager),
            $generators,
        );
        $renderTables->getReflector()->run();

        return [
            new CycleOrmScope($databaseManager, new ORM\Schema($schema)),
            $databaseManager->database(),
        ];
    }

    /** @return list<class-string> */
    private static function spaceEntityClasses(): array
    {
        return [
            Space::class,
            SpaceBinding::class,
            SpaceRelease::class,
            SpaceSkillVersion::class,
            SpaceMemoryVersion::class,
            SpaceDreamRun::class,
            SpaceUpgradeProposal::class,
            SpaceEvaluationRun::class,
            SpacePromotionEvent::class,
            SpaceSandboxJob::class,
        ];
    }
}
