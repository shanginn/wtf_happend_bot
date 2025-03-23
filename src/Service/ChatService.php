<?php

declare(strict_types=1);

namespace Bot\Service;

use Bot\Entity\Message;
use Bot\Entity\SummarizationState;
use Cycle\ORM\EntityManagerInterface;

class ChatService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private Message\MessageRepository $messages,
        private SummarizationState\SummarizationStateRepository $summarizationStates
    ) {}

    public function summarize(int $chatId): false|string
    {
        $state       = $this->summarizationStates->findByChatOrNew($chatId);
        $newMessages = $this->messages->findAllAfter($chatId, $state->lastSummarizedMessageId);
        dump($newMessages);

        if (count($newMessages) < 2) {
            return false;
        }

        $summary = 'asd asd asd asd asd ';

        $state->lastSummarizedMessageId = end($newMessages)->messageId;

        $this->em->run();
        $this->em->clean();

        return $summary;
    }
}