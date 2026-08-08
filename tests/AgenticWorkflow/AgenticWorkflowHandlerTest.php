<?php

declare(strict_types=1);

namespace Tests\AgenticWorkflow;

use Bot\AgenticWorkflow\AgenticWorkflowHandler;
use Bot\AgenticWorkflow\AgenticWorkflowInput;
use Bot\Telegram\PaymentQueryAnswer;
use Bot\Telegram\Update;
use Carbon\CarbonInterval;
use Mockery;
use Phenogram\Bindings\Factories\ChatFactory;
use Phenogram\Bindings\Factories\MessageFactory;
use Phenogram\Bindings\Factories\PreCheckoutQueryFactory;
use Phenogram\Bindings\Factories\UpdateFactory;
use Temporal\Client\WorkflowClientInterface;
use Temporal\Client\WorkflowOptions;
use Temporal\Common\IdReusePolicy;
use Tests\TestCase;

class AgenticWorkflowHandlerTest extends TestCase
{
    private const int CHAT_ID = -100123456;

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function testGenerateWorkflowIdPartitionsRootAndTopics(): void
    {
        $generalUpdate = UpdateFactory::make(
            message: MessageFactory::make(
                chat: ChatFactory::make(id: self::CHAT_ID, type: 'supergroup'),
            ),
        );
        $topicUpdate = UpdateFactory::make(
            message: MessageFactory::make(
                chat: ChatFactory::make(id: self::CHAT_ID, type: 'supergroup'),
                messageThreadId: 789,
                isTopicMessage: true,
            ),
        );
        $genericThreadUpdate = UpdateFactory::make(
            message: MessageFactory::make(
                chat: ChatFactory::make(id: self::CHAT_ID, type: 'supergroup'),
                messageThreadId: 193132,
            ),
        );

        assert($generalUpdate instanceof Update);
        assert($topicUpdate instanceof Update);
        assert($genericThreadUpdate instanceof Update);

        self::assertSame('Chat -100123456 [Root]', AgenticWorkflowHandler::generateWorkflowId($generalUpdate));
        self::assertSame(
            'Chat -100123456 [Topic 789]',
            AgenticWorkflowHandler::generateWorkflowId($topicUpdate),
        );
        self::assertSame(
            'Chat -100123456 [Root]',
            AgenticWorkflowHandler::generateWorkflowId($genericThreadUpdate),
        );
    }

    public function testHandleUpdateSignalsWorkflowForTopicMessage(): void
    {
        $update = $this->makeMessageUpdate(
            'hello there',
            messageThreadId: 789,
            isTopicMessage: true,
        );

        $client = Mockery::mock(WorkflowClientInterface::class);
        $workflowStub = new \stdClass();

        $client
            ->shouldReceive('newWorkflowStub')
            ->once()
            ->withArgs(function (string $class, WorkflowOptions $options): bool {
                return $class === \Bot\AgenticWorkflow\AgenticWorkflow::class
                    && $options->workflowId === 'Chat -100123456 [Topic 789]'
                    && (int) CarbonInterval::instance($options->workflowTaskTimeout)->totalSeconds === 60
                    && $options->workflowIdReusePolicy === IdReusePolicy::AllowDuplicate->value;
            })
            ->andReturn($workflowStub);

        $client
            ->shouldReceive('signalWithStart')
            ->once()
            ->withArgs(function (
                object $workflow,
                string $signal,
                array $signalArgs,
                array $startArgs,
            ) use ($workflowStub, $update): bool {
                return $workflow === $workflowStub
                    && $signal === 'addUpdate'
                    && $signalArgs === [$update]
                    && count($startArgs) === 1
                    && $startArgs[0] instanceof AgenticWorkflowInput
                    && $startArgs[0]->chatId === self::CHAT_ID
                    && $startArgs[0]->chatType === 'supergroup'
                    && $startArgs[0]->topicId === 789;
            });

        $client->shouldNotReceive('newUntypedRunningWorkflowStub');

        (new AgenticWorkflowHandler($client))->handleUpdate($update);

        $this->addToAssertionCount(1);
    }

    public function testHandleUpdateRejectsPreCheckoutQueryWithoutStartingWorkflow(): void
    {
        $update = UpdateFactory::make(
            updateId: 1003,
            preCheckoutQuery: PreCheckoutQueryFactory::make(id: 'checkout-missing', invoicePayload: 'legacy-payload'),
        );

        assert($update instanceof Update);

        $client = Mockery::mock(WorkflowClientInterface::class);
        $client->shouldNotReceive('newWorkflowStub');
        $client->shouldNotReceive('newUntypedRunningWorkflowStub');
        $client->shouldNotReceive('signalWithStart');

        $answer = (new AgenticWorkflowHandler($client))->handleUpdate($update);

        self::assertInstanceOf(PaymentQueryAnswer::class, $answer);
        self::assertSame(PaymentQueryAnswer::ACTION_PRE_CHECKOUT, $answer->action);
        self::assertSame('checkout-missing', $answer->queryId);
        self::assertFalse($answer->ok);
        self::assertSame('Платежи через этого бота отключены.', $answer->errorMessage);
    }

    private function makeMessageUpdate(
        string $text,
        ?int $messageThreadId = null,
        ?bool $isTopicMessage = null,
    ): Update {
        $update = UpdateFactory::make(
            updateId: 1001,
            message: MessageFactory::make(
                messageId: 2002,
                chat: ChatFactory::make(id: self::CHAT_ID, type: 'supergroup'),
                text: $text,
                messageThreadId: $messageThreadId,
                isTopicMessage: $isTopicMessage,
            ),
        );

        assert($update instanceof Update);

        return $update;
    }
}
