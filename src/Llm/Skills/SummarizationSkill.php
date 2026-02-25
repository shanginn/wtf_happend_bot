<?php

declare(strict_types=1);

namespace Bot\Llm\Skills;

class SummarizationSkill implements SkillInterface
{
    public static function name(): string
    {
        return "summarization";
    }

    public static function description(): string
    {
        return <<<TEXT
            Generates concise summaries of chat conversations. Use when the user asks
            to summarize the chat, says "what happened", uses /wtf command, or wants
            an overview of recent discussions.
            TEXT;
    }

    public static function skill(): string
    {
        return <<<MD
            # Chat Summarization Skill

            ## Goal
            Process a chat log, identify distinct conversations, and generate engaging summaries.

            ## Guidelines for Summarization

            0. **Language Fidelity (CRITICAL):**
               * The summary for **each** conversation thread **must** be written in the *same language* predominantly used within that specific thread.

            1. **Conversation Segmentation:**
               * Analyze the provided chat log to identify distinct conversation threads.
               * A new thread might be indicated by:
                 * A significant time gap since the last message (e.g., several hours, a day).
                 * A clear and abrupt shift in the main topic of discussion.
                 * A natural conclusion of a prior topic followed by a new initiation.
               * **Generate a separate, engaging summary for each identified conversation thread.**

            2. **Content Focus:**
               * Focus on the main topics and key points discussed within that specific conversation thread.
               * When topics involve technical concepts, problems, or decisions, add brief contextual insights when helpful.

            3. **Key Information:**
               * Highlight any important decisions made, actions agreed upon or taken, and significant questions raised.
               * If discussions touch on problems or challenges, note potential implications when relevant.

            4. **Be Objective and Factual:**
               * Provide a neutral, factual description of what was said.
               * Do not add moral or ethical judgment.
               * Maintain the original tone and emotional context when relevant.

            5. **Conciseness with Depth:**
               * Keep each summary reasonably concise, but include important details.
               * Aim for summaries that are both quick to read and genuinely useful.

            6. **Participant Identification:**
               * Include relevant names/usernames (e.g., `@username` or `user12345`) of participants who made key contributions.

            7. **Output Structure:**
               * Present summaries chronologically based on the start time of each thread.
               * Clearly delineate each summary.
            MD;
    }
}
