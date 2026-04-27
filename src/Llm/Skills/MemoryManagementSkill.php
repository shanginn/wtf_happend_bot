<?php

declare(strict_types=1);

namespace Bot\Llm\Skills;

class MemoryManagementSkill implements SkillInterface
{
    public static function name(): string
    {
        return 'memory-management';
    }

    public static function description(): string
    {
        return <<<TEXT
            Manages durable participant memories by saving, recalling, correcting,
            and forgetting stable facts with conservative selectors.
            TEXT;
    }

    public static function skill(): string
    {
        return <<<MD
            # Memory Management Skill

            ## Goal
            Keep saved participant memories useful, accurate, and respectful.

            ## Guidelines

            1. **Save Stable Facts:**
               * Save durable facts such as names, roles, expertise, preferences,
                 ongoing projects, and long-lived constraints.
               * Do not save temporary moods, one-off requests, gossip, speculation,
                 or sensitive personal data unless the user explicitly asks you to remember it.

            2. **Recall Before Answering Memory Questions:**
               * Use `recall_memory` when a user asks what is remembered, asks about
                 a participant's saved preferences, or asks you to edit a memory and
                 the target is not obvious.
               * Prefer `memory_id` from `recall_memory` when calling update or forget tools.

            3. **Correct Instead Of Duplicating:**
               * Use `update_memory` when a user corrects an existing durable fact
                 or says that a saved memory is stale.
               * If the target may match several memories, recall first and then use
                 the exact `memory_id`.

            4. **Forget Carefully:**
               * Use `forget_memory` when a user asks you to forget a saved fact.
               * Delete all memories for a participant only when the user explicitly
                 requests that broad deletion.
               * If deletion is ambiguous, recall matching memories and ask which one.

            5. **Acknowledge Tool Results:**
               * After a memory tool changes data, briefly tell the user what changed.
               * If a tool reports ambiguity or no match, explain the next concrete step.
            MD;
    }
}
