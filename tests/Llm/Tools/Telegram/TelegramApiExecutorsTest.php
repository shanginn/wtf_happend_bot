<?php

declare(strict_types=1);

namespace Tests\Llm\Tools\Telegram;

use Bot\Llm\Tools\Telegram\TelegramApiCallExecutor;
use Bot\Llm\Tools\Telegram\TelegramApiSchemaExecutor;
use Phenogram\Bindings\ClientInterface;
use Phenogram\Bindings\Types\Response;
use Phenogram\Bindings\Types\Interfaces\ResponseInterface;
use Tests\TestCase;

class TelegramApiExecutorsTest extends TestCase
{
    public function testSchemaExecutorDescribesExactMethod(): void
    {
        $executor = new TelegramApiSchemaExecutor();
        $result = $executor->execute(methodName: 'sendMessage');

        self::assertStringContainsString('sendMessage(', $result);
        self::assertStringContainsString('chatId', $result);
        self::assertStringContainsString('text', $result);
    }

    public function testSchemaExecutorSearchesMethods(): void
    {
        $executor = new TelegramApiSchemaExecutor();
        $result = $executor->execute(query: 'poll', limit: 10);

        self::assertStringContainsString('sendPoll', $result);
    }

    public function testSchemaExecutorDoesNotExposeGlobalOrPrivilegedMethods(): void
    {
        $executor = new TelegramApiSchemaExecutor();

        self::assertStringContainsString(
            'Unknown Telegram Bot API method',
            $executor->execute(methodName: 'setWebhook'),
        );
        self::assertStringNotContainsString(
            'banChatMember',
            $executor->execute(query: 'ban', limit: 10),
        );
        self::assertStringContainsString(
            'Unknown Telegram Bot API method',
            $executor->execute(methodName: 'sendInvoice'),
        );
        self::assertStringContainsString(
            'Unknown Telegram Bot API method',
            $executor->execute(methodName: 'editMessageText'),
        );
        self::assertStringContainsString(
            'Unknown Telegram Bot API method',
            $executor->execute(methodName: 'sendChatAction'),
        );
    }

    public function testCallExecutorInjectsCurrentChatAndSendsRawRequest(): void
    {
        $client = new RecordingTelegramClient(new Response(
            ok: true,
            result: ['message_id' => 321],
        ));
        $executor = new TelegramApiCallExecutor($client);

        $result = $executor->execute(
            -100123,
            methodName: 'sendMessage',
            parameters: ['text' => 'Hello'],
        );

        self::assertSame('sendMessage', $client->method);
        self::assertSame([
            'text' => 'Hello',
            'chat_id' => -100123,
        ], $client->data);
        self::assertStringContainsString('"ok":true', $result);
        self::assertStringContainsString('"message_id":321', $result);
    }

    public function testCallExecutorReturnsReportedParameterErrorForModelRepair(): void
    {
        $client = new RecordingTelegramClient(new Response(
            ok: true,
            result: ['message_id' => 323],
        ));
        $executor = new TelegramApiCallExecutor($client);

        $result = $executor->execute(
            -100123,
            methodName: 'sendMessage',
            parameters: [
                'text' => 'Synthetic reply',
                'reply_to_message_id' => 123,
            ],
        );

        self::assertNull($client->method);
        self::assertStringContainsString(
            'Unknown parameter(s) for sendMessage: reply_to_message_id',
            $result,
        );
        self::assertStringContainsString('Use telegram_api_schema', $result);
        self::assertFalse(TelegramApiCallExecutor::isSuccessfulResult($result));
    }

    public function testCallExecutorAcceptsSnakeCaseMethodAndParameters(): void
    {
        $client = new RecordingTelegramClient(new Response(
            ok: true,
            result: ['message_id' => 322],
        ));
        $executor = new TelegramApiCallExecutor($client);

        $executor->execute(
            -100123,
            methodName: 'send_message',
            parameters: [
                'text' => 'Quiet hello',
                'disable_notification' => true,
            ],
        );

        self::assertSame('sendMessage', $client->method);
        self::assertSame([
            'text' => 'Quiet hello',
            'disable_notification' => true,
            'chat_id' => -100123,
        ], $client->data);
    }

    public function testCallExecutorCannotEscapeTheCurrentChat(): void
    {
        $client = new RecordingTelegramClient(new Response(
            ok: true,
            result: ['message_id' => 322],
        ));
        $executor = new TelegramApiCallExecutor($client);

        $executor->execute(
            -100123,
            methodName: 'sendMessage',
            parameters: [
                'chat_id' => 999999,
                'text' => 'Keep this scoped',
            ],
        );

        self::assertSame('sendMessage', $client->method);
        self::assertSame(-100123, $client->data['chat_id']);
    }

    public function testCallExecutorBindsActionsToTheTrustedTopic(): void
    {
        $client = new RecordingTelegramClient(new Response(
            ok: true,
            result: ['message_id' => 322],
        ));
        $executor = new TelegramApiCallExecutor($client);

        $executor->execute(
            -100123,
            methodName: 'sendMessage',
            parameters: [
                'text' => 'Topic-safe reply',
                'message_thread_id' => 999,
            ],
            messageThreadId: 42,
        );

        self::assertSame('sendMessage', $client->method);
        self::assertSame(42, $client->data['message_thread_id']);
    }

