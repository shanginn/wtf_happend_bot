<?php

declare(strict_types=1);

namespace Bot\Llm\Skills;

use Phenogram\Bindings\ApiInterface;

class ImageAnalysisSkill implements SkillInterface
{
    public static function name(): string
    {
        return "image-analysis";
    }

    public static function description(): string
    {
        return <<<TEXT
            Analyzes images shared in the chat. Use when users share photos or images
            and want to know what's in them, ask questions about visual content, or need
            information extracted from images.
            TEXT;
    }

    public static function skill(): string
    {
        return <<<MD
            # Image Analysis Skill

            ## Goal
            Analyze and describe images shared in the chat, extract relevant information, and answer questions about visual content.

            ## How Images Are Provided
            Images are included **inline in the conversation context** as image content parts — you can see them directly. No tool call is required to access them.

            ## Guidelines

            1. **Description Quality:**
               * Provide detailed, accurate descriptions of what's visible
               * Note text, labels, UI elements, or any readable content
               * Describe colors, layout, objects, people, and setting
               * If unclear about something, state that explicitly

            2. **Answer Questions:**
               * Answer specific questions about the image accurately
               * If the user asks "what's in this image" — provide a comprehensive description
               * If the user asks about specific details — focus on those

            3. **Language:**
               * Respond in the **same language as the user's question**
               * Match the tone of the conversation

            4. **Helpfulness:**
               * Offer additional context or insights when relevant
               * Connect image content to the conversation topic
               * If image quality is poor, mention limitations honestly
            MD;
    }
}
