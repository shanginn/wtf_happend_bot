<?php

declare(strict_types=1);

namespace Tests\Tools;

use Bot\Llm\Tools\Decision\RespondDecision;
use Tests\TestCase;

class RespondDecisionTest extends TestCase
{
    public function testShouldRespondTrue(): void
    {
        $decision = new RespondDecision(
            shouldRespond: true,
            overview: 'User mentioned the bot by name',
        );

        self::assertTrue($decision->shouldRespond);
        self::assertSame('User mentioned the bot by name', $decision->overview);
    }

    public function testShouldRespondFalse(): void
    {
        $decision = new RespondDecision(
            shouldRespond: false,
            overview: 'Regular conversation between users',
        );

        self::assertFalse($decision->shouldRespond);
    }

    public function testToolName(): void
    {
        self::assertSame('respond_decision', RespondDecision::getName());
    }

    public function testToolDescription(): void
    {
        $desc = RespondDecision::getDescription();
        self::assertStringContainsString('respond', $desc);
    }
}
