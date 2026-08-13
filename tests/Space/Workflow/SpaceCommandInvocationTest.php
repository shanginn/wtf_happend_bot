<?php

declare(strict_types=1);

namespace Tests\Space\Workflow;

use Bot\Space\Workflow\SpaceCommandInvocation;
use Bot\Telegram\Update;
use Phenogram\Bindings\Factories\ChatFactory;
use Phenogram\Bindings\Factories\MessageEntityFactory;
use Phenogram\Bindings\Factories\MessageFactory;
use Phenogram\Bindings\Factories\UpdateFactory;
use Tests\TestCase;

final class SpaceCommandInvocationTest extends TestCase
{
    public function testExtractsOnlyLeadingTelegramCommandAndRawArguments(): void
    {
        $invocation = SpaceCommandInvocation::fromUpdate(self::update(
            '/DiManNews   про утро',
            commandLength: 10,
        ));

        self::assertNotNull($invocation);
        self::assertSame('dimannews', $invocation->name);
        self::assertSame('про утро', $invocation->argumentText);
        self::assertNull($invocation->targetUsername);
    }

    public function testRetainsAddressedCommandForFailClosedRouting(): void
    {
        $invocation = SpaceCommandInvocation::fromUpdate(self::update(
            '/dimannews@OtherBot payload',
            commandLength: 19,
        ));

        self::assertNotNull($invocation);
        self::assertSame('dimannews', $invocation->name);
        self::assertSame('otherbot', $invocation->targetUsername);
        self::assertSame('payload', $invocation->argumentText);
        self::assertFalse($invocation->isForBot('wtf_happend_bot'));
        self::assertTrue($invocation->isForBot('OTHERBOT'));
    }

    public function testIgnoresCommandEntityThatDoesNotStartTheMessage(): void
    {
        self::assertNull(SpaceCommandInvocation::fromUpdate(self::update(
            'hey /dimannews',
            commandLength: 10,
            commandOffset: 4,
        )));
    }

    public function testPlainSlashTextWithoutTelegramEntityIsNotACommand(): void
    {
        self::assertNull(SpaceCommandInvocation::fromUpdate(self::update(
            '/dimannews',
            commandLength: null,
        )));
    }

    private static function update(
        string $text,
        ?int $commandLength,
        int $commandOffset = 0,
    ): Update {
        $update = UpdateFactory::make(
            updateId: 1001,
            message: MessageFactory::make(
                chat: ChatFactory::make(id: 7001, type: 'supergroup'),
                text: $text,
                entities: $commandLength === null ? null : [MessageEntityFactory::make(
                    type: 'bot_command',
                    offset: $commandOffset,
                    length: $commandLength,
                )],
            ),
        );
        self::assertInstanceOf(Update::class, $update);

        return $update;
    }
}
