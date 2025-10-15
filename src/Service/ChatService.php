<?php

declare(strict_types=1);

namespace Bot\Service;

use function Amp\delay;

use Bot\Entity\Message;

use Bot\Entity\SummarizationState;
use Cycle\ORM\EntityManagerInterface;
use Shanginn\Openai\ChatCompletion\Message\MessageInterface;
use Shanginn\Openai\ChatCompletion\Message\UserMessage;
use Shanginn\Openai\OpenaiSimple;
use Throwable;

// Add this

class ChatService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private Message\MessageRepository $messages,
        private SummarizationState\SummarizationStateRepository $summarizationStates,
        private OpenaiSimple $openaiSimple,
    ) {}

    /**
     * Summarizes messages since the last summarization point or based on a question.
     * Updates the summarization state.
     *
     * @param int         $chatId
     * @param int         $userId
     * @param string|null $question       optional specific question to ask the AI
     * @param ?int        $startMessageId
     *
     * @return false|string false if not enough messages, summary string otherwise, or error message
     */
    public function summarize(int $chatId, int $userId, ?int $startMessageId = null, ?string $question = null): false|string
    {
        if ($startMessageId !== null) {
            $newMessages = $this->messages->findFrom($chatId, $startMessageId, 150);
        } else {
            $state       = $this->summarizationStates->findByChatAndUserOrNew($chatId, $userId);
            $newMessages = $this->messages->findAllAfter($chatId, $state->lastSummarizedMessageId);
        }

        if (count($newMessages) < 10) {
            $newMessages = $this->messages->findLastN($chatId, 50);
        }

        $summary = $this->generateSummary($newMessages, $question);

        if (isset($state) && $summary !== null) {
            $state->lastSummarizedMessageId = end($newMessages)->messageId;

            $this->em->persist($state);
            $this->em->run();
        }

        $this->em->clean();

        return $summary ?? 'Не удалось сгенерировать ответ. Сервис может быть временно недоступен. Попробуйте позже.';
    }

    /**
     * Generates the summary using the AI.
     *
     * @param array<Message> $messages the messages to summarize
     * @param string|null    $question optional question for the AI
     *
     * @return string|null summary text on success, null on failure
     */
    private function generateSummary(array $messages, ?string $question = null): ?string
    {
        if (empty($messages) && $question === null) {
            return null;
        }

        if ($question !== null) {
            $systemPrompt = <<<'PROMPT'
                **You are an AI Question-Answering Assistant for Chat Histories.**

                Your primary function is to answer specific questions with a strong focus on the provided chat history, while also leveraging your broader knowledge to provide helpful, contextual answers.

                **When a user asks a question, you will be provided with:**
                1.  The user's **question**.
                2.  A **chat history log** relevant to the timeframe or topic of the question.

                **Follow these guidelines to formulate your answer:**

                1.  **Prioritize Chat History:**
                    *   Your primary source should be the provided chat history.
                    *   If the answer is clearly present in the chat, base your response primarily on that information.

                2.  **Enhance with Outside Knowledge:**
                    *   When the chat history provides partial information or context, feel free to supplement it with relevant outside knowledge to give a more complete, helpful answer.
                    *   If the chat history mentions concepts, technologies, or topics that would benefit from additional explanation, provide that context.
                    *   You can add relevant insights, explanations, or suggestions that go beyond the chat history when it makes the answer more useful.

                3.  **Be Creative and Helpful:**
                    *   Don't be afraid to make reasonable inferences and connections.
                    *   If the chat discusses a problem or topic, you can offer additional perspectives, solutions, or related information.
                    *   Make your answer engaging and useful, not just a dry recitation of facts.

                4.  **Directness and Clarity:**
                    *   Directly address the user's question.
                    *   Provide a clear and well-structured answer. Elaborate when it adds value.

                5.  **Language Matching:**
                    *   Respond in the **same language as the user's question**.
                    *   Extract and present information from the chat history in the language of the question.

                6.  **Attribution (When Helpful):**
                    *   When referencing specific information from the chat, attribute it to participants (e.g., "UserA mentioned that...", "According to @userB...").
                    *   When adding outside knowledge, you can indicate it naturally (e.g., "Additionally...", "It's worth noting that...", "From a technical perspective...").
                PROMPT;

            $userPrompt = "Answer the following question based on the conversation (IN THE LANGUAGE OF THE MESSAGES): {$question}";
        } else {
            $systemPrompt = <<<'PROMPT'
                **You are an Expert Chat Summarizer AI.**

                Your primary task is to process a provided chat log, which may contain multiple distinct conversations separated by significant time gaps or topic shifts. You must identify these individual conversations and generate engaging, informative summaries that focus on the chat history while enriching them with contextual insights when helpful.

                When the `/wtf` command is issued, you will receive a block of chat messages to process according to these guidelines:

                **Guidelines for Summarization:**

                0.  **Language Fidelity (CRITICAL):**
                    *   The summary for **each** conversation thread **must** be written in the *same language* predominantly used within that specific thread.
                    *   (e.g., if a conversation thread is in Russian, its summary must be in Russian. If another is in Spanish, its summary must be in Spanish.)

                1.  **Conversation Segmentation and Individual Summaries:**
                    *   Analyze the provided chat log to identify distinct conversation threads. A new thread might be indicated by:
                        *   A significant time gap since the last message (e.g., several hours, a day).
                        *   A clear and abrupt shift in the main topic of discussion.
                        *   A natural conclusion of a prior topic followed by a new initiation.
                    *   **Generate a separate, engaging summary for each identified conversation thread.**

                2.  **Content Focus (Per Summary):**
                    *   For each individual summary, focus primarily on the main topics and key points discussed *within that specific conversation thread*.
                    *   When topics involve technical concepts, problems, or decisions, feel free to add brief contextual insights or implications that make the summary more valuable.

                3.  **Key Information (Per Summary):**
                    *   Highlight any important decisions made, actions agreed upon or taken, and significant questions raised *within that thread*.
                    *   If discussions touch on problems or challenges, you can briefly note potential implications or considerations when relevant.

                4.  **Be Engaging and Insightful:**
                    *   Make summaries informative and engaging, not just dry recitations of events.
                    *   When appropriate, connect dots between different parts of the conversation.
                    *   If the chat discusses technical topics, tools, or concepts, you can add brief clarifying context to make the summary more valuable.
                    *   Feel free to highlight the tone or dynamics of the conversation when relevant (e.g., collaborative problem-solving, brainstorming, heated debate, etc.).

                5.  **Conciseness with Depth:**
                    *   Keep each summary reasonably concise, but don't sacrifice important details or helpful context for the sake of brevity.
                    *   Aim for summaries that are both quick to read and genuinely useful.

                6.  **Participant Identification (Per Summary):**
                    *   Include relevant names/usernames (e.g., `@username` or `user12345`) of participants who made key contributions, decisions, or asked important questions *within that thread*.

                7.  **Output Structure:**
                    *   Present the summaries chronologically based on the start time of each conversation thread.
                    *   Clearly delineate each summary. For example:
                        *   "**Summary for Conversation ([Date/Time Range of Thread]):**" (translate title to the language of the chat)
                        *   "---" (separator)
                        *   "**Conversation 1 (Messages from [Start Time] to [End Time]):**" (translate title to the language of the chat)
                    *   If no substantive discussion is found in a potential segment (e.g., only greetings, brief acknowledgments), you may either omit a summary for it or state "No substantive discussion to summarize for this period."

                8.  **Trigger:**
                    *   The `/wtf` command initiates this entire process for the provided chat log segment.

                **Example Scenario:**

                Imagine a log contains:
                *   UserA, UserB discussing Project X on Monday.
                *   *Silence for 2 days*
                *   UserA, UserC discussing a personal issue on Wednesday.
                *   UserB asking a quick question about Project X later on Wednesday, getting a quick answer.

                You would produce three separate summaries:
                1.  Summary of Project X discussion (Monday) - focusing on what was discussed, but potentially adding context about the project's challenges or technical aspects if mentioned.
                2.  Summary of personal issue discussion (Wednesday) - capturing the essence of the conversation and any advice or support given.
                3.  Summary of the quick Project X Q&A (later Wednesday) - noting the question and answer, potentially highlighting how it relates to the earlier Monday discussion.
                PROMPT;

            $userPrompt = 'Summarize the chat conversation IN THE LANGUAGE OF THE MESSAGES.';
        }

        /** @var array<MessageInterface> $history */
        $history = [];
        foreach ($messages as $message) {
            $username = $message->fromUsername ? "@{$message->fromUsername}" : "user{$message->fromUserId}";
            $text     = $message->text;
            if ($message->fileId !== null && empty($text)) {
                $text = '[Sent a file/photo]'; // Add placeholder if text is empty but file exists
            } elseif ($message->fileId !== null && !empty($text)) {
                $text .= ' [With attached file/photo]'; // Append if text and file exist
            }

            $content = sprintf(
                '[%s] %s: %s',
                date('Y-m-d H:i:s', $message->date),
                $username,
                $text
            );

            $history[] = new UserMessage(
                content: $content,
                name: $username,
            );
        }

        // Check if after filtering, there are still messages to process
        if (count($history) === 0) {
            return null;
        }

        $maxRetries = 5;
        $retryDelay = 2; // seconds
        $attempt    = 0;
        $summary    = null;
        $lastError  = null;

        while ($summary === null && $attempt < $maxRetries) {
            try {
                $summary = $this->openaiSimple->generate(
                    system: $systemPrompt,
                    userMessage: $userPrompt,
                    history: $history,
                    temperature: 0.3,
                    maxTokens: 2048,
                );
                // dump($summary); // Keep for debugging if needed
            } catch (Throwable $e) {
                // Log the error properly instead of just dumping
                error_log('AI Generation Error (Attempt ' . ($attempt + 1) . '): ' . $e->getMessage());
                // dump($e); // Keep for debugging if needed
                $lastError = $e->getMessage();

                ++$attempt;
                if ($attempt >= $maxRetries) {
                    break; // Exit loop after max retries
                }
                delay($retryDelay);
                $retryDelay *= 2; // Exponential backoff
            }
        }

        return $summary ?? $lastError;
    }
}