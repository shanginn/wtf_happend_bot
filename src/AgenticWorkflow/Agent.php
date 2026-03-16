<?php

declare(strict_types=1);

namespace Bot\AgenticWorkflow;

use Bot\Agent\OpenaiUpdateTransformer;
use Bot\Agent\UpdateTransformerInterface;
use Bot\Llm\Skills\SkillInterface;
use Bot\Llm\Tools\Decision\RespondDecision;
use Bot\Telegram\TelegramUpdateViewFactory;
use Bot\Telegram\TelegramUpdateViewFactoryInterface;
use Phenogram\Bindings\Types\Interfaces\UpdateInterface;
use Shanginn\Openai\ChatCompletion\CompletionResponse;
use Shanginn\Openai\ChatCompletion\ErrorResponse;
use Shanginn\Openai\ChatCompletion\Message\MessageInterface;
use Shanginn\Openai\ChatCompletion\Tool\AbstractTool;
use Shanginn\Openai\Openai;

class Agent
{
    private readonly TelegramUpdateViewFactoryInterface $updateViewFactory;
    private readonly UpdateTransformerInterface $updateTransformer;

    public function __construct(
        public readonly Openai $openai,
        ?TelegramUpdateViewFactoryInterface $updateViewFactory = null,
        ?UpdateTransformerInterface $updateTransformer = null,
    ) {
        $this->updateViewFactory = $updateViewFactory ?? new TelegramUpdateViewFactory();
        $this->updateTransformer = $updateTransformer ?? new OpenaiUpdateTransformer();
    }

    /**
     * @param array<class-string<SkillInterface>> $skills
     */
    private static function buildSkillsPrompt(array $skills): string
    {
        if ($skills === []) {
            return '';
        }

        $parts = array_map(
            fn (string $skill) => <<<XML
            <skill name="{$skill::name()}" description="{$skill::description()}">
                {$skill::skill()}
            </skill>
            XML,
            $skills
        );

        return "\n<available_skills>\n" . implode("\n\n", $parts) . "\n</available_skills>\n";
    }

    /**
     * @param array<class-string<AbstractTool>> $tools
     */
    private static function buildToolsPrompt(array $tools): string
    {
        if ($tools === []) {
            return '';
        }

        $toolParts = array_map(
            fn (string $toolClass) => <<<XML
            <tool name="{$toolClass::getName()}" description="{$toolClass::getDescription()}"/>
            XML,
            $tools
        );

        return "\n<available_tools>\n" . implode("\n\n", $toolParts) . "\n</available_tools>\n";
    }

    /**
     * @param array<class-string<AbstractTool>> $tools
     * @param array<class-string<SkillInterface>> $skills
     */
    private static function systemPrompt(array $tools, array $skills): string
    {
        $now = date('Y-m-d H:i');
        $skillsPrompt = self::buildSkillsPrompt($skills);
        $toolsPrompt = self::buildToolsPrompt($tools);

        return <<<HTML
        You are a helpful AI assistant integrated into a Telegram group chat. You participate only when it helps.

        Now is {$now}.

        You have access to the following skills and tools.

        $toolsPrompt
        $skillsPrompt

        <agent_loop>
        For every batch of incoming Telegram updates:
        1. Understand the new messages in the context of the chat history.
        2. Use tools if you need more information or need to perform an action.
        3. Finish by calling the `respond_decision` tool.

        Rules for `respond_decision`:
        - If the bot should stay silent, call it with `shouldRespond=false`.
        - If the bot should answer, call it with `shouldRespond=true` and put the final user-facing message into `response`.
        - Keep `reason` short and concrete.
        - Do not call `respond_decision` until you are done with every other tool you need.

        If no tool use is needed, still finish with `respond_decision`.
        </agent_loop>
        HTML;
    }

    /**
     * @param array<UpdateInterface> $updates
     * @return array<MessageInterface>
     */
    public function transformUpdates(array $updates): array
    {
        return array_map(
            fn (UpdateInterface $update): MessageInterface => $this->updateTransformer->toChatUserMessage(
                $this->updateViewFactory->create($update)
            ),
            $updates,
        );
    }

    /**
     * @param array<MessageInterface> $history
     * @param array<class-string<AbstractTool>> $tools
     * @param array<class-string<SkillInterface>> $skills
     */
    public function complete(
        array $history,
        array $tools = [],
        array $skills = [],
    ): CompletionResponse|ErrorResponse {
        if ($history === []) {
            return new ErrorResponse(
                message: 'No messages to process.',
                type: null,
                param: null,
                code: null,
                rawResponse: '',
            );
        }

        if (!in_array(RespondDecision::class, $tools, true)) {
            $tools[] = RespondDecision::class;
        }

        return $this->openai->completion(
            messages: $history,
            system: self::systemPrompt($tools, $skills),
            tools: $tools,
        );
    }

    /**
     * @param array<UpdateInterface> $updates
     * @param array<MessageInterface> $history
     * @param array<class-string<AbstractTool>> $tools
     * @param array<class-string<SkillInterface>> $skills
     */
    public function processUpdates(
        array $updates,
        array $history = [],
        array $tools = [],
        array $skills = [],
    ): CompletionResponse|ErrorResponse {
        return $this->complete(
            history: [...$history, ...$this->transformUpdates($updates)],
            tools: $tools,
            skills: $skills,
        );
    }
}
