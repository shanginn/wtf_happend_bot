<?php

declare(strict_types=1);

namespace Bot\AgenticWorkflow;

use Bot\AgenticWorkflow\AgenticWorkflow;
use Bot\AgenticWorkflow\AgenticWorkflowInput;
use Bot\Telegram\Update;
use Temporal\Client\WorkflowClientInterface;
use Temporal\Client\WorkflowOptions;
use Temporal\Common\IdReusePolicy;

class AgenticWorkflowHandler
{
    public function __construct(
        private WorkflowClientInterface $client,
    ) {}

    public static function generateWorkflowId(Update $update): string
    {
        $message = $update->effectiveMessage;

        return sprintf('Chat %d [Root]', $message->chat->id);
    }

    public function handleUpdate(Update $update): void
    {
        $workflowId = self::generateWorkflowId($update);

        $workflow = $this->client->newWorkflowStub(
            AgenticWorkflow::class,
            options: new WorkflowOptions()
                ->withWorkflowId($workflowId)
                ->withWorkflowIdReusePolicy(IdReusePolicy::AllowDuplicate)
        );

        $this->client->signalWithStart(
            workflow: $workflow,
            signal: 'addUpdate',
            signalArgs: [$update],
            startArgs: [new AgenticWorkflowInput(chatId: $update->effectiveMessage->chat->id)],
        );
    }
}
