<?php

declare(strict_types=1);

namespace Bot\Openai;

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
     * @param array<class-string<ToolInterface>>|null $tools
     * @param ?ResponseFormat                         $responseFormat
     * @param ?float                                  $topP
     * @param ?int                                    $seed
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
    ): CompletionResponse|ErrorResponse {
        if ($system !== null) {
            array_unshift($messages, new SystemMessage($system));
        }

        $request = new CompletionRequest(
            model: $this->model,
            messages: $messages,
            temperature: $temperature,
            maxTokens: $maxTokens,
            maxCompletionTokens: $maxCompletionTokens,
            frequencyPenalty: $frequencyPenalty,
            responseFormat: $responseFormat,
            seed: $seed,
            topP: $topP,
            tools: $tools,
            toolChoice: $toolChoice,
        );

        $body = $this->serializer->serialize($request);
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
        $response = $this->serializer->deserialize($responseJson, CompletionResponse::class, $tools);

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
}
