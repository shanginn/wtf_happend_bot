<?php

declare(strict_types=1);

namespace Bot\Llm\Tools\Telegram;

use Phenogram\Bindings\ClientInterface;
use Phenogram\Bindings\Serializer;
use Phenogram\Bindings\SerializerInterface;
use Phenogram\Bindings\Types\Interfaces\ResponseInterface;
use ReflectionMethod;

final class TelegramApiCallExecutor
{
    private const int MAX_MESSAGE_TEXT_LENGTH = 4096;
    private const int RESULT_LIMIT            = 7000;

    public function __construct(
        private readonly ClientInterface $client,
        private readonly SerializerInterface $serializer = new Serializer(),
        private readonly TelegramApiMethodCatalog $catalog = new TelegramApiMethodCatalog(),
    ) {}

    public static function isTerminalMethod(string $method): bool
    {
        $catalog  = new TelegramApiMethodCatalog();
        $resolved = $catalog->resolveMethodName($method);

        return $resolved !== null && $catalog->isTerminal($resolved);
    }

    public static function isSuccessfulResult(string $result): bool
    {
        return str_starts_with($result, 'Telegram API call succeeded:');
    }

    public static function isReadOnlyMethod(string $method): bool
    {
        return (new TelegramApiMethodCatalog())->isReadOnly($method);
    }

    public function execute(
        int $chatId,
        string $methodName,
        array $parameters = [],
        ?int $messageThreadId = null,
    ): string {
        $method = $this->catalog->method($methodName);
        if ($method === null) {
            return sprintf(
                'Unknown Telegram Bot API method "%s". Similar methods: %s. Use telegram_api_schema for the exact signature.',
                $methodName,
                implode(', ', $this->catalog->similarMethods($methodName)),
            );
        }

        $validation = $this->normalizeParameters(
            $method,
            $parameters,
            $chatId,
            $messageThreadId,
        );
        if (is_string($validation)) {
            return $validation;
        }

        $response = $this->client->sendRequest(
            method: $method->getName(),
            data: $this->serializer->serialize($validation),
        );

        return $this->formatResponse($method->getName(), $response);
    }

    /**
     * @param ReflectionMethod $method
     * @param array            $rawParameters
     * @param int              $chatId
     * @param ?int             $messageThreadId
     *
     * @return array<string, mixed>|string
     */
    private function normalizeParameters(
        ReflectionMethod $method,
        array $rawParameters,
        int $chatId,
        ?int $messageThreadId,
    ): array|string {
        $parameterMap = $this->catalog->parameterMap($method);
        $parameters   = [];
        $unknown      = [];

        foreach ($rawParameters as $name => $value) {
            if (!is_string($name)) {
                $unknown[] = (string) $name;

                continue;
            }

            $parameterName = $this->catalog->resolveParameterName($method, $name);

            if ($parameterName === null) {
                $unknown[] = $name;

                continue;
            }

            if (!$this->catalog->isAllowedParameter($parameterName)) {
                return sprintf(
                    'Parameter %s is not available: Telegram actions are bound to the current chat and topic.',
                    $parameterName,
                );
            }

            $parameters[$parameterName] = $value;
        }

        if ($unknown !== []) {
            return sprintf(
                'Unknown parameter(s) for %s: %s. Use telegram_api_schema with method "%s" for the exact parameters.',
                $method->getName(),
                implode(', ', $unknown),
                $method->getName(),
            );
        }

        if (isset($parameterMap['chatId'])) {
            $parameters['chatId'] = $chatId;
        }
        if (isset($parameterMap['fromChatId'])) {
            $parameters['fromChatId'] = $chatId;
        }
        if (isset($parameterMap['messageThreadId'])) {
            if ($messageThreadId === null) {
                unset($parameters['messageThreadId']);
            } else {
                $parameters['messageThreadId'] = $messageThreadId;
            }
        }

        if (
            $method->getName() === 'sendMessage'
            && is_string($parameters['text'] ?? null)
            && mb_strlen($parameters['text']) > self::MAX_MESSAGE_TEXT_LENGTH
        ) {
            return sprintf(
                'Telegram API call failed: %s text exceeds Telegram\'s %d character limit; shorten it and retry.',
                $method->getName(),
                self::MAX_MESSAGE_TEXT_LENGTH,
            );
        }

        $missing = [];
        foreach ($parameterMap as $name => $parameter) {
            if ($parameter->isDefaultValueAvailable() || $parameter->allowsNull()) {
                continue;
            }

            if (!array_key_exists($name, $parameters)) {
                $missing[] = $name;
            }
        }

        if ($missing !== []) {
            return sprintf(
                'Missing required parameter(s) for %s: %s. Use telegram_api_schema with method "%s" for details.',
                $method->getName(),
                implode(', ', $missing),
                $method->getName(),
            );
        }

        return $parameters;
    }

    private function formatResponse(string $method, ResponseInterface $response): string
    {
        $payload = [
            'ok'     => $response->ok,
            'method' => $method,
        ];

        if (!$response->ok) {
            $payload['error_code']  = $response->errorCode;
            $payload['description'] = $response->description ?? 'Telegram returned ok=false.';
            $payload['parameters']  = $response->parameters;

            return 'Telegram API call failed: ' . $this->encodeLimited($payload);
        }

        $payload['result'] = $response->result;

        return 'Telegram API call succeeded: ' . $this->encodeLimited($payload);
    }

    private function encodeLimited(array $payload): string
    {
        $json = json_encode($payload, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR);

        if (!is_string($json)) {
            return 'Unable to encode Telegram API response.';
        }

        if (mb_strlen($json) <= self::RESULT_LIMIT) {
            return $json;
        }

        return mb_substr($json, 0, self::RESULT_LIMIT - 24) . '... [response truncated]';
    }
}
