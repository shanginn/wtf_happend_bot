<?php

declare(strict_types=1);

namespace Bot\Space\Tools;

use Bot\AgenticWorkflow\BotToolCatalog;
use Bot\Space\Publication\SpaceCapabilityPublicationTool;
use PiPHP\Agent\Enum\ToolExecutionMode;
use PiPHP\Temporal\Serialization\PiPayloadCodec;

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

    /** @return list<string> */
    public static function baseToolNames(): array
    {
        return array_values(array_map(
            static fn (array $tool): string => (string) $tool['name'],
            self::baseWireDefinitions(),
        ));
    }

    /**
     * Legacy mutable runtime tables are deliberately not exposed to a Space.
     * Live capability publication uses the Space-native immutable release tool
     * appended below. Executable code is disabled in this release.
     *
     * @return list<array<string, mixed>>
     */
    public static function wireDefinitions(): array
    {
        $codec                        = new PiPayloadCodec();
        $publication                  = $codec->toolToWire(SpaceCapabilityPublicationTool::definition());
        $publication['executionMode'] = ToolExecutionMode::Sequential->value;

        return [...self::baseWireDefinitions(), $publication];
    }

    /** @return list<array<string, mixed>> */
    private static function baseWireDefinitions(): array
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
