<?php

declare(strict_types=1);

namespace Tests\Bot;

use Bot\AgenticWorkflow\AgenticWorkflow;
use Bot\Handler\WorkflowControlCommandHandler;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Phenogram\Bindings\Api;
use Phenogram\Bindings\Factories\ChatFactory;
use Phenogram\Bindings\Factories\MessageEntityFactory;
use Phenogram\Bindings\Factories\MessageFactory;
use Phenogram\Bindings\Factories\UpdateFactory;
use Phenogram\Framework\TelegramBot;
use Temporal\Client\WorkflowClientInterface;
use Temporal\Client\WorkflowStubInterface;
use Temporal\Exception\Client\WorkflowNotFoundException;
use Temporal\Workflow\WorkflowExecution;
use Tests\TestCase;

class WorkflowControlCommandTest extends TestCase
{
    private const int CHAT_ID = -100123456;

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    /**
     * @return iterable<string, array{command: string, signal: string, response: string}>
     */
    public static function commands(): iterable
    {
        yield 'pause' => [
            '/pause',
            AgenticWorkflow::PAUSE_SIGNAL_NAME,
            'Workflow чата приостановлен. Новые сообщения будут ждать команды /resume.',
        ];
        yield 'resume with bot username' => [
            '/resume@wtf_happend_bot',
            AgenticWorkflow::RESUME_SIGNAL_NAME,
            'Workflow чата продолжил работу и обработает накопленные сообщения.',
        ];
    }

    #[DataProvider('commands')]
    public function testCommandSignalsRunningWorkflow(string $command, string $signal, string $response): void
    {
        $update = UpdateFactory::make(
            updateId: 1001,
            message: MessageFactory::make(
                messageId: 2002,
                chat: ChatFactory::make(id: self::CHAT_ID, type: 'supergroup'),
                text: $command,
                messageThreadId: 42,
                entities: [
                    MessageEntityFactory::make(
                        type: 'bot_command',
                        offset: 0,
                        length: strlen($command),
                    ),
                ],
            ),
        );

        $workflow = Mockery::mock(WorkflowStubInterface::class);
        $workflow->shouldReceive('signal')->once()->with($signal);

        $client = Mockery::mock(WorkflowClientInterface::class);
        $client
            ->shouldReceive('newUntypedRunningWorkflowStub')
            ->once()
            ->with('Chat -100123456 [Root]', null, AgenticWorkflow::WORKFLOW_TYPE)
            ->andReturn($workflow);

        $api = Mockery::mock(Api::class);
        $api
            ->shouldReceive('sendMessage')
            ->once()
            ->with(self::CHAT_ID, $response, null, 42)
            ->andReturn(MessageFactory::make());

        $handler = new WorkflowControlCommandHandler($client);
        $bot = new TelegramBot('token', $api);

        self::assertTrue($handler::supports($update));

        $handler->handle($update, $bot);
    }

    public function testOtherCommandIsNotSupported(): void
    {
        $update = UpdateFactory::make(
            message: MessageFactory::make(
                chat: ChatFactory::make(id: self::CHAT_ID),
                text: '/clear',
                entities: [
                    MessageEntityFactory::make(
                        type: 'bot_command',
                        offset: 0,
                        length: 6,
                    ),
                ],
            ),
        );

        self::assertFalse(WorkflowControlCommandHandler::supports($update));
    }

    public function testMissingWorkflowIsReported(): void
    {
        $update = UpdateFactory::make(
            message: MessageFactory::make(
                chat: ChatFactory::make(id: self::CHAT_ID),
                text: '/pause',
                entities: [
                    MessageEntityFactory::make(
                        type: 'bot_command',
                        offset: 0,
                        length: 6,
                    ),
                ],
            ),
        );

        $workflow = Mockery::mock(WorkflowStubInterface::class);
        $workflow
            ->shouldReceive('signal')
            ->once()
            ->with(AgenticWorkflow::PAUSE_SIGNAL_NAME)
            ->andThrow(new WorkflowNotFoundException(
                null,
                new WorkflowExecution('Chat -100123456 [Root]'),
                AgenticWorkflow::WORKFLOW_TYPE,
            ));

        $client = Mockery::mock(WorkflowClientInterface::class);
        $client
            ->shouldReceive('newUntypedRunningWorkflowStub')
            ->once()
            ->andReturn($workflow);

        $api = Mockery::mock(Api::class);
        $api
            ->shouldReceive('sendMessage')
            ->once()
            ->with(self::CHAT_ID, 'Активного workflow для этого чата нет.', null, null)
            ->andReturn(MessageFactory::make());

        self::assertTrue(WorkflowControlCommandHandler::supports($update));

        (new WorkflowControlCommandHandler($client))->handle(
            $update,
            new TelegramBot('token', $api),
        );
    }
}
