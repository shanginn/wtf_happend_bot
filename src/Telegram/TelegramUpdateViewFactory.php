<?php

declare(strict_types=1);

namespace Bot\Telegram;

use DateTimeImmutable;
use DateTimeZone;
use Phenogram\Bindings\Types\Interfaces\ChatInterface;
use Phenogram\Bindings\Types\Interfaces\DocumentInterface;
use Phenogram\Bindings\Types\Interfaces\MessageInterface;
use Phenogram\Bindings\Types\Interfaces\PhotoSizeInterface;
use Phenogram\Bindings\Types\Interfaces\UpdateInterface;
use Phenogram\Bindings\Types\Interfaces\UserInterface;

class TelegramUpdateViewFactory implements TelegramUpdateViewFactoryInterface
{
    private const MESSAGE_UPDATE_SOURCES = [
        'message' => ['label' => 'message', 'verb' => 'sent'],
        'editedMessage' => ['label' => 'edited message', 'verb' => 'edited'],
        'channelPost' => ['label' => 'channel post', 'verb' => 'published'],
        'editedChannelPost' => ['label' => 'edited channel post', 'verb' => 'edited'],
        'businessMessage' => ['label' => 'business message', 'verb' => 'sent'],
        'editedBusinessMessage' => ['label' => 'edited business message', 'verb' => 'edited'],
    ];

    private const OTHER_UPDATE_SOURCES = [
        'callbackQuery' => 'callback query',
        'inlineQuery' => 'inline query',
        'chosenInlineResult' => 'chosen inline result',
        'businessConnection' => 'business connection update',
        'deletedBusinessMessages' => 'deleted business messages',
        'messageReaction' => 'message reaction update',
        'messageReactionCount' => 'message reaction count update',
        'shippingQuery' => 'shipping query',
        'preCheckoutQuery' => 'pre-checkout query',
        'purchasedPaidMedia' => 'paid media purchase',
        'poll' => 'poll update',
        'pollAnswer' => 'poll answer',
        'myChatMember' => 'bot membership update',
        'chatMember' => 'chat member update',
        'chatJoinRequest' => 'chat join request',
        'chatBoost' => 'chat boost update',
        'removedChatBoost' => 'chat boost removal',
    ];

    public function __construct(
        private readonly ?TelegramFileUrlResolverInterface $fileUrlResolver = null,
    ) {}

    public function create(UpdateInterface $update): TelegramUpdateView
    {
        $messageUpdate = $this->extractMessageUpdate($update);

        if ($messageUpdate === null) {
            return new TelegramUpdateView(
                text: $this->describeNonMessageUpdate($update),
                participantReference: $this->resolveNonMessageParticipantReference($update),
            );
        }

        $message = $messageUpdate['message'];

        return new TelegramUpdateView(
            text: $this->formatMessageUpdate(
                updateId: $update->updateId,
                updateLabel: $messageUpdate['label'],
                verb: $messageUpdate['verb'],
                message: $message,
            ),
            participantReference: $this->resolveParticipantReference($message),
            imageUrls: $this->collectImageUrls($message),
        );
    }

    /**
     * @return array{label: string, verb: string, message: MessageInterface}|null
     */
    private function extractMessageUpdate(UpdateInterface $update): ?array
    {
        foreach (self::MESSAGE_UPDATE_SOURCES as $field => $meta) {
            $message = $update->{$field};

            if ($message instanceof MessageInterface) {
                return [
                    'label' => $meta['label'],
                    'verb' => $meta['verb'],
                    'message' => $message,
                ];
            }
        }

        return null;
    }

    private function resolveNonMessageParticipantReference(UpdateInterface $update): ?string
    {
        if ($update->callbackQuery !== null) {
            return $this->resolveUserReference($update->callbackQuery->from);
        }

        return null;
    }

