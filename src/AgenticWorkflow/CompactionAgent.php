<?php

declare(strict_types=1);

namespace Bot\AgenticWorkflow;

use Shanginn\Openai\ChatCompletion\CompletionResponse;
use Shanginn\Openai\ChatCompletion\ErrorResponse;
use Shanginn\Openai\ChatCompletion\Message\MessageInterface;
use Shanginn\Openai\ChatCompletion\Message\UserMessage;

final class CompactionAgent extends AbstractAgent
{
    private static function systemPrompt(): string
    {
        $now = date('Y-m-d H:i');

        return <<<TEXT
        You are the working memory compaction agent for a Telegram group chat bot.

        Now is {$now}.

        <goal>
        Rewrite older conversation state into a compact context block that can replace the raw messages.
        Preserve only information that is likely to matter later:
        - unresolved requests, follow-ups, and pending work
        - active topics and important conclusions
        - important tool results, links, or decisions worth carrying forward
        - stable constraints or preferences that are relevant to this chat but may not belong in durable participant memory
        - interpersonal context only when it materially affects future replies
        </goal>

        <drop>
        Drop filler, greetings, repeated back-and-forth, stale dead ends, one-off chatter,
        and wording details that do not need to survive after the raw messages are removed.
        </drop>

        <output_contract>
        - Return only the updated compacted context.
        - Keep it concise and information-dense.
        - Prefer short bullets.
        - Keep participant names or handles explicit when relevant.
        - Do not mention that this is a summary, compaction, or memory rewrite.
        - If nothing useful should be carried forward, reply exactly: No compacted context.
        </output_contract>
        TEXT;
    }

    /**
     * @param array<MessageInterface> $history
     */
    public function compact(
        array $history,
        string $existingCompactedContext = '',
    ): CompletionResponse|ErrorResponse {
        if ($history === []) {
            return $this->emptyHistoryError();
        }

        $existingCompactedContext = trim($existingCompactedContext);
        $existingCompactedContext = $existingCompactedContext === ''
            ? 'No compacted context.'
            : $existingCompactedContext;

        return $this->openai->completion(
            messages: [
                ...$history,
                new UserMessage(
                    <<<TEXT
                    Existing compacted context:
                    {$existingCompactedContext}

                    Rewrite it into an updated compacted context that absorbs the older messages above.
                    Keep only the information that should survive after those raw messages are removed.
                    Return only the new compacted context.
                    TEXT,
                ),
            ],
            system: self::systemPrompt(),
        );
    }
}
