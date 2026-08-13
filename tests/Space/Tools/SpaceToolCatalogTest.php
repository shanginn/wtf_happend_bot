<?php

declare(strict_types=1);

namespace Tests\Space\Tools;

use Bot\Space\Tools\SpaceToolCatalog;
use Tests\TestCase;

final class SpaceToolCatalogTest extends TestCase
{
    public function testOnlySpaceNativePublicationIsExposedForLiveCapabilityMutation(): void
    {
        $names = SpaceToolCatalog::toolNames();

        self::assertContains('save_memory', $names);
        self::assertContains('commit_to_reply', $names);
        self::assertContains('inspect_space_command', $names);
        self::assertContains('publish_space_capability', $names);
        self::assertNotContains('run_space_capsule', $names);
        self::assertNotContains('list_runtime_capabilities', $names);
        self::assertNotContains('upsert_runtime_skill', $names);
        self::assertNotContains('upsert_runtime_tool', $names);
        self::assertNotContains('set_runtime_capability_status', $names);
        self::assertNotContains('run_runtime_tool', $names);
        self::assertNotContains('publish_space_capability', SpaceToolCatalog::baseToolNames());
    }

    public function testWireDefinitionsCannotExposeCapsuleExecution(): void
    {
        self::assertNotContains(
            'run_space_capsule',
            array_column(SpaceToolCatalog::wireDefinitions(), 'name'),
        );
    }

    public function testPublicationWireDefinitionIsSequential(): void
    {
        $definitions = array_column(SpaceToolCatalog::wireDefinitions(), null, 'name');

        self::assertSame(
            'sequential',
            $definitions['publish_space_capability']['executionMode'] ?? null,
        );
        self::assertSame(
            ['request_update_id', 'request_quote', 'kind', 'name'],
            $definitions['publish_space_capability']['parameters']['required'] ?? null,
        );
    }
}