    private function describeNonMessageUpdate(UpdateInterface $update): string
    {
        $lines = [
            'Telegram update',
            'Update id: ' . $update->updateId,
        ];

        if ($update->callbackQuery !== null) {
            $lines[] = 'Kind: callback query';
            $lines[] = 'From: ' . $this->describeUser($update->callbackQuery->from);

            if ($update->callbackQuery->data !== null && trim($update->callbackQuery->data) !== '') {
                $lines[] = 'Data: ' . $update->callbackQuery->data;
            }

            if ($update->callbackQuery->message !== null) {
                $lines[] = 'Message id: ' . $update->callbackQuery->message->messageId;
            }

            return implode("\n", $lines);
        }

        foreach (self::OTHER_UPDATE_SOURCES as $field => $label) {
            if ($update->{$field} !== null) {
                $lines[] = 'Kind: ' . $label;

                return implode("\n", $lines);
            }
        }

        $lines[] = 'Kind: unknown update';

        return implode("\n", $lines);
    }

    private function formatMessageUpdate(
        int $updateId,
        string $updateLabel,
        string $verb,
        MessageInterface $message,
    ): string {
        $sections = [];
        $facts = [
            'Telegram update: ' . $updateLabel,
            'Update id: ' . $updateId,
            'Chat: ' . $this->describeChat($message->chat),
            'From: ' . $this->describeActor($message),
            'Message id: ' . $message->messageId,
            'Sent at: ' . $this->formatTimestamp($message->date),
        ];

        if ($message->editDate !== null) {
            $facts[] = 'Edited at: ' . $this->formatTimestamp($message->editDate);
        }

        if ($message->messageThreadId !== null) {
            $facts[] = 'Thread: ' . $message->messageThreadId;
        }

        if ($message->mediaGroupId !== null) {
            $facts[] = 'Media group: ' . $message->mediaGroupId;
        }

        if ($message->replyToMessage !== null) {
            $facts[] = 'Reply to: ' . $this->describeReply($message->replyToMessage);
        }

        if ($message->viaBot !== null) {
            $facts[] = 'Via bot: ' . $this->describeUser($message->viaBot);
        }

        if ($message->authorSignature !== null) {
            $facts[] = 'Author signature: ' . $message->authorSignature;
        }

        if ($message->isAutomaticForward === true) {
            $facts[] = 'Automatic forward: yes';
        }

        if ($message->hasProtectedContent === true) {
            $facts[] = 'Protected content: yes';
        }

        $sections[] = implode("\n", $facts);

        $events = $this->describeMessageEvents($message, $verb);
        if ($events !== []) {
            $sections[] = "What happened:\n- " . implode("\n- ", $events);
        }

        foreach ($this->collectTextBlocks($message) as $block) {
            $sections[] = $block['label'] . ":\n" . $block['value'];
        }

        return implode("\n\n", $sections);
    }

    /**
     * @return list<array{label: string, value: string}>
     */
    private function collectTextBlocks(MessageInterface $message): array
    {
        $blocks = [];

        $text = $this->normalizeText($message->text);
        if ($text !== null) {
            $blocks[] = ['label' => 'Text', 'value' => $text];
        }

        $caption = $this->normalizeText($message->caption);
        if ($caption !== null) {
            $blocks[] = ['label' => 'Caption', 'value' => $caption];
        }

        if ($message->quote !== null) {
            $quote = $this->normalizeText($message->quote->text);
            if ($quote !== null) {
                $blocks[] = ['label' => 'Quoted fragment', 'value' => $quote];
            }
        }

        if ($message->poll !== null) {
            $pollExplanation = $this->normalizeText($message->poll->explanation);
            if ($pollExplanation !== null) {
                $blocks[] = ['label' => 'Poll explanation', 'value' => $pollExplanation];
            }
        }

        return $blocks;
    }

