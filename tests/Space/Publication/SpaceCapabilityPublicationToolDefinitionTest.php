<?php

declare(strict_types=1);

namespace Tests\Space\Publication;

use Bot\Space\Publication\SpaceCapabilityPublicationTool;
use PiPHP\AI\Tool\ToolValidator;
use Tests\TestCase;

final class SpaceCapabilityPublicationToolDefinitionTest extends TestCase
{
    public function testDefinitionIsPortableAndAcceptsThePunishPublicationShape(): void
    {
        $tool = SpaceCapabilityPublicationTool::definition();
        (new ToolValidator())->assertSupportedSchema($tool);

        self::assertSame('publish_space_capability', $tool->name);
        self::assertSame(
            ['request_update_id', 'request_quote', 'kind', 'name'],
            $tool->parameters['required'],
        );
        self::assertArrayNotHasKey('description', $tool->parameters['properties']);
        self::assertArrayNotHasKey('instructions', $tool->parameters['properties']);
        self::assertArrayNotHasKey('parameters_schema', $tool->parameters['properties']);
        self::assertSame(
            ['skill', 'command'],
            $tool->parameters['properties']['kind']['enum'],
        );
    }
}
