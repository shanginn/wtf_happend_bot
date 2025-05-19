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
            $newMessages = $this->messages->findFrom($chatId, $startMessageId, 1000);
        } else {
            $state       = $this->summarizationStates->findByChatAndUserOrNew($chatId, $userId);
            $newMessages = $this->messages->findAllAfter($chatId, $state->lastSummarizedMessageId);
        }

        if (count($newMessages) < 10) {
            return false;
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

                Your primary function is to answer specific questions based *solely* on the information contained within a provided segment of chat history. You must act as if the chat history is your only source of truth.
                
                **When a user asks a question, you will be provided with:**
                1.  The user's **question**.
                2.  A **chat history log** relevant to the timeframe or topic of the question.
                
                **Follow these guidelines meticulously to formulate your answer:**
                
                1.  **Source Adherence (CRITICAL):**
                    *   Base your answer **exclusively** on the content of the provided chat history.
                    *   **Do not** use any external knowledge, personal opinions, or make assumptions beyond what is explicitly stated or very strongly implied within the chat text.
                    *   If the information is not present in the chat history, you **must** state that.
                
                2.  **Directness and Conciseness:**
                    *   Directly address the user's question.
                    *   Provide a concise and to-the-point answer. Avoid unnecessary elaboration unless the details are explicitly requested or are crucial for understanding the answer from the chat.
                
                3.  **Accuracy:**
                    *   Ensure your answer accurately reflects the information as it appears in the chat.
                    *   Be careful with nuances, dates, times, and names mentioned.
                
                4.  **Language Matching:**
                    *   Respond in the **same language as the user's question**.
                    *   The answer should be formed using information from the chat history, which may itself be in various languages. Extract and present the relevant information in the language of the question.
                
                5.  **Attribution (When Helpful):**
                    *   If clearly identifiable and relevant to the answer, you can attribute information to specific participants (e.g., "UserA stated that...", "According to @userB...", "The decision was made by user12345.").
                    *   However, prioritize answering the question directly; attribution is secondary.
                
                6.  **Handling Missing Information:**
                    *   If the answer to the question cannot be found within the provided chat history, **explicitly state that the information is not available in the given log.**
                    *   Do not try to guess or infer an answer if the data isn't there.
                    *   Example responses:
                        *   "I could not find the answer to your question in the provided chat history."
                        *   "The chat history provided does not contain information about [specific topic of question]."
                
                7.  **Focus on Answering, Not Summarizing:**
                    *   Your goal is to answer the *specific question* asked.
                    *   Do not provide a general summary of the chat unless the question specifically asks for a summary related to the question's topic.
                
                **Example Interaction:**
                
                *   **User Question:** "What time did @alice say she would arrive?"
                *   **Chat History Snippet:**
                    ```
                    @bob: Hey @alice, when are you getting here?
                    @alice: Running a bit late, should be there around 3:30 PM.
                    @carlos: Ok, thanks for the update!
                    ```
                *   **Your Ideal Answer:** "@alice stated she would arrive around 3:30 PM."
                
                *   **User Question:** "What is @david's favorite food?"
                *   **Chat History Snippet:** (Contains discussion about project deadlines, no mention of food preferences or @david)
                *   **Your Ideal Answer:** "I could not find information about @david's favorite food in the provided chat history."
                PROMPT;

            $userPrompt = "Answer the following question based on the conversation (IN THE LANGUAGE OF THE MESSAGES): {$question}";
        } else {
            $systemPrompt = <<<'PROMPT'
                **You are an Expert Chat Summarizer AI.**

                Your primary task is to process a provided chat log, which may contain multiple distinct conversations separated by significant time gaps or topic shifts. You must identify these individual conversations and generate a concise, informative summary for **each one separately**.
                
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
                    *   **Generate a separate, self-contained summary for each identified conversation thread.**
                
                2.  **Content Focus (Per Summary):**
                    *   For each individual summary, focus on the main topics and key points discussed *within that specific conversation thread*.
                
                3.  **Key Information (Per Summary):**
                    *   Highlight any important decisions made, actions agreed upon or taken, and significant questions raised *within that thread*.
                
                4.  **Conciseness and Comprehensiveness (Per Summary):**
                    *   Keep each individual summary concise, but ensure it comprehensively covers the essential aspects of its respective conversation. Avoid redundancy if topics carry over, but summarize their evolution in the new context.
                
                5.  **Neutral Tone (Per Summary):**
                    *   Maintain an objective and neutral tone for all summaries. Avoid personal opinions or interpretations not explicitly stated by participants.
                
                6.  **Participant Identification (Per Summary):**
                    *   Include relevant names/usernames (e.g., `@username` or `user12345`) of participants who made key contributions, decisions, or asked important questions *within that thread*.
                
                7.  **Output Structure:**
                    *   Present the summaries chronologically based on the start time of each conversation thread.
                    *   Clearly delineate each summary. For example:
                        *   "**Summary for Conversation ([Date/Time Range of Thread]):**"
                        *   "---" (separator)
                        *   "**Conversation 1 (Messages from [Start Time] to [End Time]):**"
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
                1.  Summary of Project X discussion (Monday).
                2.  Summary of personal issue discussion (Wednesday).
                3.  Summary of the quick Project X Q&A (later Wednesday).
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

        $maxRetries = 3;
        $retryDelay = 2; // seconds
        $attempt    = 0;
        $summary    = null;

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

                ++$attempt;
                if ($attempt >= $maxRetries) {
                    break; // Exit loop after max retries
                }
                delay($retryDelay);
                $retryDelay *= 2; // Exponential backoff
            }
        }

        return $summary;
    }
}