<?php

declare(strict_types=1);

namespace Tests\AgenticWorkflow;

use Bot\AgenticWorkflow\AgenticWorkflowHandler;
use Bot\AgenticWorkflow\AgenticWorkflowInput;
use Bot\Telegram\InvoiceWorkflowPayload;
use Bot\Telegram\PaymentQueryAnswer;
use Bot\Telegram\Update;
use Carbon\CarbonInterval;
use Mockery;
use Phenogram\Bindings\Factories\ChatFactory;
use Phenogram\Bindings\Factories\MessageFactory;
use Phenogram\Bindings\Factories\PreCheckoutQueryFactory;
use Phenogram\Bindings\Factories\UpdateFactory;
use Temporal\Client\WorkflowStubInterface;
use Temporal\Client\WorkflowClientInterface;
use Temporal\Client\WorkflowOptions;
use Temporal\Common\IdReusePolicy;
use Temporal\DataConverter\EncodedValues;
use Tests\TestCase;

class AgenticWorkflowHandlerTest extends TestCase
{
    private const int CHAT_ID = -100123456;

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function testGenerateWorkflowIdIgnoresTopic(): void
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

    public function testHandleUpdateSignalsWorkflowForRegularMessage(): void
    {
        $update = $this->makeMessageUpdate('hello there');

        $client = Mockery::mock(WorkflowClientInterface::class);
        $workflowStub = new \stdClass();

        $client
            ->shouldReceive('newWorkflowStub')
            ->once()
            ->withArgs(function (string $class, WorkflowOptions $options): bool {
                return $class === \Bot\AgenticWorkflow\AgenticWorkflow::class
                    && $options->workflowId === 'Chat -100123456 [Root]'
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
                    && $startArgs[0]->chatId === self::CHAT_ID;
            });

        $client->shouldNotReceive('newUntypedRunningWorkflowStub');

        (new AgenticWorkflowHandler($client))->handleUpdate($update);

        $this->addToAssertionCount(1);
    }

    public function testHandleUpdateSendsRoutedPaymentUpdateToRunningWorkflow(): void
    {
        $payload = InvoiceWorkflowPayload::encode(self::CHAT_ID, 'invoice-1');
        $update = UpdateFactory::make(
            updateId: 1002,
            preCheckoutQuery: PreCheckoutQueryFactory::make(id: 'checkout-1', invoicePayload: $payload),
        );

        assert($update instanceof Update);

        $workflow = Mockery::mock(WorkflowStubInterface::class);
        $workflow
            ->shouldReceive('update')
            ->once()
            ->with(
                \Bot\AgenticWorkflow\AgenticWorkflow::PAYMENT_UPDATE_NAME,
                $update,
            )
            ->andReturn(EncodedValues::fromValues([[
                'action' => PaymentQueryAnswer::ACTION_PRE_CHECKOUT,
                'query_id' => 'checkout-1',
                'ok' => true,
                'error_message' => null,
            ]]));

        $client = Mockery::mock(WorkflowClientInterface::class);
        $client->shouldNotReceive('newWorkflowStub');
        $client->shouldNotReceive('signalWithStart');
        $client
            ->shouldReceive('newUntypedRunningWorkflowStub')
            ->once()
            ->with('Chat -100123456 [Root]', null, \Bot\AgenticWorkflow\AgenticWorkflow::WORKFLOW_TYPE)
            ->andReturn($workflow);

        $answer = (new AgenticWorkflowHandler($client))->handleUpdate($update);

        self::assertInstanceOf(PaymentQueryAnswer::class, $answer);
        self::assertSame(PaymentQueryAnswer::ACTION_PRE_CHECKOUT, $answer->action);
        self::assertSame('checkout-1', $answer->queryId);
        self::assertTrue($answer->ok);
    }

    public function testHandleUpdateRejectsUnroutablePreCheckoutQueryWithoutStartingWorkflow(): void
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
    }

    private function makeMessageUpdate(
        string $text,
        ?int $messageThreadId = null,
    ): Update {
        $update = UpdateFactory::make(
            updateId: 1001,
            message: MessageFactory::make(
                messageId: 2002,
                chat: ChatFactory::make(id: self::CHAT_ID, type: 'supergroup'),
                text: $text,
                messageThreadId: $messageThreadId,
            ),
        );

        assert($update instanceof Update);

        return $update;
    }
}
