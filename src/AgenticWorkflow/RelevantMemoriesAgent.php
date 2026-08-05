<?php

declare(strict_types=1);

namespace Bot\AgenticWorkflow;

use Bot\Llm\Skills\RelevantMemoriesSkill;
use Bot\Llm\Skills\SkillInterface;
use Shanginn\Openai\ChatCompletion\CompletionResponse;
use Shanginn\Openai\ChatCompletion\ErrorResponse;
use Shanginn\Openai\ChatCompletion\Message\AssistantMessage;
use Shanginn\Openai\ChatCompletion\Message\MessageInterface;
use Shanginn\Openai\ChatCompletion\Message\UserMessage;

final class RelevantMemoriesAgent extends AbstractAgent
{
    private const int HISTORY_LIMIT = 10;
    private const string NO_RELEVANT_MEMORIES = 'No relevant memories.';

    /**
     * @param array<class-string<SkillInterface>> $skills
     */
    private static function systemPrompt(array $skills): string
    {
        $now = date('Y-m-d H:i');
        $skillsPrompt = self::buildSkillsPrompt($skills);

        return <<<TEXT
        You are the relevant memories agent for a Telegram group chat bot.

        Now is {$now}.

        {$skillsPrompt}

        <task>
        The previous messages are the working memory for the pending reply.
        Your only job is to filter the saved participant memory dump and return the
        smallest subset that directly changes the next reply.
        </task>

        <selection_rules>
        - Start from the latest user request and the immediate surrounding context.
        - Keep a memory only if omitting it would likely make the next reply less correct,
          less specific, or less appropriately personalized.
        - Exclude background facts, broad biographies, trivia, stale context, weak
          associations, duplicates, and anything that is merely nice to know.
        - If several memories overlap, keep only the strongest one that best supports
          the next reply.
        - Do not invent, merge, soften, or expand any memory beyond the stored text.
        - Never answer the user, call a tool, imitate a tool call, or emit tool-call markup.
        - Never explain your selection process.
        </selection_rules>

        <output_contract>
        - If nothing qualifies, reply exactly: No relevant memories.
        - Otherwise output only a concise bullet list.
        - Each bullet must preserve the stored participant label, memory, quote, and
          context in this exact shape:
          - <participant> | memory: <memory> | quote: <quote> | context: <context>
        - Return only the selected memories. No preamble. No summary. No commentary.
        </output_contract>
        TEXT;
    }

    /**
     * @param array<MessageInterface> $history
     * @param array<class-string<SkillInterface>> $skills
     */
    public function recollect(
        array $history,
        string $allMemories,
        array $skills = [RelevantMemoriesSkill::class],
    ): CompletionResponse|ErrorResponse {
        if ($history === []) {
            return $this->emptyHistoryError();
        }

        $result = $this->openai->completion(
            messages: [
                ...self::selectionHistory($history),
                new UserMessage(
                    <<<TEXT
                    Filter the saved participant memories below for the next reply.

                    Selection threshold:
                    - Include a memory only if it directly affects the reply you would send now.
                    - Drop anything merely related, generally useful, weakly connected, or redundant.
                    - If multiple memories say nearly the same thing, keep only the strongest one.
                    - If none qualify, reply exactly: No relevant memories.

                    {$allMemories}
                    TEXT,
                ),
            ],
            system: self::systemPrompt($skills),
            extraBody: ['thinking' => ['type' => 'disabled']]
        );

        if ($result instanceof ErrorResponse) {
            return $result;
        }

        $message = $result->choices[0]->message ?? null;
        if ($message instanceof AssistantMessage) {
            $message->content = self::validatedSelection($message->content, $allMemories);
            $message->toolCalls = null;
        }

        return $result;
    }

    /**
     * The selector only needs the participant-authored conversation. Assistant,
     * tool, and compacted system messages teach it to imitate the response agent.
     *
     * @param array<MessageInterface> $history
     * @return array<UserMessage>
     */
    private static function selectionHistory(array $history): array
    {
        $userMessages = array_values(array_filter(
            $history,
            static fn (MessageInterface $message): bool => $message instanceof UserMessage,
        ));

        return array_slice($userMessages, -self::HISTORY_LIMIT);
    }

    private static function validatedSelection(?string $content, string $allMemories): string
    {
        $selection = trim($content ?? '');
        if ($selection === self::NO_RELEVANT_MEMORIES) {
            return self::NO_RELEVANT_MEMORIES;
        }

        $allowedMemories = self::allowedMemories($allMemories);
        if ($selection === '' || $allowedMemories === []) {
            return self::NO_RELEVANT_MEMORIES;
        }

        $selectedMemories = [];

        foreach (preg_split('/\R/u', $selection) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || !isset($allowedMemories[$line])) {
                return self::NO_RELEVANT_MEMORIES;
            }

            $selectedMemories[$line] = true;
        }

        return $selectedMemories === []
            ? self::NO_RELEVANT_MEMORIES
            : implode("\n", array_keys($selectedMemories));
    }

    /**
     * @return array<string, true>
     */
    private static function allowedMemories(string $allMemories): array
    {
        $allowedMemories = [];

        foreach (preg_split('/\R/u', $allMemories) ?: [] as $line) {
            $line = trim($line);

            if (!str_starts_with($line, '- ')) {
                continue;
            }

            $canonical = preg_replace('/ \| updated: \d{4}-\d{2}-\d{2}$/u', '', $line);
            if (!is_string($canonical) || $canonical === '- ') {
                continue;
            }

            $allowedMemories[$canonical] = true;
        }

        return $allowedMemories;
    }
}
