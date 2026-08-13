<?php

declare(strict_types=1);

namespace Tests\AgenticWorkflow;

use Tests\TestCase;

final class ReplyTypingConfigurationTest extends TestCase
{
    public function testOnlyReplyProducingAgentAndCommandModelCallsUseTypingDecorator(): void
    {
        $declarations = file_get_contents(dirname(__DIR__, 2) . '/config/declarations.php');
        self::assertIsString($declarations);

        self::assertStringContainsString(
            '$replyTypingModelGateway = new ReplyTypingModelGateway(',
            $declarations,
        );
        self::assertStringContainsString(
            'transport: new DeepSeekReasoningReplayTransport(),',
            $declarations,
        );
        self::assertStringContainsString('models: $replyTypingModelGateway,', $declarations);
        self::assertStringContainsString(
            'new SpaceCommandActivity(' . "\n" . '                $replyTypingModelGateway,',
            $declarations,
        );
        self::assertMatchesRegularExpression(
            '/DreamActivities\(\s*database:.*?models: \$modelGateway,/s',
            $declarations,
        );
        self::assertDoesNotMatchRegularExpression(
            '/DreamActivities\(\s*database:.*?models: \$replyTypingModelGateway,/s',
            $declarations,
        );
        self::assertStringContainsString(
            'new SpaceCapabilityPublicationTool(',
            $declarations,
        );
        self::assertStringContainsString(
            'new SpaceCapabilityPublisher($database($orm))',
            $declarations,
        );
        self::assertMatchesRegularExpression(
            '/IdempotentToolExecutionGateway\(\s*inner: new SpaceCapabilityPublicationRejectionGateway\(/s',
            $declarations,
        );
    }
}
