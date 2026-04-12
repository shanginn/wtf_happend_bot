<?php

declare(strict_types=1);

namespace Bot\Agent;

use Bot\Telegram\InputMessageView;

class LlmMessageTransformer
{
    public static function toUserMessage(InputMessageView $view): UserMessageView
    {
        return new UserMessageView(
            text: $view->text,
            name: $view->participantReference,
            images: $view->imageUrls,
        );
    }
}