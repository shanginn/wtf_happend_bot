<?php

declare(strict_types=1);

namespace Tests\Space\Tools;

use Bot\Space\Tools\SpaceToolCatalog;
use Tests\TestCase;

final class SpaceToolCatalogTest extends TestCase
{
    public function testLiveSelfMutationToolsAreNotExposedOrExecutable(): void
    {
        $names = SpaceToolCatalog::toolNames();

        self::assertContains('save_memory', $names);
        self::assertNotContains('run_space_capsule', $names);
        self::assertNotContains('list_runtime_capabilities', $names);
        self::assertNotContains('upsert_runtime_skill', $names);
        self::assertNotContains('upsert_runtime_tool', $names);
        self::assertNotContains('set_runtime_capability_status', $names);
        self::assertNotContains('run_runtime_tool', $names);
    }

    public function testWireDefinitionsCannotExposeCapsuleExecution(): void
    {
        self::assertNotContains(
            'run_space_capsule',
            array_column(SpaceToolCatalog::wireDefinitions(), 'name'),
        );
    }
}
