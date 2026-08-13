<?php

declare(strict_types=1);

namespace Tests\Space\Command;

use Bot\Space\Command\SpaceCommandActivity;
use Bot\Space\Command\SpaceCommandActivityInterface;
use Spiral\Attributes\AttributeReader;
use Temporal\Internal\Declaration\Reader\ActivityReader;
use Tests\TestCase;

final class SpaceCommandActivityRegistrationTest extends TestCase
{
    public function testAgentWorkerRegistersStableConcreteCommandActivity(): void
    {
        $declarations = file_get_contents(dirname(__DIR__, 3) . '/config/declarations.php');
        self::assertIsString($declarations);
        self::assertStringContainsString(
            'SpaceCommandActivity::class => fn (): SpaceCommandActivity',
            $declarations,
        );
        self::assertStringContainsString(
            'new SpaceCommandActivity(' . "\n" . '                $replyTypingModelGateway,',
            $declarations,
        );

        $activities = (new ActivityReader(new AttributeReader()))->fromClass(
            SpaceCommandActivity::class,
        );
        self::assertCount(1, $activities);
        self::assertSame(SpaceCommandActivityInterface::EXECUTE, $activities[0]->getID());
        self::assertSame(
            SpaceCommandActivity::class,
            $activities[0]->getHandler()->getDeclaringClass()->getName(),
        );
    }
}
