<?php

declare(strict_types=1);

namespace Bot\AgenticWorkflow;

use Bot\Llm\Runtime\RuntimeToolDefinition;
use Bot\Llm\Skills\SkillInterface;
use Bot\Llm\Tools\Decision\RespondDecision;
use Shanginn\Openai\ChatCompletion\CompletionRequest\ToolInterface;
use Shanginn\Openai\ChatCompletion\CompletionResponse;
use Shanginn\Openai\ChatCompletion\ErrorResponse;
use Shanginn\Openai\ChatCompletion\Message\MessageInterface;
use Shanginn\Openai\ChatCompletion\Tool\AbstractTool;

final class DecisionAgent extends AbstractAgent
{
    /**
     * @param array<class-string<AbstractTool>|RuntimeToolDefinition> $tools
     * @param array<class-string<SkillInterface>> $skills
     */
    private static function systemPrompt(array $tools, array $skills): string
    {
        $now = date('Y-m-d H:i');
        $skillsPrompt = self::buildSkillsPrompt($skills);
        $toolsPrompt = self::buildToolsPrompt($tools);

        return <<<TEXT
        You are the decision agent for a Telegram group chat bot. Your job is only to decide whether the bot should respond to the latest messages.

        Now is {$now}.

        You have access to the following skills and tools.

        {$toolsPrompt}
        {$skillsPrompt}

        <memory_usage>
        Persistent memory is for durable, reusable facts about chat participants.
        Good memories: real names, expertise, preferences, roles, ongoing projects, and stable constraints.
        Bad memories: one-off requests, temporary mood, obvious short-lived context, or weak speculation.
        When saving memory, store only:
        - a computed memory sentence
        - a short direct quote supporting it
        - brief surrounding context explaining the quote
        </memory_usage>

        <decision_contract>
        For every batch of incoming Telegram updates:
        1. Understand the new messages in the context of the chat history.
        2. Use memory tool only if you need to save durable memory.
        3. Finish by calling the `respond_decision` tool. respond_decision IS MANDATORY! ALWAYS CALL THE respond_decision HERE!

        Rules:
        - Some listed tools may be response-phase runtime tools created in this chat. Use them only to decide whether a message is asking for bot functionality.
        - If a user invokes or asks about a listed runtime tool, set `shouldRespond=true` so the response agent can execute or explain it.
        - Do not call runtime/generated tools, runtime capability tools, Telegram tools, or chat search tools in this decision phase.
        - The only tools you may call in this phase are `save_memory` for durable participant facts and `respond_decision` for the terminal decision.
        - A decision is mandatory for every completion.
        - Every successful completion must include a `respond_decision` tool call.
        - A completion without `respond_decision` is invalid.
        - Your output is internal only. Never write the final Telegram reply in assistant text.
        - Never finish with plain assistant text instead of the tool call.
        - Set `shouldRespond=true` only when the bot should send a follow-up reply.
        - Set `shouldRespond=false` when the bot should stay silent, but still call `respond_decision`.
        - Put a short concrete summary into `overview`.
        - Do not call `respond_decision` until you are done with every other tool you need.
        - If you call other tools first, the final tool call must be `respond_decision`.
        - If no other tool use is needed, still call `respond_decision`.
        - If the situation is ambiguous, uncertain, or borderline, make the best decision you can and still call `respond_decision`.
        </decision_contract>

        NO TEXT RESPONSE HERE!!!! YOUR ONLY JOB IS TO DECIDE IS THE MESSAGES WORTH REPLYING AT ALL
        TEXT;
    }

    /**
     * @param array<MessageInterface> $history
     * @param array<class-string<AbstractTool>|RuntimeToolDefinition> $tools
     * @param array<class-string<SkillInterface>> $skills
     */
    public function decide(
        array $history,
        array $tools = [],
        array $skills = [],
    ): CompletionResponse|ErrorResponse {
        if ($history === []) {
            return $this->emptyHistoryError();
        }

        if (!in_array(RespondDecision::class, $tools, true)) {
            $tools[] = RespondDecision::class;
        }

        return $this->openai->completion(
            messages: $history,
            system: self::systemPrompt($tools, $skills),
            tools: self::callableDecisionTools($tools),
            extraBody: ['thinking' => ['type' => 'disabled']]
        );
    }

    /**
     * @param array<class-string<AbstractTool>|RuntimeToolDefinition> $tools
     * @return array<class-string<ToolInterface>>
     */
    private static function callableDecisionTools(array $tools): array
    {
        return array_values(array_filter(
            $tools,
            static fn (string|RuntimeToolDefinition $tool): bool => is_string($tool)
                && in_array($tool, AgenticToolset::DECISION_TOOLS, true)
                && is_a($tool, ToolInterface::class, true),
        ));
    }
}
