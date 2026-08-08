<?php

declare(strict_types=1);

namespace Tests\Bot;

use Bot\AgenticWorkflow\AgenticWorkflow;
use Bot\Durability\DurableCommandReplyGateway;
use Bot\Durability\IdempotencyClaim;
use Bot\Durability\IdempotencyLedgerInterface;
use Bot\Handler\ClearCommandHandler;
use Bot\Handler\WorkflowControlCommandHandler;
use Bot\Telegram\TelegramChatAuthorizationPolicy;
use Mockery;
use Phenogram\Bindings\Api;
use Phenogram\Bindings\Factories\ChatFactory;
use Phenogram\Bindings\Factories\ChatMemberAdministratorFactory;
use Phenogram\Bindings\Factories\ChatMemberMemberFactory;
use Phenogram\Bindings\Factories\MessageEntityFactory;
use Phenogram\Bindings\Factories\MessageFactory;
use Phenogram\Bindings\Factories\UpdateFactory;
use Phenogram\Bindings\Factories\UserFactory;
use Phenogram\Framework\TelegramBot;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Temporal\Client\WorkflowClientInterface;
use Temporal\Client\WorkflowStubInterface;
use Temporal\Exception\Client\WorkflowNotFoundException;
use Temporal\Workflow\WorkflowExecution;
use Tests\TestCase;
use UnexpectedValueException;

