<?php

declare(strict_types=1);

namespace Tests\AgenticWorkflow;

use Bot\AgenticWorkflow\AgenticWorkflowHandler;
use Bot\Telegram\Update;
use Phenogram\Bindings\Factories\ChatFactory;
use Phenogram\Bindings\Factories\MessageFactory;
use Phenogram\Bindings\Factories\UpdateFactory;
use Tests\TestCase;

class AgenticWorkflowHandlerTest extends TestCase
{
    public function testGenerateWorkflowIdIgnoresTopic(): void
    {
        $generalUpdate = UpdateFactory::make(
            message: MessageFactory::make(
                chat: ChatFactory::make(id: -100123456, type: 'supergroup'),
            ),
        );
        $topicUpdate = UpdateFactory::make(
            message: MessageFactory::make(
                chat: ChatFactory::make(id: -100123456, type: 'supergroup'),
                messageThreadId: 789,
            ),
        );

        assert($generalUpdate instanceof Update);
        assert($topicUpdate instanceof Update);

        self::assertSame('Chat -100123456 [Root]', AgenticWorkflowHandler::generateWorkflowId($generalUpdate));
        self::assertSame(
            AgenticWorkflowHandler::generateWorkflowId($generalUpdate),
            AgenticWorkflowHandler::generateWorkflowId($topicUpdate),
        );
    }
}