    /**
     * @return list<string>
     */
    private function describeMessageEvents(MessageInterface $message, string $verb): array
    {
        $events = [];

        if ($this->normalizeText($message->text) !== null) {
            $events[] = $verb . ' a text message';
        }

        if ($message->photo !== null && $message->photo !== []) {
            $events[] = $verb . ' ' . $this->describePhoto($message->photo);
        }

        if ($message->document !== null) {
            $events[] = $verb . ' ' . $this->describeDocument($message->document);
        }

        if ($message->animation !== null) {
            $details = $this->joinDetails([
                $message->animation->width . 'x' . $message->animation->height,
                $this->formatDuration($message->animation->duration),
                $message->animation->fileName,
            ]);

            $events[] = $verb . ' an animation' . ($details !== '' ? ' (' . $details . ')' : '');
        }

        if ($message->audio !== null) {
            $details = $this->joinDetails([
                $message->audio->title,
                $message->audio->performer,
                $this->formatDuration($message->audio->duration),
            ]);

            $events[] = $verb . ' audio' . ($details !== '' ? ' (' . $details . ')' : '');
        }

        if ($message->video !== null) {
            $details = $this->joinDetails([
                $message->video->width . 'x' . $message->video->height,
                $this->formatDuration($message->video->duration),
                $message->video->fileName,
            ]);

            $events[] = $verb . ' a video' . ($details !== '' ? ' (' . $details . ')' : '');
        }

        if ($message->videoNote !== null) {
            $details = $this->joinDetails([
                $message->videoNote->length . 'px',
                $this->formatDuration($message->videoNote->duration),
            ]);

            $events[] = $verb . ' a video note' . ($details !== '' ? ' (' . $details . ')' : '');
        }

        if ($message->voice !== null) {
            $details = $this->joinDetails([
                $this->formatDuration($message->voice->duration),
                $message->voice->mimeType,
            ]);

            $events[] = $verb . ' a voice message' . ($details !== '' ? ' (' . $details . ')' : '');
        }

        if ($message->sticker !== null) {
            $details = $this->joinDetails([
                $message->sticker->emoji,
                $message->sticker->setName,
                $message->sticker->isAnimated ? 'animated' : null,
                $message->sticker->isVideo ? 'video' : null,
            ]);

            $events[] = $verb . ' a sticker' . ($details !== '' ? ' (' . $details . ')' : '');
        }

        if ($message->poll !== null) {
            $options = array_map(
                static fn ($option): string => $option->text,
                array_slice($message->poll->options, 0, 4),
            );

            $details = $this->joinDetails([
                $this->quote($message->poll->question),
                $options !== [] ? implode(', ', $options) : null,
            ]);

            $events[] = $verb . ' a poll' . ($details !== '' ? ' (' . $details . ')' : '');
        }

        if ($message->dice !== null) {
            $events[] = sprintf(
                '%s a dice roll (%s %d)',
                $verb,
                $message->dice->emoji,
                $message->dice->value,
            );
        }

        if ($message->contact !== null) {
            $name = trim($message->contact->firstName . ' ' . ($message->contact->lastName ?? ''));
            $details = $this->joinDetails([
                $name !== '' ? $name : null,
                $message->contact->phoneNumber,
            ]);

            $events[] = 'shared a contact' . ($details !== '' ? ' (' . $details . ')' : '');
        }

        if ($message->venue !== null) {
            $details = $this->joinDetails([
                $message->venue->title,
                $message->venue->address,
                $this->formatCoordinates(
                    $message->venue->location->latitude,
                    $message->venue->location->longitude,
                ),
            ]);

            $events[] = 'shared a venue' . ($details !== '' ? ' (' . $details . ')' : '');
        } elseif ($message->location !== null) {
            $details = $this->joinDetails([
                $this->formatCoordinates($message->location->latitude, $message->location->longitude),
                $message->location->horizontalAccuracy !== null
                    ? 'accuracy ' . rtrim(rtrim(number_format($message->location->horizontalAccuracy, 2, '.', ''), '0'), '.') . 'm'
                    : null,
            ]);

            $events[] = 'shared a location' . ($details !== '' ? ' (' . $details . ')' : '');
        }

        if ($message->newChatMembers !== null && $message->newChatMembers !== []) {
            $events[] = 'added new members: ' . $this->formatUserList($message->newChatMembers);
        }

        if ($message->leftChatMember !== null) {
            $events[] = 'removed member: ' . $this->describeUser($message->leftChatMember);
        }

        if ($message->newChatTitle !== null) {
            $events[] = 'changed the chat title to ' . $this->quote($message->newChatTitle);
        }

        if ($message->newChatPhoto !== null && $message->newChatPhoto !== []) {
            $events[] = 'updated the chat photo';
        }

        if ($message->deleteChatPhoto === true) {
            $events[] = 'deleted the chat photo';
        }

        if ($message->groupChatCreated === true) {
            $events[] = 'created the group chat';
        }

        if ($message->supergroupChatCreated === true) {
            $events[] = 'created the supergroup';
        }

        if ($message->channelChatCreated === true) {
            $events[] = 'created the channel';
        }

        if ($message->pinnedMessage !== null) {
            $events[] = 'pinned a message';
        }

        if ($message->story !== null) {
            $events[] = $verb . ' a forwarded story';
        }

        if ($message->checklist !== null) {
            $events[] = $verb . ' a checklist';
        }

        if ($message->writeAccessAllowed !== null) {
            $events[] = 'allowed the bot to write messages';
        }

        if ($message->connectedWebsite !== null) {
            $events[] = 'logged in via ' . $this->quote($message->connectedWebsite);
        }

        if ($message->webAppData !== null) {
            $events[] = 'submitted data from a Web App';
        }

        if ($message->showCaptionAboveMedia === true) {
            $events[] = 'placed the caption above the media';
        }

        if ($message->hasMediaSpoiler === true) {
            $events[] = 'marked the media as spoiler';
        }

        if ($events === []) {
            $events[] = 'contained a Telegram payload without a dedicated formatter';
        }

        return $events;
    }

