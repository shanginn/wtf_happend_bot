<?php

declare(strict_types=1);

namespace Bot\AgenticWorkflow;

use Bot\Llm\Skills\RelevantMemoriesSkill;
use Bot\Llm\Skills\SkillInterface;
use Shanginn\Openai\ChatCompletion\CompletionResponse;
use Shanginn\Openai\ChatCompletion\ErrorResponse;
use Shanginn\Openai\ChatCompletion\Message\MessageInterface;
use Shanginn\Openai\ChatCompletion\Message\UserMessage;
use Shanginn\Openai\ChatCompletion\Tool\AbstractTool;

final class ResponseAgent extends AbstractAgent
{
    /**
     * @param array<class-string<SkillInterface>> $skills
     */
    private static function relevantMemoriesSystemPrompt(array $skills): string
    {
        $now = date('Y-m-d H:i');
        $skillsPrompt = self::buildSkillsPrompt($skills);

        return <<<TEXT
        You are selecting persistent participant memories for the response agent of a Telegram bot.

        Now is {$now}.

        {$skillsPrompt}

        Use the full working memory to understand the current request.
        You will also receive a full dump of saved participant memories.
        Return only the memories that materially help with the next reply.
        Do not invent new memories.
        If nothing is useful, reply exactly: No relevant memories.
        TEXT;
    }

    /**
     * @param array<class-string<AbstractTool>> $tools
     * @param array<class-string<SkillInterface>> $skills
     */
    private static function responseSystemPrompt(array $tools, array $skills): string
    {
        $now = date('Y-m-d H:i');
        $skillsPrompt = self::buildSkillsPrompt($skills);
        $toolsPrompt = self::buildToolsPrompt($tools);

        return <<<TEXT
        You are the response agent for a Telegram group chat bot.

        A separate decision agent already decided that the bot should respond.
        Now is {$now}.

        You have access to the following skills and tools.

        {$toolsPrompt}
        {$skillsPrompt}

        <response_policy>
        - Focus on the best helpful reply to the latest user messages.
        - Use tools if you need more information.
        - Respond in the same language as the user unless the chat context strongly suggests otherwise.
        - Keep the reply concise unless the user asked for depth.
        - Do not explain the internal decision process.
        </response_policy>
        TEXT;
    }

    /**
     * @param array<MessageInterface> $history
     * @param array<class-string<SkillInterface>> $skills
     */
    public function recollectRelevantMemories(
        array $history,
        string $allMemories,
        array $skills = [RelevantMemoriesSkill::class],
    ): CompletionResponse|ErrorResponse {
        if ($history === []) {
            return $this->emptyHistoryError();
        }

        return $this->openai->completion(
            messages: [
                ...$history,
                new UserMessage(
                    "All saved participant memories:\n{$allMemories}\n\nReturn only the memories relevant for the next reply.",
                ),
            ],
            system: self::relevantMemoriesSystemPrompt($skills),
        );
    }

    /**
     * @param array<MessageInterface> $history
     * @param array<class-string<AbstractTool>> $tools
     * @param array<class-string<SkillInterface>> $skills
     */
    public function respond(
        array $history,
        array $tools = [],
        array $skills = [],
    ): CompletionResponse|ErrorResponse {
        if ($history === []) {
            return $this->emptyHistoryError();
        }

        return $this->openai->completion(
            messages: $history,
            system: self::responseSystemPrompt($tools, $skills),
            tools: $tools,
        );
    }
}
