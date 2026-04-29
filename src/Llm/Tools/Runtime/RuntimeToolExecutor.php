<?php

declare(strict_types=1);

namespace Bot\Llm\Tools\Runtime;

use Bot\Entity\RuntimeTool;
use Bot\Llm\Runtime\RuntimeCapabilityValidator;
use Cycle\ORM\ORMInterface;
use Shanginn\Openai\ChatCompletion\CompletionResponse;
use Shanginn\Openai\ChatCompletion\ErrorResponse;
use Shanginn\Openai\ChatCompletion\Message\UserMessage;
use Shanginn\Openai\Openai;
use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;

#[ActivityInterface(prefix: 'RuntimeToolExecutor.')]
class RuntimeToolExecutor
{
    private const int RESULT_LIMIT = 7000;

    public function __construct(
        private readonly ORMInterface $orm,
        private readonly Openai $openai,
    ) {}

    #[ActivityMethod]
    public function execute(int $chatId, string $toolName, string $argumentsJson): string
    {
        $toolName = RuntimeCapabilityValidator::normalizeName($toolName);

        /** @var \Bot\Entity\RuntimeTool\RuntimeToolRepository $repo */
        $repo = $this->orm->getRepository(RuntimeTool::class);
        $tool = $repo->findEnabledByName($chatId, $toolName);

        if ($tool === null) {
            return sprintf('Runtime tool "%s" is not enabled or does not exist in this chat.', $toolName);
        }

        try {
            $arguments = json_decode($argumentsJson, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return sprintf('Runtime tool "%s" received invalid JSON arguments: %s', $toolName, $e->getMessage());
        }

        if (!is_array($arguments)) {
            return sprintf('Runtime tool "%s" expected a JSON object for arguments.', $toolName);
        }

        $response = $this->openai->completion(
            messages: [
                new UserMessage(json_encode([
                    'tool_name' => $tool->name,
                    'description' => $tool->description,
                    'parameters_schema' => RuntimeCapabilityValidator::decodeParametersSchema($tool->parametersSchema),
                    'arguments' => $arguments,
                ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)),
            ],
            system: $this->systemPrompt($tool),
            extraBody: ['thinking' => ['type' => 'disabled']],
        );

        if ($response instanceof ErrorResponse) {
            return sprintf(
                'Runtime tool "%s" failed: %s',
                $tool->name,
                $response->message ?? 'unknown model error',
            );
        }

        return $this->formatResponse($tool, $response);
    }

    private function systemPrompt(RuntimeTool $tool): string
    {
        return <<<TEXT
        You are executing a generated runtime tool for a Telegram bot.

        Tool name: {$tool->name}
        Tool description: {$tool->description}

        <execution_contract>
        - Return only the tool result that should be inserted back into the agent loop.
        - Do not write a Telegram-visible final answer.
        - Follow the stored instructions exactly when possible.
        - Use only the provided JSON arguments and the tool instructions.
        - If the requested result cannot be produced from the provided arguments, say what is missing.
        </execution_contract>

        <stored_instructions>
        {$tool->instructions}
        </stored_instructions>
        TEXT;
    }

    private function formatResponse(RuntimeTool $tool, CompletionResponse $response): string
    {
        $content = $response->choices[0]->message->content ?? null;

        if (!is_string($content) || trim($content) === '') {
            return sprintf('Runtime tool "%s" returned no content.', $tool->name);
        }

        $content = trim($content);
        if (mb_strlen($content) <= self::RESULT_LIMIT) {
            return $content;
        }

        return mb_substr($content, 0, self::RESULT_LIMIT - 24) . '... [result truncated]';
    }
}
