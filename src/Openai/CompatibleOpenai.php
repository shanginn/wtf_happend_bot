<?php

declare(strict_types=1);

namespace Bot\Openai;

use Bot\Llm\Runtime\RuntimeToolDefinition;
use Shanginn\Openai\ChatCompletion\CompletionRequest;
use Shanginn\Openai\ChatCompletion\CompletionRequest\ResponseFormat;
use Shanginn\Openai\ChatCompletion\CompletionRequest\ResponseFormatEnum;
use Shanginn\Openai\ChatCompletion\CompletionRequest\ToolChoice;
use Shanginn\Openai\ChatCompletion\CompletionRequest\ToolInterface;
use Shanginn\Openai\ChatCompletion\CompletionResponse;
use Shanginn\Openai\ChatCompletion\ErrorResponse;
use Shanginn\Openai\ChatCompletion\Message\AssistantMessage;
use Shanginn\Openai\ChatCompletion\Message\MessageInterface;
use Shanginn\Openai\ChatCompletion\Message\SystemMessage;
use Shanginn\Openai\Openai as BaseOpenai;
use Shanginn\Openai\Openai\OpenaiClientInterface;
use Shanginn\Openai\Openai\OpenaiSerializerInterface;
use Throwable;

final class CompatibleOpenai extends BaseOpenai
{
    private readonly OpenaiSerializerInterface $serializer;

    public function __construct(
        private readonly OpenaiClientInterface $client,
        private readonly string $model = 'gpt-4.1-mini',
        ?OpenaiSerializerInterface $serializer = null,
    ) {
        parent::__construct($client, $model);
        $this->serializer = $serializer ?? new CompatibleOpenaiSerializer();
    }

    /**
     * @param array<MessageInterface>                 $messages
     * @param ?string                                 $system
     * @param ?float                                  $temperature
     * @param ?int                                    $maxTokens
     * @param ?int                                    $maxCompletionTokens
     * @param ?float                                  $frequencyPenalty
     * @param ToolChoice|null                         $toolChoice
     * @param array<class-string<ToolInterface>|RuntimeToolDefinition|array<string, mixed>>|null $tools
     * @param ?ResponseFormat                         $responseFormat
     * @param ?float                                  $topP
     * @param ?int                                    $seed
     * @param ?string                                 $reasoningEffort
     * @param array<string, mixed>|null               $extraBody
     */
    public function completion(
        array $messages,
        ?string $system = null,
        ?float $temperature = 0.0,
        ?int $maxTokens = null,
        ?int $maxCompletionTokens = null,
        ?float $frequencyPenalty = null,
        ?ToolChoice $toolChoice = null,
        ?array $tools = null,
        ?ResponseFormat $responseFormat = null,
        ?float $topP = null,
        ?int $seed = null,
        ?string $reasoningEffort = null,
        ?array $extraBody = null,
    ): CompletionResponse|ErrorResponse {
        if ($system !== null) {
            array_unshift($messages, new SystemMessage($system));
        }

        $toolSet = $this->splitTools($tools);

        $request = new CompletionRequest(
            model: $this->model,
            messages: $messages,
            temperature: $temperature,
            maxTokens: $maxTokens,
            maxCompletionTokens: $maxCompletionTokens,
            reasoningEffort: $reasoningEffort,
            frequencyPenalty: $frequencyPenalty,
            responseFormat: $responseFormat,
            seed: $seed,
            topP: $topP,
            tools: $toolSet['static'] === [] ? null : $toolSet['static'],
            toolChoice: $toolChoice,
        );

        $body = $this->serializer->serialize($request);
        $body = $this->appendRawTools($body, $toolSet['raw']);
        if ($extraBody !== null && $extraBody !== []) {
            $bodyData = json_decode($body, associative: true, flags: \JSON_THROW_ON_ERROR);
            $body = json_encode(array_replace($bodyData, $extraBody), \JSON_THROW_ON_ERROR);
        }

        $responseJson = $this->client->sendRequest('/chat/completions', $body);
        $responseData = json_decode($responseJson, associative: true);

        if (isset($responseData['error'])) {
            if (is_string($responseData['error'])) {
                $errorMessage = $responseData['error'];
            } else {
                $errorMessage = $responseData['error']['message'] ?? null;
            }

            return new ErrorResponse(
                message: $errorMessage,
                type: $responseData['error']['type'] ?? null,
                param: $responseData['error']['param'] ?? null,
                code: $responseData['error']['code'] ?? $responseData['code'] ?? null,
                rawResponse: $responseJson,
            );
        }

        /** @var CompletionResponse $response */
        $response = $this->serializer->deserialize(
            $responseJson,
            CompletionResponse::class,
            $toolSet['static'] === [] ? null : $toolSet['static'],
        );

        foreach ($response->choices as $index => $choice) {
            if (!$choice->message instanceof AssistantMessage) {
                continue;
            }

            if ($responseFormat?->type !== ResponseFormatEnum::JSON_SCHEMA) {
                continue;
            }

            try {
                $schemedContent = $this->serializer->deserialize(
                    serialized: $choice->message->content,
                    to: $responseFormat->jsonSchema,
                );

                $response->choices[$index] = CompletionResponse\Choice::withSchemedMessage(
                    $choice,
                    $schemedContent,
                );
            } catch (Throwable) {
                continue;
            }
        }

        return $response;
    }

    /**
     * @param array<class-string<ToolInterface>|RuntimeToolDefinition|array<string, mixed>>|null $tools
     * @return array{static: array<class-string<ToolInterface>>, raw: array<array<string, mixed>>}
     */
    private function splitTools(?array $tools): array
    {
        $staticTools = [];
        $rawTools = [];

        foreach ($tools ?? [] as $tool) {
            if (is_string($tool) && is_a($tool, ToolInterface::class, true)) {
                $staticTools[] = $tool;
                continue;
            }

            if ($tool instanceof RuntimeToolDefinition) {
                $rawTools[] = $tool->toOpenaiTool();
                continue;
            }

            if (is_array($tool)) {
                $rawTools[] = $tool;
                continue;
            }

            throw new \InvalidArgumentException('Tool must be a ToolInterface class-string, RuntimeToolDefinition, or raw OpenAI tool schema array.');
        }

        if ((count($staticTools) + count($rawTools)) > 128) {
            throw new \InvalidArgumentException('A max of 128 tools are supported.');
        }

        return [
            'static' => $staticTools,
            'raw' => $rawTools,
        ];
    }

    /**
     * @param array<array<string, mixed>> $rawTools
     */
    private function appendRawTools(string $body, array $rawTools): string
    {
        if ($rawTools === []) {
            return $body;
        }

        $bodyData = json_decode($body, associative: true, flags: \JSON_THROW_ON_ERROR);
        $existingTools = $bodyData['tools'] ?? [];
        if (!is_array($existingTools)) {
            $existingTools = [];
        }

        $bodyData['tools'] = [
            ...$existingTools,
            ...$rawTools,
        ];

        return json_encode(
            $bodyData,
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
        );
    }
}
