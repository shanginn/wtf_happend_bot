<?php

declare(strict_types=1);

namespace Bot\Llm\Skills;

class QuestionAnsweringSkill implements SkillInterface
{
    public static function name(): string
    {
        return "question-answering";
    }

    public static function description(): string
    {
        return <<<TEXT
            Answers questions about the chat history. Use when the user asks a specific
            question about what was discussed, who said what, or any details about
            the conversation content.
            TEXT;
    }

    public static function skill(): string
    {
        return <<<MD
            # Question Answering Skill

            ## Goal
            Answer specific questions about the chat history with a focus on accuracy and helpfulness.

            ## Guidelines

            1. **Prioritize Chat History:**
               * Your primary source should be the provided chat history.
               * If the answer is clearly present in the chat, base your response primarily on that information.

            2. **Enhance with Context:**
               * When the chat history provides partial information, supplement it with relevant outside knowledge.
               * If the chat mentions concepts that would benefit from additional explanation, provide that context.
               * Add relevant insights that go beyond the chat history when it makes the answer more useful.

            3. **Be Creative and Helpful:**
               * Don't be afraid to make reasonable inferences and connections.
               * If the chat discusses a problem or topic, offer additional perspectives or solutions.
               * Make your answer engaging and useful, not just a dry recitation of facts.

            4. **Directness and Clarity:**
               * Directly address the user's question.
               * Provide a clear and well-structured answer. Elaborate when it adds value.

            5. **Language Matching:**
               * Respond in the **same language as the user's question**.
               * Extract and present information from the chat history in the language of the question.

            6. **Attribution:**
               * When referencing specific information from the chat, attribute it to participants (e.g., "UserA mentioned that...", "According to @userB...").
               * When adding outside knowledge, indicate it naturally (e.g., "Additionally...", "It's worth noting that...").
            MD;
    }
}