    public function testCallbackAnswerIsNotExposedToTheModel(): void
    {
        $client = new RecordingTelegramClient(new Response(ok: true, result: true));
        $executor = new TelegramApiCallExecutor($client);

        $result = $executor->execute(
            -100123,
            methodName: 'answerCallbackQuery',
            parameters: ['callback_query_id' => 'model-controlled'],
        );

        self::assertNull($client->method);
        self::assertStringContainsString('Unknown Telegram Bot API method', $result);
    }

    public function testCallExecutorRejectsNestedAndPaidRoutingOptions(): void
    {
        $client = new RecordingTelegramClient(new Response(ok: true, result: true));
        $executor = new TelegramApiCallExecutor($client);

        $replyResult = $executor->execute(
            -100123,
            methodName: 'sendMessage',
            parameters: [
                'text' => 'No escape',
                'reply_parameters' => [
                    'chat_id' => 999999,
                    'message_id' => 1,
                ],
            ],
        );
        $paidResult = $executor->execute(
            -100123,
            methodName: 'sendMessage',
            parameters: [
                'text' => 'No paid broadcast',
                'allow_paid_broadcast' => true,
            ],
        );

        self::assertNull($client->method);
        self::assertStringContainsString('bound to the current chat and topic', $replyResult);
        self::assertStringContainsString('bound to the current chat and topic', $paidResult);
    }

    public function testCallExecutorRejectsOversizedMessageBeforeNetworkCall(): void
    {
        $client = new RecordingTelegramClient(new Response(ok: true, result: true));
        $executor = new TelegramApiCallExecutor($client);

        $result = $executor->execute(
            -100123,
            methodName: 'sendMessage',
            parameters: ['text' => str_repeat('x', 4097)],
        );

        self::assertNull($client->method);
        self::assertStringContainsString('4096 character limit', $result);
    }

    public function testCrossTopicMessageMutationIsNotExposed(): void
    {
        $client = new RecordingTelegramClient(new Response(ok: true, result: true));
        $executor = new TelegramApiCallExecutor($client);

        $result = $executor->execute(
            -100123,
            methodName: 'forwardMessage',
            parameters: [
                'from_chat_id' => 999999,
                'message_id' => 42,
            ],
        );

        self::assertNull($client->method);
        self::assertStringContainsString('Unknown Telegram Bot API method', $result);
    }

    public function testGlobalBotAdministrationMethodIsRejected(): void
    {
        $client = new RecordingTelegramClient(new Response(ok: true, result: true));
        $executor = new TelegramApiCallExecutor($client);

        $result = $executor->execute(
            -100123,
            methodName: 'setWebhook',
            parameters: ['url' => 'https://example.test/hijack'],
        );

        self::assertNull($client->method);
        self::assertStringContainsString('Unknown Telegram Bot API method', $result);
    }

    public function testCallExecutorCannotCreateInvoices(): void
    {
        $client = new RecordingTelegramClient(new Response(ok: true, result: true));
        $executor = new TelegramApiCallExecutor($client);

        $result = $executor->execute(
            -100123,
            methodName: 'sendInvoice',
            parameters: [],
        );

        self::assertNull($client->method);
        self::assertStringContainsString('Unknown Telegram Bot API method', $result);
    }

    public function testCallExecutorRejectsUnknownParameterBeforeNetworkCall(): void
    {
        $client = new RecordingTelegramClient(new Response(ok: true, result: true));
        $executor = new TelegramApiCallExecutor($client);

        $result = $executor->execute(
            -100123,
            methodName: 'sendMessage',
            parameters: [
                'text' => 'Hello',
                'wat' => true,
            ],
        );

        self::assertNull($client->method);
        self::assertStringContainsString('Unknown parameter(s) for sendMessage: wat', $result);
    }

    public function testCallExecutorRejectsMissingRequiredParameterBeforeNetworkCall(): void
    {
        $client = new RecordingTelegramClient(new Response(ok: true, result: true));
        $executor = new TelegramApiCallExecutor($client);

        $result = $executor->execute(
            -100123,
            methodName: 'sendPhoto',
            parameters: [],
        );

        self::assertNull($client->method);
        self::assertStringContainsString('Missing required parameter(s) for sendPhoto: photo', $result);
    }

    public function testTerminalMethodDetection(): void
    {
        self::assertTrue(TelegramApiCallExecutor::isTerminalMethod('sendMessage'));
        self::assertFalse(TelegramApiCallExecutor::isTerminalMethod('editMessageText'));
        self::assertFalse(TelegramApiCallExecutor::isTerminalMethod('getChat'));
        self::assertFalse(TelegramApiCallExecutor::isTerminalMethod('sendChatAction'));
        self::assertFalse(TelegramApiCallExecutor::isTerminalMethod('createInvoiceLink'));
        self::assertFalse(TelegramApiCallExecutor::isTerminalMethod('deleteMessage'));
        self::assertTrue(TelegramApiCallExecutor::isReadOnlyMethod('getChat'));
        self::assertTrue(TelegramApiCallExecutor::isReadOnlyMethod('get_me'));
        self::assertFalse(TelegramApiCallExecutor::isReadOnlyMethod('sendChatAction'));
    }
}

final class RecordingTelegramClient implements ClientInterface
{
    public ?string $method = null;

    /** @var array<mixed>|null */
    public ?array $data = null;

    public function __construct(private readonly ResponseInterface $response) {}

    public function sendRequest(string $method, array $data): ResponseInterface
    {
        $this->method = $method;
        $this->data = $data;

        return $this->response;
    }
}