    /**
     * @return list<string>
     */
    private function collectImageUrls(MessageInterface $message): array
    {
        if ($this->fileUrlResolver === null) {
            return [];
        }

        $urls = [];
        $seen = [];

        $appendImage = function (?string $fileId) use (&$urls, &$seen): void {
            if ($fileId === null || $fileId === '') {
                return;
            }

            $url = $this->fileUrlResolver?->resolve($fileId);
            if ($url === null || isset($seen[$url])) {
                return;
            }

            $seen[$url] = true;
            $urls[] = $url;
        };

        $appendImage($this->pickLargestPhoto($message->photo)?->fileId);

        if ($message->document !== null && $this->isImageDocument($message->document)) {
            $appendImage($message->document->fileId);
        }

        if ($message->sticker !== null && !$message->sticker->isAnimated && !$message->sticker->isVideo) {
            $appendImage($message->sticker->fileId);
        }

        $appendImage($message->animation?->thumbnail?->fileId);
        $appendImage($message->video?->thumbnail?->fileId);
        $appendImage($message->videoNote?->thumbnail?->fileId);
        $appendImage($message->audio?->thumbnail?->fileId);
        $appendImage($this->pickLargestPhoto($message->newChatPhoto)?->fileId);

        return $urls;
    }

    private function resolveParticipantReference(MessageInterface $message): ?string
    {
        return $message->from !== null
            ? $this->resolveUserReference($message->from)
            : $this->resolveChatReference($message->senderChat);
    }

    private function resolveUserReference(UserInterface $user): string
    {
        return $user->username ?? 'user_' . $user->id;
    }

    private function resolveChatReference(?ChatInterface $chat): ?string
    {
        if ($chat === null) {
            return null;
        }

        return $chat->username !== null
            ? 'chat_' . $chat->username
            : 'chat_' . $chat->id;
    }

    private function describeActor(MessageInterface $message): string
    {
        if ($message->from !== null) {
            return $this->describeUser($message->from);
        }

        if ($message->senderChat !== null) {
            return 'chat ' . $this->describeChat($message->senderChat);
        }

        return 'unknown sender';
    }

    private function describeUser(UserInterface $user): string
    {
        $name = trim($user->firstName . ' ' . ($user->lastName ?? ''));
        $identity = $name !== '' ? $name : 'user';
        $details = ['id ' . $user->id];

        if ($user->username !== null) {
            array_unshift($details, '@' . $user->username);
        }

        return $identity . ' (' . implode(', ', $details) . ')';
    }

    /**
     * @param array<UserInterface> $users
     */
    private function formatUserList(array $users): string
    {
        return implode(', ', array_map(
            fn (UserInterface $user): string => $this->describeUser($user),
            $users,
        ));
    }

    private function describeChat(ChatInterface $chat): string
    {
        $label = $chat->title
            ?? $chat->username
            ?? trim(($chat->firstName ?? '') . ' ' . ($chat->lastName ?? ''));

        $suffix = $label !== '' ? ' ' . $this->quote($label) : '';
        $details = ['id ' . $chat->id];

        if ($chat->username !== null) {
            $details[] = '@' . $chat->username;
        }

        return $chat->type . $suffix . ' (' . implode(', ', $details) . ')';
    }

