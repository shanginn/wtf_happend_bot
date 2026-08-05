<?php

declare(strict_types=1);

namespace Bot\AgenticWorkflow;

use Bot\Telegram\TelegramChatAuthorizationPolicy;
use PiPHP\Temporal\Contract\ToolExecutionGatewayInterface;
use PiPHP\Temporal\DTO\ToolActivityInput;
use PiPHP\Temporal\DTO\ToolActivityResult;

final readonly class RuntimeCapabilityAuthorizationGateway implements ToolExecutionGatewayInterface
{
    private const array PROTECTED_TOOLS = [
        'upsert_runtime_skill',
        'upsert_runtime_tool',
        'set_runtime_capability_status',
    ];

    public function __construct(
        private ToolExecutionGatewayInterface $inner,
        private TelegramChatAuthorizationPolicy $authorization,
    ) {}

    public function execute(ToolActivityInput $input): ToolActivityResult
    {
        if (!in_array($input->name, self::PROTECTED_TOOLS, true)) {
            return $this->inner->execute($input);
        }

        $chatId                = $input->metadata['chatId'] ?? null;
        $chatType              = $input->metadata['chatType'] ?? null;
        $actorUserIds          = $input->metadata['actorUserIds'] ?? null;
        $actorIdentityComplete = $input->metadata['actorIdentityComplete'] ?? null;

        if (
            !is_int($chatId)
            || !is_string($chatType)
            || !is_array($actorUserIds)
            || $actorIdentityComplete !== true
            || !$this->authorization->areActorsAuthorized(
                chatId: $chatId,
                chatType: $chatType,
                actorUserIds: $actorUserIds,
            )
        ) {
            return $this->denied($input);
        }

        return $this->inner->execute($input);
    }

    private function denied(ToolActivityInput $input): ToolActivityResult
    {
        return new ToolActivityResult(
            callId: $input->callId,
            name: $input->name,
            content: [[
                'type' => 'text',
                'text' => 'Изменение runtime-возможностей запрещено: действие должен подтвердить '
                    . 'пользователь личного чата или каждый участвующий владелец/администратор группы.',
            ]],
            isError: true,
            metadata: [
                ...$input->metadata,
                'idempotencyKey'      => $input->idempotencyKey,
                'authorizationDenied' => true,
                'preflight'           => true,
            ],
        );
    }
}
