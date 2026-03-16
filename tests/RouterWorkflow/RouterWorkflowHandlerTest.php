<?php

declare(strict_types=1);

namespace Tests\RouterWorkflow;

use Bot\RouterWorkflow\RouterWorkflowHandler;
use Bot\Telegram\Update;
use Phenogram\Bindings\Factories\ChatFactory;
use Phenogram\Bindings\Factories\MessageFactory;
use Phenogram\Bindings\Factories\UpdateFactory;
use Phenogram\Bindings\Factories\UserFactory;
use Tests\TestCase;

class RouterWorkflowHandlerTest extends TestCase
{
    public function testGenerateWorkflowIdForGroupChat(): void
    {
        $update = UpdateFactory::make(
            message: MessageFactory::make(
                chat: ChatFactory::make(id: -100123456, type: 'supergroup'),
                from: UserFactory::make(id: 42),
            ),
        );

        assert($update instanceof Update);
        $workflowId = RouterWorkflowHandler::generateWorkflowId($update);

        self::assertSame('[Router] chat -100123456, topic general', $workflowId);
    }

    public function testGenerateWorkflowIdForTopic(): void
    {
        $update = UpdateFactory::make(
            message: MessageFactory::make(
                chat: ChatFactory::make(id: -100123456, type: 'supergroup'),
                from: UserFactory::make(id: 42),
                messageThreadId: 789,
            ),
        );

        assert($update instanceof Update);
        $workflowId = RouterWorkflowHandler::generateWorkflowId($update);

        self::assertSame('[Router] chat -100123456, topic 789', $workflowId);
    }

    public function testGenerateWorkflowIdForPrivateChat(): void
    {
        $update = UpdateFactory::make(
            message: MessageFactory::make(
                chat: ChatFactory::make(id: 42, type: 'private'),
                from: UserFactory::make(id: 42),
            ),
        );

        assert($update instanceof Update);
        $workflowId = RouterWorkflowHandler::generateWorkflowId($update);

        self::assertSame('[Router] chat 42, topic general', $workflowId);
    }

    public function testDifferentChatsGetDifferentWorkflowIds(): void
    {
        $update1 = UpdateFactory::make(
            message: MessageFactory::make(
                chat: ChatFactory::make(id: -100111, type: 'supergroup'),
            ),
        );

        $update2 = UpdateFactory::make(
            message: MessageFactory::make(
                chat: ChatFactory::make(id: -100222, type: 'supergroup'),
            ),
        );

        assert($update1 instanceof Update);
        assert($update2 instanceof Update);

        self::assertNotSame(
            RouterWorkflowHandler::generateWorkflowId($update1),
            RouterWorkflowHandler::generateWorkflowId($update2),
        );
    }

    public function testSameChatDifferentTopicsGetDifferentIds(): void
    {
        $update1 = UpdateFactory::make(
            message: MessageFactory::make(
                chat: ChatFactory::make(id: -100111),
                messageThreadId: 1,
            ),
        );

        $update2 = UpdateFactory::make(
            message: MessageFactory::make(
                chat: ChatFactory::make(id: -100111),
                messageThreadId: 2,
            ),
        );

        assert($update1 instanceof Update);
        assert($update2 instanceof Update);

        self::assertNotSame(
            RouterWorkflowHandler::generateWorkflowId($update1),
            RouterWorkflowHandler::generateWorkflowId($update2),
        );
    }
}
