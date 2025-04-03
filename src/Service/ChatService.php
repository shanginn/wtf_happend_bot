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
     * @param string|null $question       optional specific question to ask the AI
     * @param ?int        $startMessageId
     *
     * @return false|string false if not enough messages, summary string otherwise, or error message
     */
    public function summarize(int $chatId, ?int $startMessageId = null, ?string $question = null): false|string
    {
        if ($startMessageId !== null) {
            $newMessages = $this->messages->findFrom($chatId, $startMessageId, 1000);
        } else {
            $state       = $this->summarizationStates->findByChatOrNew($chatId);
            $newMessages = $this->messages->findAllAfter($chatId, $state->lastSummarizedMessageId);
        }

        if (count($newMessages) < 1) {
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

        $systemPrompt = <<<'PROMPT'
            You are an expert chat summarizer. Your task is to create a concise and informative summary of the chat conversation provided.
            Follow these guidelines:
            0. Write in the same language the conversation is in! (very important. if messages are in Russian, write in Russian)
            1. Focus on the main topics and key points discussed
            2. Highlight any important decisions, actions, or questions
            3. Keep the summary concise but comprehensive
            4. Maintain a neutral tone
            5. Include relevant names/usernames of participants (e.g., @username or user12345)
            6. The /wtf command is used to summarize the chat conversation.
            7. Use Telegram MarkdownV2 formatting for the summary.
            PROMPT;

        if ($question !== null) {
            $userPrompt = "Answer the following question based on the conversation: {$question}";
        } else {
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