    private function describeReply(MessageInterface $reply): string
    {
        $sender = $reply->from !== null
            ? $this->describeUser($reply->from)
            : ($reply->senderChat !== null ? 'chat ' . $this->describeChat($reply->senderChat) : null);

        $preview = $this->normalizeText($reply->text)
            ?? $this->normalizeText($reply->caption)
            ?? ($reply->photo !== null ? 'photo' : null)
            ?? ($reply->document !== null ? 'document' : null)
            ?? ($reply->sticker !== null ? 'sticker' : null)
            ?? ($reply->video !== null ? 'video' : null);

        $parts = ['#' . $reply->messageId];

        if ($sender !== null) {
            $parts[] = 'by ' . $sender;
        }

        if ($preview !== null) {
            $parts[] = $this->quote($this->truncate($preview, 80));
        }

        return implode(' ', $parts);
    }

    /**
     * @param array<PhotoSizeInterface>|null $photos
     */
    private function pickLargestPhoto(?array $photos): ?PhotoSizeInterface
    {
        if ($photos === null || $photos === []) {
            return null;
        }

        usort($photos, static function (PhotoSizeInterface $left, PhotoSizeInterface $right): int {
            $leftArea = $left->width * $left->height;
            $rightArea = $right->width * $right->height;

            return [$rightArea, $right->fileSize ?? 0] <=> [$leftArea, $left->fileSize ?? 0];
        });

        return $photos[0];
    }

    /**
     * @param array<PhotoSizeInterface> $photos
     */
    private function describePhoto(array $photos): string
    {
        $largest = $this->pickLargestPhoto($photos);

        if ($largest === null) {
            return 'a photo';
        }

        $details = $this->joinDetails([
            $largest->width . 'x' . $largest->height,
            $this->formatBytes($largest->fileSize),
        ]);

        return 'a photo' . ($details !== '' ? ' (' . $details . ')' : '');
    }

    private function describeDocument(DocumentInterface $document): string
    {
        $kind = $this->isImageDocument($document) ? 'an image document' : 'a document';
        $details = $this->joinDetails([
            $document->fileName,
            $document->mimeType,
            $this->formatBytes($document->fileSize),
        ]);

        return $kind . ($details !== '' ? ' (' . $details . ')' : '');
    }

    private function isImageDocument(DocumentInterface $document): bool
    {
        return $document->mimeType !== null && str_starts_with($document->mimeType, 'image/');
    }

    private function normalizeText(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        $normalized = trim($text);

        return $normalized === '' ? null : $normalized;
    }

    private function formatTimestamp(int $timestamp): string
    {
        $timezone = new DateTimeZone(date_default_timezone_get());

        return (new DateTimeImmutable('@' . $timestamp))
            ->setTimezone($timezone)
            ->format('Y-m-d H:i:s P');
    }

    private function formatDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . 's';
        }

        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $remainingSeconds = $seconds % 60;

        $parts = [];
        if ($hours > 0) {
            $parts[] = $hours . 'h';
        }

        if ($minutes > 0) {
            $parts[] = $minutes . 'm';
        }

        if ($remainingSeconds > 0 || $parts === []) {
            $parts[] = $remainingSeconds . 's';
        }

        return implode(' ', $parts);
    }

    private function formatBytes(?int $bytes): ?string
    {
        if ($bytes === null) {
            return null;
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $value = (float) $bytes;
        $unitIndex = 0;

        while ($value >= 1024 && $unitIndex < count($units) - 1) {
            $value /= 1024;
            $unitIndex++;
        }

        $precision = $unitIndex === 0 ? 0 : ($value < 10 ? 1 : 0);

        return number_format($value, $precision, '.', '') . ' ' . $units[$unitIndex];
    }

    private function formatCoordinates(float $latitude, float $longitude): string
    {
        return sprintf('%.5f, %.5f', $latitude, $longitude);
    }

    /**
     * @param list<string|null> $parts
     */
    private function joinDetails(array $parts): string
    {
        return implode(', ', array_values(array_filter(
            $parts,
            static fn (?string $part): bool => $part !== null && $part !== '',
        )));
    }

    private function truncate(string $text, int $limit): string
    {
        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, $limit - 1)) . '…';
    }

    private function quote(string $text): string
    {
        return '"' . $text . '"';
    }
}
