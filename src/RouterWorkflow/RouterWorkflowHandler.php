<?php

declare(strict_types=1);

namespace Bot\RouterWorkflow;

use Bot\Telegram\Update;
use Temporal\Client\WorkflowClientInterface;
use Temporal\Client\WorkflowOptions;
use Temporal\Common\IdReusePolicy;

class RouterWorkflowHandler
{
    public function __construct(
        private WorkflowClientInterface $client,
    ) {}

    public static function generateWorkflowId(Update $update): string
    {
        $message = $update->effectiveMessage;

        return sprintf(
            '[Router] chat %d, topic %s',
            $message->chat->id,
            $message->messageThreadId ?? 'general',
        );
    }

    public function handleUpdate(Update $update): void
    {
        $workflowId = self::generateWorkflowId($update);

        $workflow = $this->client->newWorkflowStub(
            RouterWorkflow::class,
            options: new WorkflowOptions()
                ->withWorkflowId($workflowId)
                ->withWorkflowIdReusePolicy(IdReusePolicy::AllowDuplicate)
        );

        $this->client->signalWithStart(
            workflow: $workflow,
            signal: 'addUpdate',
            signalArgs: [$update],
            startArgs: [new RouterWorkflowInput(
                chatId: $update->effectiveMessage->chat->id,
                messageThreadId: $update->effectiveMessage->messageThreadId,
            )],
        );
    }
}
