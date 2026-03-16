<?php

declare(strict_types=1);

namespace Bot\Activity;

use Carbon\CarbonInterval;
use Shanginn\Openai\ChatCompletion\CompletionRequest\JsonSchema\JsonSchemaInterface;
use Shanginn\Openai\ChatCompletion\CompletionRequest\ResponseFormat;
use Shanginn\Openai\ChatCompletion\CompletionRequest\ToolChoice;
use Shanginn\Openai\ChatCompletion\CompletionResponse;
use Shanginn\Openai\ChatCompletion\ErrorResponse;
use Shanginn\Openai\ChatCompletion\Message\MessageInterface;
use Shanginn\Openai\Openai;
use Shanginn\Openai\OpenaiSimple;
use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;
use Temporal\Activity\ActivityOptions;
use Temporal\Common\RetryOptions;
use Temporal\Internal\Workflow\ActivityProxy;
use Temporal\Workflow;

#[ActivityInterface(prefix: 'Llm.')]
class LlmActivity
{
    private OpenaiSimple $openaiSimple;

    public function __construct(
        private Openai $openai,
    ) {
        $this->openaiSimple = new OpenaiSimple($openai);
    }

    public static function getDefinition(): ActivityProxy|self
    {
        return Workflow::newActivityStub(
            self::class,
            ActivityOptions::new()
                ->withStartToCloseTimeout(CarbonInterval::minute())
                ->withRetryOptions(
                    RetryOptions::new()->withNonRetryableExceptions([])
                )
        );
    }

    #[ActivityMethod]
    public function generate(
        string $system,
        string $text,
        ?array $history = [],
        ?string $schema = null,
        ?float $temperature = 0.0,
        ?float $frequencyPenalty = 0.0,
    ): JsonSchemaInterface|string {
        return $this->openaiSimple->generate(
            $system,
            $text,
            $history,
            $schema,
            $temperature,
            $frequencyPenalty,
        );
    }

    #[ActivityMethod]
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
        return $this->openai->completion(
            messages: $messages,
            system: $system,
            temperature: $temperature,
            maxTokens: $maxTokens,
            maxCompletionTokens: $maxCompletionTokens,
            frequencyPenalty: $frequencyPenalty,
            toolChoice: $toolChoice,
            tools: $tools,
            responseFormat: $responseFormat,
            topP: $topP,
            seed: $seed,
        );
    }
}
