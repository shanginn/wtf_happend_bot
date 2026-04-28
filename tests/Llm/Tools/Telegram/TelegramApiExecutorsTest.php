<?php

declare(strict_types=1);

namespace Tests\Llm\Tools\Telegram;

use Bot\Llm\Tools\Telegram\TelegramApiCall;
use Bot\Llm\Tools\Telegram\TelegramApiCallExecutor;
use Bot\Llm\Tools\Telegram\TelegramApiSchema;
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
        $result = $executor->execute(-100123, new TelegramApiSchema(method: 'sendMessage'));

        self::assertStringContainsString('sendMessage(', $result);
        self::assertStringContainsString('chatId', $result);
        self::assertStringContainsString('text', $result);
    }

    public function testSchemaExecutorSearchesMethods(): void
    {
        $executor = new TelegramApiSchemaExecutor();
        $result = $executor->execute(-100123, new TelegramApiSchema(query: 'poll', limit: 10));

        self::assertStringContainsString('sendPoll', $result);
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
            new TelegramApiCall(method: 'sendMessage', parameters: ['text' => 'Hello']),
        );

        self::assertSame('sendMessage', $client->method);
        self::assertSame([
            'text' => 'Hello',
            'chat_id' => -100123,
        ], $client->data);
        self::assertStringContainsString('"ok":true', $result);
        self::assertStringContainsString('"message_id":321', $result);
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
            new TelegramApiCall(
                method: 'send_message',
                parameters: [
                    'text' => 'Quiet hello',
                    'disable_notification' => true,
                ],
            ),
        );

        self::assertSame('sendMessage', $client->method);
        self::assertSame([
            'text' => 'Quiet hello',
            'disable_notification' => true,
            'chat_id' => -100123,
        ], $client->data);
    }

    public function testCallExecutorRejectsUnknownParameterBeforeNetworkCall(): void
    {
        $client = new RecordingTelegramClient(new Response(ok: true, result: true));
        $executor = new TelegramApiCallExecutor($client);

        $result = $executor->execute(
            -100123,
            new TelegramApiCall(
                method: 'sendMessage',
                parameters: [
                    'text' => 'Hello',
                    'wat' => true,
                ],
            ),
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
            new TelegramApiCall(method: 'sendPhoto', parameters: []),
        );

        self::assertNull($client->method);
        self::assertStringContainsString('Missing required parameter(s) for sendPhoto: photo', $result);
    }

    public function testTerminalMethodDetection(): void
    {
        self::assertTrue(TelegramApiCallExecutor::isTerminalMethod('sendMessage'));
        self::assertTrue(TelegramApiCallExecutor::isTerminalMethod('deleteMessage'));
        self::assertFalse(TelegramApiCallExecutor::isTerminalMethod('getChat'));
        self::assertFalse(TelegramApiCallExecutor::isTerminalMethod('sendChatAction'));
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