class WorkflowControlCommandTest extends TestCase
{
    private const int CHAT_ID         = -100123456;
    private const int ADMIN_ID        = 7001;
    private const int PRIVATE_CHAT_ID = 7002;

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
            'Workflow темы приостановлен. Новые сообщения сохраняются в историю, но не обрабатываются задним числом.',
        ];
        yield 'resume with bot username' => [
            '/resume@wtf_happend_bot',
            AgenticWorkflow::RESUME_SIGNAL_NAME,
            'Workflow темы продолжил работу. Новые сообщения снова обрабатываются.',
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
                from: UserFactory::make(id: self::ADMIN_ID, isBot: false),
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
            ->with('Chat -100123456 [Topic 42]', null, AgenticWorkflow::WORKFLOW_TYPE)
            ->andReturn($workflow);

        $api = Mockery::mock(Api::class);
        $api
            ->shouldReceive('getChatMember')
            ->once()
            ->with(self::CHAT_ID, self::ADMIN_ID)
            ->andReturn(ChatMemberAdministratorFactory::make(
                status: 'administrator',
                user: UserFactory::make(id: self::ADMIN_ID),
                isAnonymous: false,
            ));
        $api
            ->shouldReceive('sendMessage')
            ->once()
            ->with(self::CHAT_ID, $response, null, 42)
            ->andReturn(MessageFactory::make());

        $handler = new WorkflowControlCommandHandler(
            $client,
            new TelegramChatAuthorizationPolicy($api),
            self::durableReplies(),
        );
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

    public function testRepeatedDuplicateUpdateDoesNotRepeatSignalOrReply(): void
    {
        $update = UpdateFactory::make(
            updateId: 1002,
            message: MessageFactory::make(
                messageId: 2003,
                chat: ChatFactory::make(id: self::CHAT_ID, type: 'supergroup'),
                from: UserFactory::make(id: self::ADMIN_ID, isBot: false),
                text: '/pause',
                messageThreadId: 42,
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
            ->with(AgenticWorkflow::PAUSE_SIGNAL_NAME);

        $client = Mockery::mock(WorkflowClientInterface::class);
        $client
            ->shouldReceive('newUntypedRunningWorkflowStub')
            ->once()
            ->andReturn($workflow);

        $api = Mockery::mock(Api::class);
        $api
            ->shouldReceive('getChatMember')
            ->once()
            ->with(self::CHAT_ID, self::ADMIN_ID)
            ->andReturn(ChatMemberAdministratorFactory::make(
                status: 'administrator',
                user: UserFactory::make(id: self::ADMIN_ID),
                isAnonymous: false,
            ));
        $api
            ->shouldReceive('sendMessage')
            ->once()
            ->with(
                self::CHAT_ID,
                'Workflow темы приостановлен. Новые сообщения сохраняются в историю, '
                    . 'но не обрабатываются задним числом.',
                null,
                42,
            )
            ->andReturn(MessageFactory::make());

        $handler = new WorkflowControlCommandHandler(
            $client,
            new TelegramChatAuthorizationPolicy($api),
            self::durableReplies(),
        );
        $bot = new TelegramBot('token', $api);

        self::assertTrue(WorkflowControlCommandHandler::supports($update));

        $handler->handle($update, $bot);
        $handler->handle($update, $bot);
    }

    public function testAcceptedReplyWithLostResponseIsNotSentAgainOnIngressRetry(): void
    {
        $update = UpdateFactory::make(
            updateId: 1003,
            message: MessageFactory::make(
                messageId: 2004,
                chat: ChatFactory::make(id: self::CHAT_ID, type: 'supergroup'),
                from: UserFactory::make(id: self::ADMIN_ID, isBot: false),
                text: '/clear',
                messageThreadId: 42,
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
            ->shouldReceive('terminate')
            ->once()
            ->with('Cleared by /clear command', ['updateId' => 1003]);

        $client = Mockery::mock(WorkflowClientInterface::class);
        $client
            ->shouldReceive('newUntypedRunningWorkflowStub')
            ->once()
            ->andReturn($workflow);

        $api = Mockery::mock(Api::class);
        $api
            ->shouldReceive('getChatMember')
            ->once()
            ->with(self::CHAT_ID, self::ADMIN_ID)
            ->andReturn(ChatMemberAdministratorFactory::make(
                status: 'administrator',
                user: UserFactory::make(id: self::ADMIN_ID),
                isAnonymous: false,
            ));
        $api
            ->shouldReceive('sendMessage')
            ->once()
            ->with(
                self::CHAT_ID,
                'Текущий workflow чата остановлен. Следующее сообщение запустит новый.',
                null,
                42,
            )
            ->andThrow(new RuntimeException('accepted but response lost'));

        $handler = new ClearCommandHandler(
            $client,
            new TelegramChatAuthorizationPolicy($api),
            self::durableReplies(),
        );
        $bot = new TelegramBot('token', $api);

        try {
            $handler->handle($update, $bot);
            self::fail('The ambiguous Telegram response must fail the first ingress attempt.');
        } catch (RuntimeException $error) {
            self::assertSame('accepted but response lost', $error->getMessage());
        }

        $handler->handle($update, $bot);
    }

    public function testExistingCompetingCommandClaimDoesNotRepeatAnAmbiguousMutation(): void
    {
        $gateway       = self::durableReplies();
        $mutations     = 0;
        $replyAttempts = 0;

        try {
            $gateway->execute(
                updateId: 1010,
                action: AgenticWorkflow::PAUSE_SIGNAL_NAME,
                chatId: self::CHAT_ID,
                messageThreadId: 42,
                messageId: 2010,
                resolveReply: static function () use (&$mutations): string {
                    ++$mutations;

                    throw new RuntimeException('mutation response lost');
                },
                sendReply: static function () use (&$replyAttempts): void {
                    ++$replyAttempts;
                },
            );
            self::fail('The competing owner must leave an ambiguous command claim.');
        } catch (RuntimeException $error) {
            self::assertSame('mutation response lost', $error->getMessage());
        }

        $gateway->execute(
            updateId: 1010,
            action: AgenticWorkflow::PAUSE_SIGNAL_NAME,
            chatId: self::CHAT_ID,
            messageThreadId: 42,
            messageId: 2010,
            resolveReply: static function () use (&$mutations): string {
                ++$mutations;

                return 'must not run';
            },
            sendReply: static function () use (&$replyAttempts): void {
                ++$replyAttempts;
            },
        );

        self::assertSame(1, $mutations);
        self::assertSame(0, $replyAttempts);
    }

    public function testSameUpdateIdAndActionWithDifferentRoutingDoNotCollide(): void
    {
        $gateway   = self::durableReplies();
        $mutations = [];
        $replies   = [];

        foreach ([
            ['chatId' => self::CHAT_ID, 'messageThreadId' => 42, 'messageId' => 2011],
            ['chatId' => self::CHAT_ID, 'messageThreadId' => 43, 'messageId' => 2012],
        ] as $routing) {
            $gateway->execute(
                updateId: 1011,
                action: AgenticWorkflow::PAUSE_SIGNAL_NAME,
                chatId: $routing['chatId'],
                messageThreadId: $routing['messageThreadId'],
                messageId: $routing['messageId'],
                resolveReply: static function () use (&$mutations, $routing): string {
                    $mutations[] = $routing;

                    return "paused:{$routing['messageThreadId']}";
                },
                sendReply: static function (string $text) use (&$replies): void {
                    $replies[] = $text;
                },
            );
        }

        self::assertCount(2, $mutations);
        self::assertSame(['paused:42', 'paused:43'], $replies);
    }

    public function testClearCommandTerminatesCurrentTopicWorkflow(): void
    {
        $update = UpdateFactory::make(
            updateId: 1004,
            message: MessageFactory::make(
                chat: ChatFactory::make(id: self::CHAT_ID, type: 'supergroup'),
                from: UserFactory::make(id: self::ADMIN_ID, isBot: false),
                text: '/clear',
                messageThreadId: 42,
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
            ->shouldReceive('terminate')
            ->once()
            ->with('Cleared by /clear command', ['updateId' => 1004]);

        $client = Mockery::mock(WorkflowClientInterface::class);
        $client
            ->shouldReceive('newUntypedRunningWorkflowStub')
            ->once()
            ->with('Chat -100123456 [Topic 42]')
            ->andReturn($workflow);

        $api = Mockery::mock(Api::class);
        $api
            ->shouldReceive('getChatMember')
            ->once()
            ->with(self::CHAT_ID, self::ADMIN_ID)
            ->andReturn(ChatMemberAdministratorFactory::make(
                status: 'administrator',
                user: UserFactory::make(id: self::ADMIN_ID),
                isAnonymous: false,
            ));
        $api
            ->shouldReceive('sendMessage')
            ->once()
            ->with(
                self::CHAT_ID,
                'Текущий workflow чата остановлен. Следующее сообщение запустит новый.',
                null,
                42,
            )
            ->andReturn(MessageFactory::make());

        self::assertTrue(ClearCommandHandler::supports($update));

        (new ClearCommandHandler(
            $client,
            new TelegramChatAuthorizationPolicy($api),
            self::durableReplies(),
        ))->handle(
            $update,
            new TelegramBot('token', $api),
        );
    }

    public function testMissingWorkflowIsReported(): void
    {
        $update = UpdateFactory::make(
            message: MessageFactory::make(
                chat: ChatFactory::make(id: self::PRIVATE_CHAT_ID, type: 'private'),
                from: UserFactory::make(id: self::PRIVATE_CHAT_ID, isBot: false),
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
                new WorkflowExecution('Chat 7002 [Root]'),
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
            ->with(self::PRIVATE_CHAT_ID, 'Активного workflow для этого чата нет.', null, null)
            ->andReturn(MessageFactory::make());

        self::assertTrue(WorkflowControlCommandHandler::supports($update));

        (new WorkflowControlCommandHandler(
            $client,
            new TelegramChatAuthorizationPolicy($api),
            self::durableReplies(),
        ))->handle(
            $update,
            new TelegramBot('token', $api),
        );
    }

    public function testGroupMemberCannotPauseWorkflow(): void
    {
        $update = UpdateFactory::make(
            message: MessageFactory::make(
                chat: ChatFactory::make(id: self::CHAT_ID, type: 'supergroup'),
                from: UserFactory::make(id: self::ADMIN_ID, isBot: false),
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
        $client = Mockery::mock(WorkflowClientInterface::class);
        $client->shouldNotReceive('newUntypedRunningWorkflowStub');
        $api = Mockery::mock(Api::class);
        $api
            ->shouldReceive('getChatMember')
            ->once()
            ->with(self::CHAT_ID, self::ADMIN_ID)
            ->andReturn(ChatMemberMemberFactory::make(
                status: 'member',
                user: UserFactory::make(id: self::ADMIN_ID),
            ));
        $api
            ->shouldReceive('sendMessage')
            ->once()
            ->with(
                self::CHAT_ID,
                'Недостаточно прав: в личном чате команду может выполнить '
                    . 'только его пользователь, а в группе — владелец или администратор.',
                null,
                null,
            )
            ->andReturn(MessageFactory::make());

        self::assertTrue(WorkflowControlCommandHandler::supports($update));

        (new WorkflowControlCommandHandler(
            $client,
            new TelegramChatAuthorizationPolicy($api),
            self::durableReplies(),
        ))->handle(
            $update,
            new TelegramBot('token', $api),
        );
    }

    public function testAuthorizationLookupFailureDoesNotClearWorkflow(): void
    {
        $update = UpdateFactory::make(
            message: MessageFactory::make(
                chat: ChatFactory::make(id: self::CHAT_ID, type: 'supergroup'),
                from: UserFactory::make(id: self::ADMIN_ID, isBot: false),
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
        $client = Mockery::mock(WorkflowClientInterface::class);
        $client->shouldNotReceive('newUntypedRunningWorkflowStub');
        $api = Mockery::mock(Api::class);
        $api
            ->shouldReceive('getChatMember')
            ->once()
            ->with(self::CHAT_ID, self::ADMIN_ID)
            ->andThrow(new RuntimeException('telegram unavailable'));
        $api
            ->shouldReceive('sendMessage')
            ->once()
            ->with(
                self::CHAT_ID,
                'Не удалось проверить права в Telegram. '
                    . 'Workflow не изменён; попробуйте ещё раз позже.',
                null,
                null,
            )
            ->andReturn(MessageFactory::make());

        self::assertTrue(ClearCommandHandler::supports($update));

        (new ClearCommandHandler(
            $client,
            new TelegramChatAuthorizationPolicy($api),
            self::durableReplies(),
        ))->handle(
            $update,
            new TelegramBot('token', $api),
        );
    }

    private static function durableReplies(
        ?IdempotencyLedgerInterface $ledger = null,
    ): DurableCommandReplyGateway {
        return new DurableCommandReplyGateway(
            $ledger ?? new InMemoryIdempotencyLedger(),
        );
    }
}

final class InMemoryIdempotencyLedger implements IdempotencyLedgerInterface
{
    /**
     * @var array<string, array{identity: string, result: array<string, mixed>|null}>
     */
    private array $records = [];

    public function claim(string $idempotencyKey, string $identity): IdempotencyClaim
    {
        $record = $this->records[$idempotencyKey] ?? null;
        if ($record !== null) {
            if ($record['identity'] !== $identity) {
                throw new UnexpectedValueException('Idempotency identity mismatch.');
            }

            return new IdempotencyClaim(
                idempotencyKey: $idempotencyKey,
                identity: $identity,
                acquired: false,
                result: $record['result'],
            );
        }

        $this->records[$idempotencyKey] = [
            'identity' => $identity,
            'result'   => null,
        ];

        return new IdempotencyClaim(
            idempotencyKey: $idempotencyKey,
            identity: $identity,
            acquired: true,
            result: null,
        );
    }

    public function complete(IdempotencyClaim $claim, array $result): void
    {
        if (!$claim->acquired) {
            throw new UnexpectedValueException('Only an acquired claim can be completed.');
        }

        $record = $this->records[$claim->idempotencyKey] ?? null;
        if ($record === null || $record['identity'] !== $claim->identity) {
            throw new UnexpectedValueException('Unknown idempotency claim.');
        }

        if ($record['result'] !== null && $record['result'] !== $result) {
            throw new UnexpectedValueException('Idempotency result mismatch.');
        }

        $this->records[$claim->idempotencyKey]['result'] = $result;
    }
}
