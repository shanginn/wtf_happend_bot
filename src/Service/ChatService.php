<?php

declare(strict_types=1);

namespace Bot\Service;

use function Amp\delay;

use Bot\Entity\Message;
use Bot\Entity\SummarizationState;
use Cycle\ORM\EntityManagerInterface;
use Shanginn\Openai\OpenaiSimple;
use Throwable;

class ChatService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private Message\MessageRepository $messages,
        private SummarizationState\SummarizationStateRepository $summarizationStates,
        private OpenaiSimple $openaiSimple,
    ) {}

    public function summarize(int $chatId): false|string
    {
        $state       = $this->summarizationStates->findByChatOrNew($chatId);
        $newMessages = $this->messages->findAllAfter($chatId, $state->lastSummarizedMessageId);

        if (count($newMessages) < 2) {
            return false;
        }

        // Format messages for summarization
        $formattedMessages = [];
        foreach ($newMessages as $message) {
            $username            = $message->fromUsername ? "@{$message->fromUsername}" : "User {$message->fromUserId}";
            $formattedMessages[] = "{$username}: {$message->text}";
        }

        $conversationText = implode("\n", $formattedMessages);

        $systemPrompt = <<<'PROMPT'
            You are an expert chat summarizer. Your task is to create a concise and informative summary of the chat conversation provided.
            Follow these guidelines:
            0. Write in the same language the conversation is in! (very important. if messages are in Russian, write in Russian)
            1. Focus on the main topics and key points discussed
            2. Highlight any important decisions, actions, or questions
            3. Keep the summary concise but comprehensive
            4. Maintain a neutral tone
            5. Include relevant names/usernames of participants
            7. Ignore the /wtf command mentions
            PROMPT;

        $userPrompt = <<<PROMPT
            Summarize the following chat conversation IN THE LANGUAGE OF THE MESSAGES
            
            {$conversationText}
            PROMPT;

        $maxRetries = 3;
        $retryDelay = 2; // seconds
        $attempt    = 0;
        $summary    = null;

        while ($summary === null && $attempt < $maxRetries) {
            try {
                $summary = $this->openaiSimple->generate(
                    system: $systemPrompt,
                    userMessage: $userPrompt,
                    temperature: 0.3,
                    maxTokens: 1024,
                );
            } catch (Throwable $e) {
                dump($e);
                ++$attempt;

                delay($retryDelay);
                $retryDelay *= 2;
            }
        }

        if ($summary === null) {
            return 'Сервис временно недоступен. Попробуйте позже.';
        }

        $state->lastSummarizedMessageId = end($newMessages)->messageId;
        $this->em->persist($state);

        $this->em->run();
        $this->em->clean();

        return $summary;
    }
}