<?php

declare(strict_types=1);

namespace Bot\Agent;

use Bot\Telegram\TelegramUpdateView;
use Shanginn\Openai\ChatCompletion\Message\User\ImageContentPart;
use Shanginn\Openai\ChatCompletion\Message\User\ImageDetailLevelEnum;
use Shanginn\Openai\ChatCompletion\Message\User\TextContentPart;
use Shanginn\Openai\ChatCompletion\Message\UserMessage;

class OpenaiUpdateTransformer implements UpdateTransformerInterface
{
    public function toChatUserMessage(TelegramUpdateView $view): UserMessage
    {
        return new UserMessage(
            content: $this->toOpenaiContent($view),
            name: $this->toOpenaiParticipantName($view->participantReference),
        );
    }

    /**
     * @return string|array<TextContentPart|ImageContentPart>
     */
    private function toOpenaiContent(TelegramUpdateView $view): string|array
    {
        if ($view->imageUrls === []) {
            return $view->text;
        }

        return [
            new TextContentPart($view->text),
            ...array_map(
                static fn (string $url): ImageContentPart => new ImageContentPart(
                    $url,
                    ImageDetailLevelEnum::AUTO,
                ),
                $view->imageUrls,
            ),
        ];
    }

    private function toOpenaiParticipantName(?string $participantReference): ?string
    {
        if ($participantReference === null) {
            return null;
        }

        $normalized = preg_replace('/[^A-Za-z0-9_-]+/', '_', $participantReference);
        $normalized = trim((string) $normalized, '_');

        if ($normalized === '') {
            return null;
        }

        if (!preg_match('/^[A-Za-z]/', $normalized)) {
            $normalized = 'tg_' . $normalized;
        } elseif (!str_starts_with($normalized, 'tg_')) {
            $normalized = 'tg_' . $normalized;
        }

        return substr($normalized, 0, 64);
    }
}
