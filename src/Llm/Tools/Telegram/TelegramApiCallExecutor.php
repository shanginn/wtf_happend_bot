<?php

declare(strict_types=1);

namespace Bot\Llm\Tools\Telegram;

use Phenogram\Bindings\ClientInterface;
use Phenogram\Bindings\Serializer;
use Phenogram\Bindings\SerializerInterface;
use Phenogram\Bindings\Types\Interfaces\ResponseInterface;
use ReflectionMethod;
use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;

#[ActivityInterface(prefix: 'TelegramApiCallExecutor.')]
class TelegramApiCallExecutor
{
    private const int RESULT_LIMIT = 7000;

    public function __construct(
        private readonly ClientInterface $client,
        private readonly SerializerInterface $serializer = new Serializer(),
        private readonly TelegramApiMethodCatalog $catalog = new TelegramApiMethodCatalog(),
    ) {}

    #[ActivityMethod]
    public function execute(int $chatId, TelegramApiCall $schema): string
    {
        $method = $this->catalog->method($schema->method);
        if ($method === null) {
            return sprintf(
                'Unknown Telegram Bot API method "%s". Similar methods: %s. Use telegram_api_schema for the exact signature.',
                $schema->method,
                implode(', ', $this->catalog->similarMethods($schema->method)),
            );
        }

        $validation = $this->normalizeParameters($method, $schema->parameters, $chatId);
        if (is_string($validation)) {
            return $validation;
        }

        $response = $this->client->sendRequest(
            method: $method->getName(),
            data: $this->serializer->serialize($validation),
        );

        return $this->formatResponse($method->getName(), $response);
    }

    public static function isTerminalMethod(string $method): bool
    {
        $catalog = new TelegramApiMethodCatalog();
        $resolved = $catalog->resolveMethodName($method);

        if ($resolved === null || $resolved === 'sendChatAction') {
            return false;
        }

        return !$catalog->isReadOnly($resolved);
    }

    /**
     * @return array<string, mixed>|string
     */
    private function normalizeParameters(ReflectionMethod $method, array $rawParameters, int $chatId): array|string
    {
        $parameterMap = $this->catalog->parameterMap($method);
        $parameters = [];
        $unknown = [];

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

        if (isset($parameterMap['chatId']) && (!array_key_exists('chatId', $parameters) || $parameters['chatId'] === null)) {
            $parameters['chatId'] = $chatId;
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
            'ok' => $response->ok,
            'method' => $method,
        ];

        if (!$response->ok) {
            $payload['error_code'] = $response->errorCode;
            $payload['description'] = $response->description ?? 'Telegram returned ok=false.';
            $payload['parameters'] = $response->parameters;

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
