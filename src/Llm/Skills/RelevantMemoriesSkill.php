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
            Selects only the smallest subset of participant memories that directly
            affects the next reply, excluding weakly related or redundant memories.
            TEXT;
    }

    public static function skill(): string
    {
        return <<<MD
            # Relevant Memories Skill

            ## Goal
            Given the current working memory and a full dump of saved participant memories,
            return only the memories that directly change the next assistant reply.

            ## Guidelines

            1. **Be Selective:**
               * Include only memories that materially change correctness, specificity,
                 or personalization of the next response.
               * Exclude unrelated, weak, stale, background, or redundant memories.
               * When multiple memories overlap, keep only the strongest one.

            2. **Prioritize Durable Facts:**
               * Prefer memories about identities, preferences, expertise, roles,
                 ongoing projects, and stable constraints.

            3. **Usefulness Standard:**
               * Keep a memory only if it changes the answer's correctness, targeting,
                 wording, or interpretation of the conversation.

            4. **Summaries and Chat Questions:**
               * When the user asks for a summary or about prior discussion, include
                 memories that are necessary to identify participants or interpret references.

            5. **No Invention:**
               * Do not create new memories.
               * Do not rewrite, soften, merge, or expand facts beyond the provided memory dump.

            6. **Output Format:**
               * If nothing is useful, reply exactly: `No relevant memories.`
               * Otherwise return a concise bullet list.
               * Return no preamble, explanation, or summary.
               * Preserve participant labels and include the stored memory, quote, and context.
            MD;
    }
}
