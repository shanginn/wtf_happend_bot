<?php

declare(strict_types=1);

namespace Tests\Space\Persistence;

use Bot\Entity\SpaceRelease;
use Bot\Space\Persistence\SpaceStore;
use Cycle\Database\DatabaseInterface;
use Cycle\Database\StatementInterface;
use Cycle\ORM\ORMInterface;
use Mockery;
use Tests\TestCase;

final class SpaceStoreBooleanParameterTest extends TestCase
{
    private const string SPACE_ID = 'spc_abcdefghijklmnopqrstuvwxyzabcdefgh';

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function testMaterializeCandidateSkillsEncodesDisabledFlagForRawSql(): void
    {
        $database     = Mockery::mock(DatabaseInterface::class);
        $boundEnabled = null;
        $database->shouldReceive('transaction')
            ->once()
            ->andReturnUsing(static fn (callable $callback): mixed => $callback($database));

        $candidate = Mockery::mock(StatementInterface::class);
        $parent = Mockery::mock(StatementInterface::class);
        $parentSkills = Mockery::mock(StatementInterface::class);
        $versions = Mockery::mock(StatementInterface::class);
        $existing = Mockery::mock(StatementInterface::class);

        $skill = [
            'name'        => 'disabled-skill',
            'description' => 'Disabled skill description',
            'body'        => 'Disabled skill body',
            'enabled'     => false,
        ];
        $skillsDigest = 'sha256:' . hash('sha256', json_encode(
            [$skill],
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
        ));

        $database->shouldReceive('query')->once()->ordered()->andReturn($candidate);
        $candidate->shouldReceive('fetch')->once()->andReturn([
            'id'                => 'candidate-release',
            'parent_release_id' => 'parent-release',
            'status'            => SpaceRelease::STATUS_DRAFT,
            'manifest_json'     => json_encode(['skillsDigest' => $skillsDigest], \JSON_THROW_ON_ERROR),
        ]);
        $database->shouldReceive('query')->once()->ordered()->andReturn($parent);
        $parent->shouldReceive('fetch')->once()->andReturn(['id' => 'parent-release']);
        $database->shouldReceive('query')->once()->ordered()->andReturn($parentSkills);
        $parentSkills->shouldReceive('fetchAll')->once()->andReturn([]);
        $database->shouldReceive('query')->once()->ordered()->andReturn($versions);
        $versions->shouldReceive('fetchAll')->once()->andReturn([]);
        $database->shouldReceive('query')->once()->ordered()->andReturn($existing);
        $existing->shouldReceive('fetchAll')->once()->andReturn([]);
        $database->shouldReceive('execute')
            ->once()
            ->ordered()
            ->withArgs(static function (string $sql, array $parameters) use (&$boundEnabled): bool {
                $boundEnabled = $parameters[9] ?? null;

                return str_contains($sql, 'INSERT INTO space_skill_versions');
            })
            ->andReturn(1);

        (new SpaceStore(Mockery::mock(ORMInterface::class), $database))->materializeCandidateSkills(
            self::SPACE_ID,
            'parent-release',
            'candidate-release',
            [$skill],
            now: 1_725_000_000,
        );

        self::assertSame(0, $boundEnabled);
    }

    public function testCreateSkillVersionEncodesDisabledFlagForRawSql(): void
    {
        $database     = Mockery::mock(DatabaseInterface::class);
        $boundEnabled = null;
        $database->shouldReceive('transaction')
            ->once()
            ->andReturnUsing(static fn (callable $callback): mixed => $callback($database));

        $release = Mockery::mock(StatementInterface::class);
        $existing = Mockery::mock(StatementInterface::class);
        $latestVersion = Mockery::mock(StatementInterface::class);

        $database->shouldReceive('query')->once()->ordered()->andReturn($release);
        $release->shouldReceive('fetch')->once()->andReturn(['status' => SpaceRelease::STATUS_DRAFT]);
        $database->shouldReceive('query')->once()->ordered()->andReturn($existing);
        $existing->shouldReceive('fetch')->once()->andReturn(false);
        $database->shouldReceive('query')->once()->ordered()->andReturn($latestVersion);
        $latestVersion->shouldReceive('fetchColumn')->once()->andReturn(0);
        $database->shouldReceive('execute')
            ->once()
            ->ordered()
            ->withArgs(static function (string $sql, array $parameters) use (&$boundEnabled): bool {
                $boundEnabled = $parameters[9] ?? null;

                return str_contains($sql, 'INSERT INTO space_skill_versions');
            })
            ->andReturn(1);

        $skill = (new SpaceStore(Mockery::mock(ORMInterface::class), $database))->createSkillVersion(
            self::SPACE_ID,
            'candidate-release',
            'disabled-skill',
            'Disabled skill description',
            'Disabled skill body',
            enabled: false,
            now: 1_725_000_000,
        );

        self::assertFalse($skill->enabled);
        self::assertSame(0, $boundEnabled);
    }
}
