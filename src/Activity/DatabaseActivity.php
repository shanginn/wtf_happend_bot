<?php

declare(strict_types=1);

namespace Bot\Activity;

use Bot\Entity\Message;
use Bot\Entity\UserMemory;
use Carbon\CarbonInterval;
use Cycle\ORM\ORMInterface;
use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;
use Temporal\Activity\ActivityOptions;
use Temporal\Common\RetryOptions;
use Temporal\Internal\Workflow\ActivityProxy;
use Temporal\Workflow;

#[ActivityInterface(prefix: 'Database.')]
class DatabaseActivity
{
    public function __construct(
        private ORMInterface $orm,
    ) {}

    public static function getDefinition(): ActivityProxy|self
    {
        return Workflow::newActivityStub(
            self::class,
            ActivityOptions::new()
                ->withStartToCloseTimeout(CarbonInterval::seconds(30))
                ->withRetryOptions(
                    RetryOptions::new()->withNonRetryableExceptions([])
                )
        );
    }

    #[ActivityMethod]
    public function saveMemory(
        int $chatId,
        string $userIdentifier,
        string $category,
        string $content,
    ): string {
        /** @var \Bot\Entity\UserMemory\UserMemoryRepository $repo */
        $repo = $this->orm->getRepository(UserMemory::class);

        // Check if a similar memory already exists (same user, category, similar content)
        $existing = $repo->findByUser($chatId, $userIdentifier);
        foreach ($existing as $memory) {
            if ($memory->category === $category && $this->isSimilar($memory->content, $content)) {
                // Update existing memory
                $memory->content = $content;
                $memory->updatedAt = time();
                $repo->save($memory);
                return "Memory updated for {$userIdentifier}: {$content}";
            }
        }

        $memory = new UserMemory(
            chatId: $chatId,
            userIdentifier: $userIdentifier,
            category: $category,
            content: $content,
            createdAt: time(),
            updatedAt: time(),
        );

        $repo->save($memory);

        return "Memory saved for {$userIdentifier}: {$content}";
    }

    #[ActivityMethod]
    public function recallMemory(
        int $chatId,
        ?string $userIdentifier = null,
        ?string $query = null,
    ): string {
        /** @var \Bot\Entity\UserMemory\UserMemoryRepository $repo */
        $repo = $this->orm->getRepository(UserMemory::class);

        if ($query !== null && $query !== '') {
            $memories = $repo->search($chatId, $query, $userIdentifier);
        } elseif ($userIdentifier !== null && $userIdentifier !== '') {
            $memories = $repo->findByUser($chatId, $userIdentifier);
        } else {
            $memories = $repo->findByChat($chatId);
        }

        if (empty($memories)) {
            $target = $userIdentifier ? "for {$userIdentifier}" : 'in this chat';
            return "No memories found {$target}" . ($query ? " matching '{$query}'" : '') . '.';
        }

        $lines = [];
        foreach ($memories as $memory) {
            $date = date('Y-m-d', $memory->updatedAt);
            $lines[] = "[{$memory->userIdentifier}] ({$memory->category}, {$date}): {$memory->content}";
        }

        return implode("\n", $lines);
    }

    #[ActivityMethod]
    public function searchMessages(
        int $chatId,
        string $query,
        ?string $username = null,
        int $limit = 10,
    ): string {
        /** @var \Bot\Entity\Message\MessageRepository $repo */
        $repo = $this->orm->getRepository(Message::class);

        $messages = $repo->searchByText($chatId, $query, $username, $limit);

        if (empty($messages)) {
            return 'No messages found matching "' . $query . '"' . ($username ? " from {$username}" : '') . '.';
        }

        $lines = [];
        foreach ($messages as $msg) {
            $date = date('Y-m-d H:i', $msg->date);
            $user = $msg->fromUsername ? '@' . $msg->fromUsername : 'user:' . $msg->fromUserId;
            $lines[] = "[{$date}] {$user}: {$msg->text}";
        }

        return implode("\n", array_reverse($lines));
    }

    #[ActivityMethod]
    public function getCurrentTime(string $timezone = 'UTC'): string
    {
        try {
            $tz = new \DateTimeZone($timezone);
        } catch (\Exception) {
            return "Unknown timezone: {$timezone}. Use IANA timezone names like 'Europe/Moscow', 'America/New_York'.";
        }

        $now = new \DateTimeImmutable('now', $tz);

        return sprintf(
            'Current time in %s: %s (%s)',
            $timezone,
            $now->format('Y-m-d H:i:s'),
            $now->format('l'),
        );
    }

    private function isSimilar(string $a, string $b): bool
    {
        // Simple similarity: if one is a substring of the other, or Levenshtein is small
        $a = mb_strtolower(trim($a));
        $b = mb_strtolower(trim($b));

        if (str_contains($a, $b) || str_contains($b, $a)) {
            return true;
        }

        // For short strings, use edit distance
        if (mb_strlen($a) < 100 && mb_strlen($b) < 100) {
            $maxLen = max(strlen($a), strlen($b));
            if ($maxLen > 0 && levenshtein($a, $b) / $maxLen < 0.3) {
                return true;
            }
        }

        return false;
    }
}
