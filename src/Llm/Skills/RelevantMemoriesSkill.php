<?php

declare(strict_types=1);

namespace Bot\Llm\Skills;

class RelevantMemoriesSkill implements SkillInterface
{
    public static function name(): string
    {
        return 'relevant-memories';
    }

    public static function description(): string
    {
        return <<<TEXT
            Selects only the participant memories that matter for the next reply.
            Use when the full memory set is available and you need to narrow it down
            to the memories that actually help answer the current request.
            TEXT;
    }

    public static function skill(): string
    {
        return <<<MD
            # Relevant Memories Skill

            ## Goal
            Given the current working memory and a full dump of saved participant memories,
            return only the memories that are useful for the next assistant reply.

            ## Guidelines

            1. **Be Selective:**
               * Include only memories that materially improve the next response.
               * Exclude unrelated, weak, stale, or redundant memories.

            2. **Prioritize Durable Facts:**
               * Prefer memories about identities, preferences, expertise, roles,
                 ongoing projects, and stable constraints.

            3. **Usefulness Standard:**
               * Keep a memory only if it changes the answer's correctness, targeting,
                 wording, or interpretation of the conversation.

            4. **Summaries and Chat Questions:**
               * When the user asks for a summary or about prior discussion, include
                 memories that help identify participants or interpret references.

            5. **No Invention:**
               * Do not create new memories.
               * Do not rewrite facts that are not grounded in the provided memory dump.

            6. **Output Format:**
               * If nothing is useful, reply exactly: `No relevant memories.`
               * Otherwise return a concise bullet list.
               * Preserve participant labels and include the stored memory, quote, and context.
            MD;
    }
}
