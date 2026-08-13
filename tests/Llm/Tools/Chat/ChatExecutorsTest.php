<?php

declare(strict_types=1);

namespace Tests\Llm\Tools\Chat;

use Bot\Entity\UpdateRecord;
use Bot\Llm\Tools\Chat\GetCurrentTimeExecutor;
use Bot\Llm\Tools\Chat\SearchMessagesExecutor;
use Bot\Telegram\Factory;
use Bot\Telegram\Update;
use Cycle\ORM\ORMInterface;
use Cycle\ORM\RepositoryInterface;
use DateTimeImmutable;
use DateTimeZone;
use Mockery;
use Phenogram\Bindings\Serializer;
use Phenogram\Bindings\Types\Chat;
use Phenogram\Bindings\Types\Dice;
use Phenogram\Bindings\Types\Message;
use Phenogram\Bindings\Types\PhotoSize;
use Phenogram\Bindings\Types\Sticker;
use Phenogram\Bindings\Types\Story;
use Phenogram\Bindings\Types\SuccessfulPayment;
use Phenogram\Bindings\Types\User;
use Phenogram\Bindings\Types\VideoNote;
use Phenogram\Bindings\Types\Voice;
use Tests\TestCase;

class ChatExecutorsTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testSearchMessagesExecutorLoadsRecentHistoryWhenQueryIsEmpty(): void
    {
        $chatId = -100123;
        $repo   = $this->makeUpdateRepo([
            $this->makeUpdateRecord(2, $chatId, 'second message', 200, 'bob'),
            $this->makeUpdateRecord(1, $chatId, 'first message', 100, 'alice'),
        ]);

        $executor = new SearchMessagesExecutor($this->makeOrm($repo));
        $result   = $executor->execute(chatId: $chatId, resultLimit: 2);

        self::assertStringContainsString('Recent inbound Telegram history', $result);
        self::assertTrue(strpos($result, 'first message') < strpos($result, 'second message'));
    }

    public function testSearchMessagesExecutorFiltersByQueryAndUsername(): void
    {
        $chatId = -100123;
        $repo   = $this->makeUpdateRepo([
            $this->makeUpdateRecord(3, $chatId, 'deploy plan is ready', 300, 'alice'),
            $this->makeUpdateRecord(2, $chatId, 'deploy failed on staging', 200, 'bob'),
            $this->makeUpdateRecord(1, $chatId, 'random chat', 100, 'alice'),
        ]);

        $executor = new SearchMessagesExecutor($this->makeOrm($repo));
        $result   = $executor->execute(
            chatId: $chatId,
            queryText: 'deploy',
            usernameText: '@alice',
            resultLimit: 5,
        );

        self::assertStringContainsString('Relevant inbound Telegram history', $result);
        self::assertStringContainsString('deploy plan is ready', $result);
        self::assertStringNotContainsString('deploy failed on staging', $result);
        self::assertStringNotContainsString('random chat', $result);
    }

    public function testSearchMessagesExecutorSearchesBeyondRecentWindowWhenQueryIsPresent(): void
    {
        $chatId  = -100123;
        $records = [];

        for ($i = 400; $i >= 101; --$i) {
            $records[] = $this->makeUpdateRecord($i, $chatId, 'recent filler ' . $i, $i, 'alice');
        }

        $records[] = $this->makeUpdateRecord(1, $chatId, 'ancient deploy decision', 1, 'bob');

        $executor = new SearchMessagesExecutor($this->makeOrm($this->makeUpdateRepo($records)));
        $result   = $executor->execute(
            chatId: $chatId,
            queryText: 'ancient deploy',
            resultLimit: 5,
        );

        self::assertStringContainsString('Relevant inbound Telegram history', $result);
        self::assertStringContainsString('ancient deploy decision', $result);
        self::assertStringNotContainsString('recent filler', $result);
    }

    public function testSearchMessagesExecutorSearchesTheWholeChatAcrossTopics(): void
    {
        $chatId = -100123;
        $repo   = $this->makeUpdateRepo([
            $this->makeUpdateRecord(2, $chatId, 'topic message', 200, 'bob', topicId: 42),
            $this->makeUpdateRecord(1, $chatId, 'general message', 100, 'alice'),
        ]);

        $executor = new SearchMessagesExecutor($this->makeOrm($repo));
        $result   = $executor->execute(chatId: $chatId, resultLimit: 5);

        self::assertStringContainsString('general message', $result);
        self::assertStringContainsString('topic message', $result);
    }

    public function testSearchMessagesExecutorResolvesThreeCalendarMonthsAgo(): void
    {
        $chatId = -100123;
        $zone   = new DateTimeZone('Asia/Yekaterinburg');
        $at     = static fn (string $time): int => (new DateTimeImmutable($time, $zone))->getTimestamp();
        $repo   = $this->makeUpdateRepo([
            $this->makeUpdateRecord(4, $chatId, 'next day', $at('2026-05-14 00:00:00'), 'alice'),
            $this->makeUpdateRecord(3, $chatId, 'end of target day', $at('2026-05-13 23:59:59'), 'bob', topicId: 42),
            $this->makeUpdateRecord(2, $chatId, 'start of target day', $at('2026-05-13 00:00:00'), 'alice'),
            $this->makeUpdateRecord(1, $chatId, 'previous day', $at('2026-05-12 23:59:59'), 'alice'),
        ]);

        $result = (new SearchMessagesExecutor($this->makeOrm($repo)))->execute(
            chatId: $chatId,
            relativeDay: ['months_ago' => 3],
            referenceTimestamp: $at('2026-08-13 19:17:00'),
            resultLimit: 30,
        );

        self::assertStringContainsString('2026-05-13 in Asia/Yekaterinburg', $result);
        self::assertStringContainsString('start of target day', $result);
        self::assertStringContainsString('end of target day', $result);
        self::assertStringNotContainsString('previous day', $result);
        self::assertStringNotContainsString('next day', $result);
    }

    public function testSearchMessagesExecutorPagesAWholeOldDayWithoutRecentWindow(): void
    {
        $chatId  = -100123;
        $zone    = new DateTimeZone('Asia/Yekaterinburg');
        $start   = new DateTimeImmutable('2026-05-13 00:00:00', $zone);
        $records = [];
        for ($index = 0; $index < 65; ++$index) {
            $records[] = $this->makeUpdateRecord(
                $index + 1,
                $chatId,
                'historic item ' . $index,
                $start->modify("+{$index} minutes")->getTimestamp(),
                'alice',
                topicId: $index % 2 === 0 ? null : 42,
            );
        }
        for ($index = 0; $index < 400; ++$index) {
            $records[] = $this->makeUpdateRecord(
                1000 + $index,
                $chatId,
                'recent filler ' . $index,
                $start->modify('+3 months +' . $index . ' minutes')->getTimestamp(),
                'bob',
            );
        }
        $executor = new SearchMessagesExecutor($this->makeOrm($this->makeUpdateRepo($records)));

        $first = $executor->execute(chatId: $chatId, onDate: '2026-05-13', resultLimit: 30);
        $last  = $executor->execute(chatId: $chatId, onDate: '2026-05-13', resultLimit: 30, offset: 60);

        self::assertStringContainsString('has_more=true; next_offset=30', $first);
        self::assertStringContainsString('historic item 0', $first);
        self::assertStringNotContainsString('recent filler', $first);
        self::assertStringContainsString('has_more=false', $last);
        self::assertStringContainsString('historic item 64', $last);
    }

    public function testLastSupportedPeriodPageReportsTruncationWithoutAnUnreachableOffset(): void
    {
        $chatId  = -100123;
        $zone    = new DateTimeZone('Asia/Yekaterinburg');
        $start   = new DateTimeImmutable('2026-05-13 00:00:00', $zone);
        $records = [];
        for ($index = 0; $index < 1021; ++$index) {
            $records[] = $this->makeUpdateRecord(
                $index + 1,
                $chatId,
                'historic item ' . $index,
                $start->modify("+{$index} seconds")->getTimestamp(),
                'alice',
            );
        }
        $executor = new SearchMessagesExecutor($this->makeOrm($this->makeUpdateRepo($records)));

        $lastSupportedPage = $executor->execute(
            chatId: $chatId,
            onDate: '2026-05-13',
            resultLimit: 30,
            offset: 990,
        );

        self::assertStringContainsString(
            'has_more=false; truncated=true; pagination_limit_reached=true',
            $lastSupportedPage,
        );
        self::assertStringNotContainsString('next_offset=', $lastSupportedPage);
        self::assertStringContainsString('historic item 990', $lastSupportedPage);
        self::assertStringContainsString('historic item 1019', $lastSupportedPage);
        self::assertStringNotContainsString('historic item 1020', $lastSupportedPage);
    }

    public function testPeriodKeepsUpdateOrderForMessagesSentInTheSameSecond(): void
    {
        $chatId   = -100123;
        $zone     = new DateTimeZone('Asia/Yekaterinburg');
        $at       = (new DateTimeImmutable('2026-05-13 12:00:00', $zone))->getTimestamp();
        $executor = new SearchMessagesExecutor($this->makeOrm($this->makeUpdateRepo([
            $this->makeUpdateRecord(2, $chatId, 'same second second', $at, 'bob'),
            $this->makeUpdateRecord(1, $chatId, 'same second first', $at, 'alice'),
        ])));

        $result = $executor->execute(chatId: $chatId, onDate: '2026-05-13', resultLimit: 30);

        self::assertLessThan(
            strpos($result, 'same second second'),
            strpos($result, 'same second first'),
        );
    }

    public function testRelativeCalendarMonthClampsToTheLastTargetMonthDay(): void
    {
        $chatId   = -100123;
        $zone     = new DateTimeZone('Asia/Yekaterinburg');
        $at       = static fn (string $time): int => (new DateTimeImmutable($time, $zone))->getTimestamp();
        $executor = new SearchMessagesExecutor($this->makeOrm($this->makeUpdateRepo([
            $this->makeUpdateRecord(2, $chatId, 'wrong rollover day', $at('2026-03-03 12:00:00'), 'alice'),
            $this->makeUpdateRecord(1, $chatId, 'clamped february day', $at('2026-02-28 12:00:00'), 'alice'),
        ])));

        $result = $executor->execute(
            chatId: $chatId,
            relativeDay: ['months_ago' => 6],
            referenceTimestamp: $at('2026-08-31 19:17:00'),
        );

        self::assertStringContainsString('2026-02-28 in Asia/Yekaterinburg', $result);
        self::assertStringContainsString('clamped february day', $result);
        self::assertStringNotContainsString('wrong rollover day', $result);
    }

    public function testSearchMessagesExecutorRejectsInvalidOrMixedDateSelectorsInBand(): void
    {
        $executor = new SearchMessagesExecutor($this->makeOrm($this->makeUpdateRepo([])));

        self::assertStringStartsWith(
            'History search request invalid:',
            $executor->execute(chatId: -100123, onDate: '2026-02-30'),
        );
        self::assertStringContainsString(
            'exactly one',
            $executor->execute(
                chatId: -100123,
                onDate: '2026-05-13',
                relativeDay: ['months_ago' => 3],
                referenceTimestamp: 1_786_605_420,
            ),
        );
        self::assertStringContainsString(
            'supplied together',
            $executor->execute(chatId: -100123, fromDate: '2026-05-13'),
        );
    }

    public function testSearchMessagesExecutorDoesNotSearchNestedReplyText(): void
    {
        $chatId     = -100123;
        $serializer = new Serializer(new Factory());
        $update     = new Update(
            updateId: 1,
            message: new Message(
                messageId: 2,
                date: 200,
                chat: new Chat(id: $chatId, type: 'supergroup', title: 'Tea Room'),
                from: new User(id: 11, isBot: false, firstName: 'User', username: 'alice'),
                text: 'is that really true?',
                replyToMessage: new Message(
                    messageId: 1,
                    date: 100,
                    chat: new Chat(id: $chatId, type: 'supergroup', title: 'Tea Room'),
                    from: new User(id: 99, isBot: true, firstName: 'Bot', username: 'local_bot'),
                    text: 'there were exactly four notebook records under article 128.1',
                ),
            ),
        );
        $record = new UpdateRecord(
            updateId: 1,
            update: json_encode($serializer->serialize([$update])[0], \JSON_THROW_ON_ERROR),
            chatId: $chatId,
            createdAt: 200,
        );
        $executor = new SearchMessagesExecutor($this->makeOrm($this->makeUpdateRepo([$record])));

        self::assertSame(
            'No messages found matching "128.1".',
            $executor->execute(chatId: $chatId, queryText: '128.1'),
        );
    }

    public function testSearchMessagesExecutorKeepsMediaOnlyMessagesInDatedHistory(): void
    {
        $chatId     = -100123;
        $zone       = new DateTimeZone('Asia/Yekaterinburg');
        $start      = new DateTimeImmutable('2026-05-13 12:00:00', $zone);
        $chat       = new Chat(id: $chatId, type: 'supergroup', title: 'Tea Room');
        $sender     = new User(id: 11, isBot: false, firstName: 'User', username: 'alice');
        $serializer = new Serializer(new Factory());
        $reply      = new Message(
            messageId: 99,
            date: $start->modify('-1 minute')->getTimestamp(),
            chat: $chat,
            from: new User(id: 99, isBot: true, firstName: 'Bot'),
            text: 'nested notebook claim under article 128.1',
        );
        $messages = [];
        for ($index = 0; $index < 4; ++$index) {
            $messages[] = new Message(
                messageId: $index + 1,
                date: $start->modify("+{$index} minutes")->getTimestamp(),
                chat: $chat,
                from: $sender,
                photo: [new PhotoSize(
                    fileId: 'private-photo-file-id-' . $index,
                    fileUniqueId: 'private-photo-unique-id-' . $index,
                    width: 640,
                    height: 480,
                )],
                replyToMessage: $index === 0 ? $reply : null,
            );
        }
        for ($index = 0; $index < 3; ++$index) {
            $messages[] = new Message(
                messageId: $index + 5,
                date: $start->modify('+' . ($index + 4) . ' minutes')->getTimestamp(),
                chat: $chat,
                from: $sender,
                sticker: new Sticker(
                    fileId: 'private-sticker-file-id-' . $index,
                    fileUniqueId: 'private-sticker-unique-id-' . $index,
                    type: 'regular',
                    width: 512,
                    height: 512,
                    isAnimated: false,
                    isVideo: false,
                ),
            );
        }
        $messages[] = new Message(
            messageId: 8,
            date: $start->modify('+7 minutes')->getTimestamp(),
            chat: $chat,
            from: $sender,
            videoNote: new VideoNote(
                fileId: 'private-video-note-file-id',
                fileUniqueId: 'private-video-note-unique-id',
                length: 360,
                duration: 12,
            ),
        );

        $records = array_map(
            static function (Message $message, int $index) use ($chatId, $serializer): UpdateRecord {
                $update = new Update(updateId: 1000 + $index, message: $message);

                return new UpdateRecord(
                    updateId: $update->updateId,
                    update: json_encode($serializer->serialize([$update])[0], \JSON_THROW_ON_ERROR),
                    chatId: $chatId,
                    createdAt: $message->date,
                );
            },
            $messages,
            array_keys($messages),
        );
        $executor = new SearchMessagesExecutor($this->makeOrm($this->makeUpdateRepo($records)));

        $wholeDay = $executor->execute(chatId: $chatId, onDate: '2026-05-13', resultLimit: 30);
        self::assertSame(4, substr_count($wholeDay, 'photo'));
        self::assertSame(3, substr_count($wholeDay, 'sticker'));
        self::assertSame(1, substr_count($wholeDay, 'video note'));
        self::assertStringNotContainsString('private-', $wholeDay);
        self::assertStringNotContainsString('128.1', $wholeDay);

        self::assertSame(
            4,
            substr_count(
                $executor->execute(chatId: $chatId, queryText: 'photo', onDate: '2026-05-13'),
                'photo',
            ),
        );
        self::assertSame(
            3,
            substr_count(
                $executor->execute(chatId: $chatId, queryText: 'sticker', onDate: '2026-05-13'),
                'sticker',
            ),
        );
        self::assertStringContainsString(
            'video note',
            $executor->execute(chatId: $chatId, queryText: 'video note', onDate: '2026-05-13'),
        );
        self::assertSame(
            'No Telegram messages or bot outputs found in 2026-05-13 in Asia/Yekaterinburg matching "128.1".',
            $executor->execute(chatId: $chatId, queryText: '128.1', onDate: '2026-05-13'),
        );
    }

    public function testSearchMessagesExecutorFindsFormattedDirectEventPhrases(): void
    {
        $chatId     = -100123;
        $zone       = new DateTimeZone('Asia/Yekaterinburg');
        $start      = new DateTimeImmutable('2026-05-13 12:00:00', $zone);
        $chat       = new Chat(id: $chatId, type: 'supergroup', title: 'Tea Room');
        $sender     = new User(id: 11, isBot: false, firstName: 'User', username: 'alice');
        $serializer = new Serializer(new Factory());
        $messages   = [
            new Message(
                messageId: 1,
                date: $start->getTimestamp(),
                chat: $chat,
                from: $sender,
                text: 'hello world',
            ),
            new Message(
                messageId: 2,
                date: $start->modify('+1 minute')->getTimestamp(),
                chat: $chat,
                from: $sender,
                photo: [new PhotoSize(
                    fileId: 'private-photo-file-id',
                    fileUniqueId: 'private-photo-unique-id',
                    width: 640,
                    height: 480,
                )],
            ),
            new Message(
                messageId: 3,
                date: $start->modify('+2 minutes')->getTimestamp(),
                chat: $chat,
                from: $sender,
                voice: new Voice(
                    fileId: 'private-voice-file-id',
                    fileUniqueId: 'private-voice-unique-id',
                    duration: 12,
                ),
            ),
            new Message(
                messageId: 4,
                date: $start->modify('+3 minutes')->getTimestamp(),
                chat: $chat,
                from: $sender,
                dice: new Dice(emoji: '🎲', value: 4),
            ),
            new Message(
                messageId: 5,
                date: $start->modify('+4 minutes')->getTimestamp(),
                chat: $chat,
                from: $sender,
                story: new Story(chat: $chat, id: 77),
            ),
            new Message(
                messageId: 6,
                date: $start->modify('+5 minutes')->getTimestamp(),
                chat: $chat,
                from: $sender,
                successfulPayment: new SuccessfulPayment(
                    currency: 'XTR',
                    totalAmount: 100,
                    invoicePayload: 'private-invoice-payload',
                    telegramPaymentChargeId: 'private-telegram-charge-id',
                    providerPaymentChargeId: 'private-provider-charge-id',
                ),
            ),
        ];
        $records = array_map(
            static function (Message $message, int $index) use ($chatId, $serializer): UpdateRecord {
                $update = new Update(updateId: 2000 + $index, message: $message);

                return new UpdateRecord(
                    updateId: $update->updateId,
                    update: json_encode($serializer->serialize([$update])[0], \JSON_THROW_ON_ERROR),
                    chatId: $chatId,
                    createdAt: $message->date,
                );
            },
            $messages,
            array_keys($messages),
        );
        $executor = new SearchMessagesExecutor($this->makeOrm($this->makeUpdateRepo($records)));

        foreach ([
            'photo'              => 'photo',
            'voice message'      => 'voice message',
            'dice roll'          => 'dice roll',
            'forwarded story'    => 'forwarded story',
            'successful payment' => 'completed a successful payment',
        ] as $query => $expected) {
            self::assertStringContainsString(
                $expected,
                $executor->execute(chatId: $chatId, queryText: $query, onDate: '2026-05-13'),
                $query,
            );
        }
    }

    public function testSpaceSearchNeverCrossesTheTopicBoundary(): void
    {
        $chatId = -100123;
        $repo   = $this->makeUpdateRepo([
            $this->makeUpdateRecord(3, $chatId, 'private topic 99 note', 300, 'eve', topicId: 99),
            $this->makeUpdateRecord(2, $chatId, 'private topic 42 note', 200, 'bob', topicId: 42),
            $this->makeUpdateRecord(1, $chatId, 'root private note', 100, 'alice'),
        ]);
        $executor = new SearchMessagesExecutor($this->makeOrm($repo));

        $topic = $executor->executeInSpace($chatId, 42, 'private', resultLimit: 10);
        self::assertStringContainsString('topic 42', $topic);
        self::assertStringNotContainsString('topic 99', $topic);
        self::assertStringNotContainsString('root private', $topic);

        $root = $executor->executeInSpace($chatId, null, 'private', resultLimit: 10);
        self::assertStringContainsString('root private', $root);
        self::assertStringNotContainsString('topic 42', $root);
        self::assertStringNotContainsString('topic 99', $root);
    }

    public function testDatedSpaceSearchNeverCrossesTheTopicBoundary(): void
    {
        $chatId = -100123;
        $zone   = new DateTimeZone('Asia/Yekaterinburg');
        $at     = (new DateTimeImmutable('2026-05-13 12:00:00', $zone))->getTimestamp();
        $repo   = $this->makeUpdateRepo([
            $this->makeUpdateRecord(3, $chatId, 'dated topic 99 note', $at + 2, 'eve', topicId: 99),
            $this->makeUpdateRecord(2, $chatId, 'dated topic 42 note', $at + 1, 'bob', topicId: 42),
            $this->makeUpdateRecord(1, $chatId, 'dated root note', $at, 'alice'),
        ]);
        $executor = new SearchMessagesExecutor($this->makeOrm($repo));

        $topic = $executor->executeInSpace($chatId, 42, onDate: '2026-05-13', resultLimit: 30);
        self::assertStringContainsString('dated topic 42', $topic);
        self::assertStringNotContainsString('dated topic 99', $topic);
        self::assertStringNotContainsString('dated root', $topic);

        $root = $executor->executeInSpace($chatId, null, onDate: '2026-05-13', resultLimit: 30);
        self::assertStringContainsString('dated root', $root);
        self::assertStringNotContainsString('dated topic 42', $root);
        self::assertStringNotContainsString('dated topic 99', $root);
    }

    public function testSearchMessagesExecutorReturnsAUsefulNoMatchMessage(): void
    {
        $chatId = -100123;
        $repo   = $this->makeUpdateRepo([
            $this->makeUpdateRecord(1, $chatId, 'human deploy note', 100, 'alice'),
        ]);

        $executor = new SearchMessagesExecutor($this->makeOrm($repo));
        $result   = $executor->execute(
            chatId: $chatId,
            queryText: 'missing phrase',
            usernameText: '@bob',
        );

        self::assertSame('No messages found matching "missing phrase" for @bob.', $result);
    }

    public function testGetCurrentTimeExecutorReturnsFormattedTime(): void
    {
        $executor = new GetCurrentTimeExecutor();
        $result   = $executor->execute('UTC');

        self::assertMatchesRegularExpression(
            '/^Current time in UTC: \d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2} \([A-Za-z]+\)$/',
            $result,
        );
    }

    public function testGetCurrentTimeExecutorRejectsUnknownTimezone(): void
    {
        $executor = new GetCurrentTimeExecutor();

        self::assertStringStartsWith(
            'Unknown timezone: Mars/Olympus_Mons.',
            $executor->execute('Mars/Olympus_Mons'),
        );
    }

    private function makeUpdateRecord(
        int $updateId,
        int $chatId,
        string $text,
        int $createdAt,
        string $username,
        ?int $topicId = null,
    ): UpdateRecord {
        $serializer = new Serializer(new Factory());
        $update     = new Update(
            updateId: $updateId,
            message: new Message(
                messageId: $updateId,
                date: $createdAt,
                chat: new Chat(id: $chatId, type: 'supergroup', title: 'Tea Room'),
                from: new User(id: 10 + $updateId, isBot: false, firstName: 'User', username: $username),
                text: $text,
                messageThreadId: $topicId,
            ),
        );

        return new UpdateRecord(
            updateId: $updateId,
            update: json_encode($serializer->serialize([$update])[0], \JSON_THROW_ON_ERROR),
            chatId: $chatId,
            topicId: $topicId,
            createdAt: $createdAt,
        );
    }

    /**
     * @param list<UpdateRecord> $records
     */
    private function makeUpdateRepo(array $records): RepositoryInterface
    {
        return new class($records) implements RepositoryInterface {
            /**
             * @param list<UpdateRecord> $records
             */
            public function __construct(private readonly array $records) {}

            /**
             * @param int $chatId
             * @param int $limit
             *
             * @return list<UpdateRecord>
             */
            public function findLastN(int $chatId, int $limit): array
            {
                return array_slice(
                    array_values(array_filter(
                        $this->records,
                        static fn (UpdateRecord $record): bool => $record->chatId === $chatId,
                    )),
                    0,
                    $limit,
                );
            }

            /**
             * @param list<string> $tokens
             * @param int          $chatId
             * @param int          $limit
             *
             * @return list<UpdateRecord>
             */
            public function searchByPayloadText(int $chatId, array $tokens, int $limit): array
            {
                $records = array_values(array_filter(
                    $this->records,
                    static function (UpdateRecord $record) use ($chatId, $tokens): bool {
                        if ($record->chatId !== $chatId) {
                            return false;
                        }

                        $decoded = json_decode($record->update, true, flags: \JSON_THROW_ON_ERROR);
                        $update  = (new Serializer(new Factory()))
                            ->deserialize($decoded, \Phenogram\Bindings\Types\Interfaces\UpdateInterface::class);
                        $payload = mb_strtolower(
                            (new \Bot\Telegram\TelegramUpdateViewFactory())
                                ->create($update)
                                ->directHistoryText ?? '',
                        );

                        foreach ($tokens as $token) {
                            if (!str_contains($payload, $token)) {
                                return false;
                            }
                        }

                        return true;
                    },
                ));

                usort(
                    $records,
                    static fn (UpdateRecord $left, UpdateRecord $right): int => [$right->createdAt, $right->updateId]
                        <=> [$left->createdAt, $left->updateId],
                );

                return array_slice($records, 0, $limit);
            }

            /**
             * @param list<string> $tokens
             * @param int          $chatId
             * @param int          $startInclusive
             * @param int          $endExclusive
             * @param int          $limit
             * @param int          $offset
             *
             * @return list<UpdateRecord>
             */
            public function searchInPeriod(
                int $chatId,
                int $startInclusive,
                int $endExclusive,
                array $tokens,
                int $limit,
                int $offset = 0,
            ): array {
                $records = array_values(array_filter(
                    $this->searchByPayloadText($chatId, $tokens, PHP_INT_MAX),
                    static fn (UpdateRecord $record): bool => $record->createdAt >= $startInclusive
                        && $record->createdAt < $endExclusive,
                ));
                usort(
                    $records,
                    static fn (UpdateRecord $left, UpdateRecord $right): int => [$left->createdAt, $left->updateId]
                        <=> [$right->createdAt, $right->updateId],
                );

                return array_slice($records, $offset, $limit);
            }

            /** @return list<UpdateRecord> */
            public function searchInPeriodInTopic(
                int $chatId,
                ?int $topicId,
                int $startInclusive,
                int $endExclusive,
                array $tokens,
                int $limit,
                int $offset = 0,
            ): array {
                return array_slice(array_values(array_filter(
                    $this->searchInPeriod(
                        $chatId,
                        $startInclusive,
                        $endExclusive,
                        $tokens,
                        PHP_INT_MAX,
                    ),
                    static fn (UpdateRecord $record): bool => $record->topicId === $topicId,
                )), $offset, $limit);
            }

            /** @return list<UpdateRecord> */
            public function findLastNInTopic(int $chatId, ?int $topicId, int $limit): array
            {
                return array_slice(array_values(array_filter(
                    $this->records,
                    static fn (UpdateRecord $record): bool => $record->chatId === $chatId
                        && $record->topicId === $topicId,
                )), 0, $limit);
            }

            /**
             * @param list<string> $tokens
             * @param int          $chatId
             * @param ?int         $topicId
             * @param int          $limit
             *
             * @return list<UpdateRecord>
             */
            public function searchByPayloadTextInTopic(
                int $chatId,
                ?int $topicId,
                array $tokens,
                int $limit,
            ): array {
                return array_slice(array_values(array_filter(
                    $this->searchByPayloadText($chatId, $tokens, $limit),
                    static fn (UpdateRecord $record): bool => $record->topicId === $topicId,
                )), 0, $limit);
            }

            public function findByPK(mixed $id): ?object
            {
                return null;
            }

            public function findOne(array $scope = []): ?object
            {
                return null;
            }

            public function findAll(array $scope = []): iterable
            {
                return [];
            }
        };
    }

    private function makeOrm(RepositoryInterface $updateRepo): ORMInterface
    {
        $orm = Mockery::mock(ORMInterface::class);
        $orm->shouldReceive('getRepository')
            ->atLeast()
            ->once()
            ->with(UpdateRecord::class)
            ->andReturn($updateRepo);

        return $orm;
    }
}
