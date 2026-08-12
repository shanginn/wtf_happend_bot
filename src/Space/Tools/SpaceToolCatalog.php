<?php

declare(strict_types=1);

namespace Bot\Space\Tools;

use Bot\AgenticWorkflow\BotToolCatalog;

final class SpaceToolCatalog
{
    /** @return list<string> */
    public static function toolNames(): array
    {
        return array_values(array_map(
            static fn (array $tool): string => (string) $tool['name'],
            self::wireDefinitions(),
        ));
    }

    /**
     * Legacy live self-mutation tools are deliberately not exposed to a Space.
     * Prompt, personality, skill, and memory changes enter through the nightly
     * governed release loop. Executable code is disabled in this release.
     *
     * @return list<array<string, mixed>>
     */
    public static function wireDefinitions(): array
    {
        $excluded = array_fill_keys([
            'list_runtime_capabilities',
            'upsert_runtime_skill',
            'upsert_runtime_tool',
            'set_runtime_capability_status',
            'run_runtime_tool',
            'run_space_capsule',
        ], true);

        return array_values(array_filter(
            BotToolCatalog::wireDefinitions(),
            static fn (array $tool): bool => !isset($excluded[$tool['name'] ?? '']),
        ));
    }
}
