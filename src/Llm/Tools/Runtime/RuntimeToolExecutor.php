<?php

declare(strict_types=1);

namespace Bot\Llm\Tools\Runtime;

use Bot\AgenticWorkflow\AgentRuntime;
use Bot\Entity\RuntimeTool;
use Bot\Entity\RuntimeTool\RuntimeToolRepository;
use Bot\Llm\Runtime\RuntimeCapabilityValidator;
use Cycle\ORM\ORMInterface;
use PiPHP\AI\Content\ToolCallContent;
use PiPHP\AI\Tool\Tool;
use PiPHP\AI\Tool\ToolValidator;
use PiPHP\Temporal\Contract\ModelCompletionGatewayInterface;
use PiPHP\Temporal\DTO\AgentMessage;
use PiPHP\Temporal\DTO\ModelActivityInput;
use Throwable;

final readonly class RuntimeToolExecutor
{
    private const int RESULT_LIMIT = 7000;

    public function __construct(
        private ORMInterface $orm,
        private ModelCompletionGatewayInterface $models,
        private string $model = AgentRuntime::MODEL,
        private ToolValidator $validator = new ToolValidator(),
    ) {}

    /**
     * @param array<string, mixed> $arguments
     * @param int                  $chatId
     * @param string               $toolName
     * @param string               $idempotencyKey
     */
    public function execute(
        int $chatId,
        string $toolName,
        array $arguments,
        string $idempotencyKey,
    ): string {
        $toolName = RuntimeCapabilityValidator::normalizeName($toolName);

        /** @var RuntimeToolRepository $repo */
        $repo = $this->orm->getRepository(RuntimeTool::class);
        $tool = $repo->findEnabledByName($chatId, $toolName);

        if ($tool === null) {
            return sprintf('Runtime tool "%s" is not enabled or does not exist in this chat.', $toolName);
        }

        try {
            $parameters = RuntimeCapabilityValidator::decodeParametersSchema($tool->parametersSchema);
        } catch (Throwable) {
            return sprintf(
                'Runtime tool "%s" is unavailable: stored schema is invalid.',
                $tool->name,
            );
        }

        $storedError = RuntimeCapabilityValidator::storedRuntimeToolError($tool);
        if ($storedError !== null) {
            return sprintf(
                'Runtime tool "%s" is unavailable: stored definition is invalid: %s',
                $tool->name,
                $storedError,
            );
        }

        $definition = new Tool(
            name: $tool->name,
            description: $tool->description,
            parameters: $parameters,
        );

        try {
            $arguments = $this->validator->validate(
                $definition,
                new ToolCallContent(
                    id: $idempotencyKey,
                    name: $tool->name,
                    arguments: $arguments,
                ),
            );
        } catch (Throwable $error) {
            return sprintf('Runtime tool "%s" rejected its arguments: %s', $tool->name, $error->getMessage());
        }

        $result = $this->models->complete(new ModelActivityInput(
            model: $this->model,
            messages: [
                AgentMessage::text('system', $this->systemPrompt($tool))->toArray(),
                AgentMessage::text('user', json_encode([
                    'tool_name'         => $tool->name,
                    'description'       => $tool->description,
                    'parameters_schema' => $definition->parameters,
                    'arguments'         => $arguments,
                ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE))->toArray(),
            ],
            tools: [],
            metadata: [
                'chatId'      => $chatId,
                'runtimeTool' => $tool->name,
            ],
            idempotencyKey: $idempotencyKey . ':runtime-tool',
        ));

        if ($result->errorMessage !== null || $result->stopReason === 'error') {
            return sprintf(
                'Runtime tool "%s" failed: %s',
                $tool->name,
                $result->errorMessage ?? 'unknown model error',
            );
        }

        $content = self::text($result->assistantMessage);
        if ($content === '') {
            return sprintf('Runtime tool "%s" returned no content.', $tool->name);
        }

        return mb_strlen($content) <= self::RESULT_LIMIT
            ? $content
            : mb_substr($content, 0, self::RESULT_LIMIT - 24) . '... [result truncated]';
    }

    /**
     * @param array<string, mixed> $message
     */
    private static function text(array $message): string
    {
        $parts = [];
        foreach ($message['content'] ?? [] as $block) {
            if (
                is_array($block)
                && ($block['type'] ?? null) === 'text'
                && is_string($block['text'] ?? null)
            ) {
                $parts[] = $block['text'];
            }
        }

        return trim(implode('', $parts));
    }

    private function systemPrompt(RuntimeTool $tool): string
    {
        return <<<PROMPT
            Execute the generated runtime tool below.

            Tool name: {$tool->name}
            Tool description: {$tool->description}

            Return only the tool result for the parent agent. Do not address Telegram
            users directly. Follow the stored instructions using only the validated
            JSON arguments. If required information is absent, state exactly what is
            missing.

            <stored_instructions>
            {$tool->instructions}
            </stored_instructions>
            PROMPT;
    }
}
