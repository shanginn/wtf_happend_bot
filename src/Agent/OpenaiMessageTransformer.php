<?php

declare(strict_types=1);

namespace Bot\Agent;

use Bot\Telegram\InputMessageView;
use Shanginn\Openai\ChatCompletion\Message\AssistantMessage;
use Shanginn\Openai\ChatCompletion\Message\User\ImageContentPart;
use Shanginn\Openai\ChatCompletion\Message\User\ImageDetailLevelEnum;
use Shanginn\Openai\ChatCompletion\Message\User\TextContentPart;
use Shanginn\Openai\ChatCompletion\Message\UserMessage;

class OpenaiMessageTransformer
{
    public static function toChatUserMessage(InputMessageView $view): UserMessage
    {
        return new UserMessage(
            content: static::toOpenaiContent($view),
            name: $view->participantReference,
        );
    }

    /**
     * @return string|array<TextContentPart|ImageContentPart>
     */
    private static function toOpenaiContent(InputMessageView $view): string|array
    {
        if (count($view->imageUrls) === 0) {
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
}
