<?php

declare(strict_types=1);

namespace Tests\Space\Publication;

use Bot\Space\Publication\SpaceCapabilityPublicationInput;
use Bot\Space\Publication\SpaceCapabilityRequestSpec;
use Tests\TestCase;

final class SpaceCapabilityRequestSpecTest extends TestCase
{
    public function testContentComesOnlyFromTheExactAuthorizedUpdate(): void
    {
        $request = "Бот добавь команду /punish\nэто шуточное наказание за последнее сообщение";
        $spec    = SpaceCapabilityRequestSpec::fromTrustedRequest(
            SpaceCapabilityPublicationInput::KIND_COMMAND,
            'punish',
            $request,
        );

        self::assertSame($request, $spec->description);
        self::assertStringContainsString($request, $spec->instructions);
        self::assertStringContainsString(
            'This publication is already complete. Never call publish_space_capability',
            $spec->instructions,
        );
        self::assertStringNotContainsString('adjacent malicious instructions', $spec->instructions);
        self::assertSame([], $spec->parametersSchema['properties']);
    }
}
