<?php

declare(strict_types=1);

namespace Bot\RouterWorkflow;

use Bot\Llm\Skills\SkillInterface;
use Bot\Llm\Tools\Decision\RespondDecision;
use Bot\Telegram\Update;
use Carbon\CarbonInterval;
use Phenogram\Bindings\SerializerInterface;
use Shanginn\Openai\ChatCompletion\CompletionRequest\ToolChoice;
use Shanginn\Openai\ChatCompletion\CompletionResponse;
use Shanginn\Openai\ChatCompletion\ErrorResponse;
use Shanginn\Openai\ChatCompletion\Message\Assistant\KnownFunctionCall;
use Shanginn\Openai\ChatCompletion\Message\UserMessage;
use Shanginn\Openai\Openai;
use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;
use Temporal\Activity\ActivityOptions;
use Temporal\Common\RetryOptions;
use Temporal\Internal\Workflow\ActivityProxy;
use Temporal\Workflow;

#[ActivityInterface(prefix: 'Router.')]
class RouterActivity
{
    public function __construct(
        private Openai $openai,
        private SerializerInterface $telegramSerializer,
    ) {}

    public static function getDefinition(): ActivityProxy|self
    {
        return Workflow::newActivityStub(
            self::class,
            ActivityOptions::new()
                ->withStartToCloseTimeout(CarbonInterval::minute())
                ->withRetryOptions(
                    RetryOptions::new()->withNonRetryableExceptions([])
                )
        );
    }

    #[ActivityMethod]
    public function shouldRespond(array $updates): bool
    {
        $updatesJson = json_encode($this->telegramSerializer->serialize($updates));

        $decisionPrompt = <<<PROMPT
        You are a decision-making component for a Telegram bot. Analyze the incoming messages and decide if the bot should respond.

        Call the respond_decision tool with your decision.

        Rules for responding (should_respond = true):
        - Direct messages to the bot (private chat)
        - Bot is mentioned by username
        - Message is a reply to bot's message
        - User asks a question directed at the group that the bot could help with
        - User explicitly requests help, summary, or bot functionality
        - Command like /wtf, /help, /start

        Rules for NOT responding (should_respond = false):
        - Regular group chat conversation between users
        - Bot is not mentioned or involved
        - Just casual chat, jokes, or off-topic discussion
        - Users talking to each other without needing bot assistance

        Telegram updates:
        {$updatesJson}
        PROMPT;

        $res = $this->openai->completion(
            messages: [new UserMessage($decisionPrompt)],
            tools: [RespondDecision::class],
            toolChoice: ToolChoice::useTool(RespondDecision::class),
            temperature: 0.0,
            maxTokens: 100,
        );

        if ($res instanceof ErrorResponse) {
            return false;
        }

        $toolCalls = $res->choices[0]->message->toolCalls ?? [];
        
        foreach ($toolCalls as $toolCall) {
            if ($toolCall instanceof KnownFunctionCall && $toolCall->tool === RespondDecision::class) {
                /** @var RespondDecision $decision */
                $decision = $toolCall->arguments;
                return $decision->shouldRespond;
            }
        }

        return false;
    }

    /**
     * @param array<Update> $updates
     * @param array $history
     * @return ErrorResponse|CompletionResponse
     */
    #[ActivityMethod]
    public function processUpdate(array $updates, array $history): ErrorResponse|CompletionResponse
    {
        /** @var array<class-string<SkillInterface>> $skills */
        $skills = [
            \Bot\Llm\Skills\SummarizationSkill::class,
            \Bot\Llm\Skills\QuestionAnsweringSkill::class,
        ];

        $skillsContent = implode(
            "\n\n---\n\n",
            array_map(
                fn(string $skill) => <<<HTML
                <skill name="{$skill::name()}" description="{$skill::description()}">
                    {$skill::skill()}
                </skill>
                HTML,
                $skills
            )
        );

        $systemPrompt = self::systemPrompt() .
            "\n<available_skills>\n" .
            $skillsContent .
            "\n</available_skills>\n";

        $res = $this->openai->completion(
            messages: array_merge(
                $history,
                [
                    new UserMessage(
                        content: json_encode($this->telegramSerializer->serialize($updates))
                    )
                ]
            ),
            system: $systemPrompt,
            temperature: 0.7,
        );

        return $res;
    }

    public static function systemPrompt(): string
    {
        $today = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $todayStr = $today->format('Y-m-d');

        return <<<HTML
        You are a helpful AI assistant integrated into a Telegram chat. You can see chat messages and interact with users naturally.

        Today's date is {$todayStr}.

        <identity>
        You are conversational, helpful, and intelligent. You can:
        - Have natural conversations on any topic
        - Answer questions and provide explanations
        - Help with tasks, brainstorming, and problem-solving
        - Analyze and summarize chat history when requested
        - Maintain context from the ongoing conversation
        </identity>

        <capabilities>
        You receive Telegram chat updates as JSON. You can see:
        - Message text and metadata
        - User information (names, usernames, IDs)
        - Timestamps and chat details
        - Photos, documents, and other media (via file_id references)

        You respond as text messages back to the chat. Be mindful of Telegram's formatting:
        - Use Markdown for formatting when helpful
        - Keep messages concise and readable
        - For long responses, consider breaking into multiple messages
        </capabilities>

        <communication_style>
        - Be natural and conversational, not robotic
        - Match your tone to the conversation context
        - Respond in the same language as the user
        - Be helpful but don't be overly verbose
        - Use humor when appropriate but stay professional
        - Acknowledge when you don't know something
        - Ask clarifying questions when needed
        </communication_style>

        <special_requests>
        When users ask you to:
        - "Summarize the chat" or "what happened" or use /wtf command → Use the Summarization skill
        - Ask questions about past messages → Use the Question Answering skill
        - General conversation → Respond naturally
        - Help with tasks → Be helpful and proactive
        </special_requests>

        <context_awareness>
        You receive batches of updates. Each message includes:
        - update_id: Unique identifier
        - message: The actual message content
        - from: Sender information
        - chat: Chat information
        - date: Unix timestamp

        Track conversation flow and refer back to earlier messages when relevant.
        Remember what different participants have said throughout the conversation.
        </context_awareness>

        <limitations>
        - You cannot send images or files, only text
        - You cannot access external URLs or services unless tools are provided
        - You cannot remember conversations across different chats
        - Your context is limited to messages provided in the current session
        </limitations>
        HTML;
    }
}
