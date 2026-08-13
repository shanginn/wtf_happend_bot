<?php

declare(strict_types=1);

namespace Bot\Space\Workflow;

use Bot\Telegram\InputMessageView;
use PiPHP\Temporal\DTO\AgentMessage;

final class SpaceTelegramAgentMessageMapper
{
    public static function map(InputMessageView $view): AgentMessage
    {
        $text = trim($view->text);
        if ($view->participantReference !== null) {
            $text .= "\nParticipant reference: {$view->participantReference}";
        }
        if ($view->updateId !== null) {
            // Capability mutations are authorized against one exact Telegram
            // update, not against the aggregate set of actors in a coalesced
            // batch. Expose the host-authored identifier to the model so it can
            // pass that opaque reference back to the publication tool.
            $text .= "\nTelegram update reference: {$view->updateId}";
        }
        if ($view->imageAttachmentCount > 0) {
            $text .= sprintf(
                "\n\nImage attachments: %d. Visual bytes are not included in this model context.",
                $view->imageAttachmentCount,
            );
        }

        return new AgentMessage(
            role: 'user',
            content: [['type' => 'text', 'text' => $text]],
            name: $view->participantReference,
            metadata: array_filter([
                'telegramParticipant'      => $view->participantReference,
                'telegramUpdateId'         => $view->updateId,
                'telegramMemoryEvidence'   => $view->memoryEvidenceText,
                'telegramMessageTimestamp' => $view->messageTimestamp,
                'imageAttachmentCount'     => $view->imageAttachmentCount > 0
                    ? $view->imageAttachmentCount
                    : null,
            ], static fn (mixed $value): bool => $value !== null),
        );
    }
}
