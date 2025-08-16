<?php

declare(strict_types=1);

namespace Bot\Tool;

use Shanginn\Openai\ChatCompletion\CompletionRequest\JsonSchema\AbstractJsonSchema;
use Shanginn\Openai\ChatCompletion\CompletionRequest\JsonSchema\OpenaiSchema;
use Shanginn\Openai\ChatCompletion\Message\UserMessage;
use Shanginn\Openai\ChatCompletion\Message\User\TextContentPart;
use Shanginn\Openai\Exceptions\OpenaiErrorResponseException;
use Shanginn\Openai\Exceptions\OpenaiWrongSchemaException;
use Shanginn\Openai\OpenaiSimple;
use Spiral\JsonSchemaGenerator\Attribute\Field;
use RuntimeException;

#[OpenaiSchema(
    name: 'bot_routing_decision',
    description: 'Decision about what action the bot should take for a message.',
    isStrict: true
)]
class BotRoutingDecision extends AbstractJsonSchema
{
    public function __construct(
        #[Field(
            title: 'Action',
            description: 'The action the bot should take: "reply" for responding to user, "summarize" for creating summary, "silent" for no action.',
//            enum: ['reply', 'summarize', 'silent']
        )]
        public string $action,

        #[Field(
            title: 'Reason',
            description: 'Brief explanation why this action was chosen.'
        )]
        public string $reason,

        #[Field(
            title: 'Confidence',
            description: 'Confidence level in this decision from 1-10.'
        )]
        public int $confidence,
    ) {}
}

class RouterTool
{
    public function __construct(
        private readonly OpenaiSimple $openaiSimple,
    ) {}

    /**
     * Analyzes a message and decides what action the bot should take
     *
     * @param string      $messageText
     * @param string      $chatContext
     * @param string|null $username
     * @param bool        $isReplyToBot
     * @param int         $messagesSinceLastAction
     *
     * @return BotRoutingDecision
     * @throws OpenaiErrorResponseException
     * @throws OpenaiWrongSchemaException
     * @throws RuntimeException
     */
    public function routeMessage(
        string $messageText,
        string $chatContext,
        ?string $username = null,
        bool $isReplyToBot = false,
        int $messagesSinceLastAction = 0
    ): BotRoutingDecision {
        $systemPrompt = <<<PROMPT
You are a routing assistant for a Telegram chat bot that can:
1. Reply to users with memory-enhanced responses 
2. Create summaries of chat conversations
3. Stay silent when not needed

Decide what action to take based on these rules:

**REPLY** when:
- User directly addresses the bot or asks a question
- Message is a reply to the bot's previous message
- User needs help or asks for information
- Message contains greeting directed at bot
- User explicitly requests assistance

**SUMMARIZE** when:
- User asks for chat summary (like "what happened", "wtf happened", etc.)
- Explicitly requested with /wtf command (but this is handled separately)
- Long discussion needs summarization

**SILENT** when:
- General chat between users not involving bot
- Simple acknowledgments, greetings between users
- Off-topic conversations
- Bot would be intrusive to respond
- Spam or low-quality content

Consider:
- Chat context and conversation flow
- Whether response would add value
- User's intent and expectations
- Number of messages since last bot action (avoid being too chatty)

Be conservative - prefer SILENT over unnecessary responses.
PROMPT;

        $userPrompt = sprintf(
            "Message: \"%s\"\nChat Context: %s\nUsername: %s\nIs Reply to Bot: %s\nMessages since last action: %d\n\nDecide action:",
            $messageText,
            $chatContext,
            $username ?? 'unknown',
            $isReplyToBot ? 'yes' : 'no',
            $messagesSinceLastAction
        );

        try {
            $result = $this->openaiSimple->generate(
                system: $systemPrompt,
                userMessage: new UserMessage(content: [new TextContentPart($userPrompt)]),
                schema: BotRoutingDecision::class,
                temperature: 0.2,
                maxTokens: 200
            );

            if (!$result instanceof BotRoutingDecision) {
                throw new RuntimeException('OpenaiSimple->generate returned an unexpected type.');
            }

            return $result;
        } catch (OpenaiErrorResponseException | OpenaiWrongSchemaException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new RuntimeException("An unexpected error occurred during routing analysis: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Quick decision for obvious cases without AI call
     *
     * @param string $messageText
     * @param bool   $isReplyToBot
     *
     * @return BotRoutingDecision|null Returns null if AI analysis is needed
     */
    public function quickRoute(string $messageText, bool $isReplyToBot): ?BotRoutingDecision
    {
        // Always reply if it's a reply to bot
        if ($isReplyToBot) {
            return new BotRoutingDecision(
                action: 'reply',
                reason: 'Message is a reply to bot',
                confidence: 10
            );
        }

        // Check for explicit summary requests
        $summaryPatterns = [
            '/wtf\s*happened/i',
            '/what\s*happened/i',
            '/summarize/i',
            '/summary/i',
        ];

        foreach ($summaryPatterns as $pattern) {
            if (preg_match($pattern, $messageText)) {
                return new BotRoutingDecision(
                    action: 'summarize',
                    reason: 'Explicit summary request detected',
                    confidence: 9
                );
            }
        }

        // Check for bot mentions or direct questions
        $botMentions = [
            '/bot\b/i',
            '/помощ/i', // help in Russian
            '/help\b/i',
            '/\?$/',    // ends with question mark
        ];

        foreach ($botMentions as $pattern) {
            if (preg_match($pattern, $messageText)) {
                return new BotRoutingDecision(
                    action: 'reply',
                    reason: 'Bot mention or question detected',
                    confidence: 8
                );
            }
        }

        // Check for obvious silent cases
        $silentPatterns = [
            '/^(ok|okay|ок|да|yes|no|нет|lol|😂|👍|👎|🙂|😊)$/i',
            '/^(hi|hello|привет|hey)$/i',
            '/^\s*$/', // empty or whitespace
        ];

        foreach ($silentPatterns as $pattern) {
            if (preg_match($pattern, $messageText)) {
                return new BotRoutingDecision(
                    action: 'silent',
                    reason: 'Simple acknowledgment or greeting',
                    confidence: 8
                );
            }
        }

        return null; // Need AI analysis
    }
}