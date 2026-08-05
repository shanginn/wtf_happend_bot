<?php

declare(strict_types=1);

namespace Bot\AgenticWorkflow;

use Bot\Telegram\PaymentQueryAnswer;
use Bot\Telegram\Update;
use Carbon\CarbonInterval;
use InvalidArgumentException;
use LogicException;
use Temporal\Client\WorkflowClientInterface;
use Temporal\Client\WorkflowOptions;
use Temporal\Common\IdReusePolicy;

class AgenticWorkflowHandler
{
    private const string PAYMENTS_DISABLED = 'Платежи через этого бота отключены.';

    public function __construct(
        private WorkflowClientInterface $client,
    ) {}

    public static function generateWorkflowIdForChat(int $chatId, ?int $topicId = null): string
    {
        return $topicId === null
            ? sprintf('Chat %d [Root]', $chatId)
            : sprintf('Chat %d [Topic %d]', $chatId, $topicId);
    }

    public static function generateWorkflowId(Update $update): string
    {
        $chat = $update->effectiveChat;
        if ($chat !== null) {
            return self::generateWorkflowIdForChat(
                $chat->id,
                $update->effectiveMessage?->messageThreadId,
            );
        }

        throw new InvalidArgumentException('Cannot resolve workflow id for Telegram update without a chat.');
    }

    public function handleUpdate(Update $update): ?PaymentQueryAnswer
    {
        $paymentAnswer = $this->disabledPaymentAnswer($update);
        if ($paymentAnswer !== null) {
            return $paymentAnswer;
        }

        if ($update->messageReaction !== null || $update->messageReactionCount !== null) {
            return null;
        }

        if ($update->callbackQuery !== null && $update->effectiveMessage === null) {
            return null;
        }

        $chatId = $update->effectiveChat?->id;
        if ($chatId === null) {
            return null;
        }

        $this->signalWithStart($update, $chatId, $update->effectiveMessage?->messageThreadId);

        return null;
    }

    private function signalWithStart(Update $update, int $chatId, ?int $topicId): void
    {
        $workflowId = self::generateWorkflowIdForChat($chatId, $topicId);

        $workflow = $this->client->newWorkflowStub(
            AgenticWorkflow::class,
            options: new WorkflowOptions()
                ->withWorkflowId($workflowId)
                ->withWorkflowTaskTimeout(CarbonInterval::minute())
                ->withWorkflowIdReusePolicy(IdReusePolicy::AllowDuplicate)
        );

        $this->client->signalWithStart(
            workflow: $workflow,
            signal: 'addUpdate',
            signalArgs: [$update],
            startArgs: [new AgenticWorkflowInput(
                chatId: $chatId,
                chatType: $update->effectiveChat?->type
                    ?? throw new LogicException('Telegram chat type is unavailable.'),
                topicId: $topicId,
                model: AgentRuntime::MODEL,
                tools: BotToolCatalog::wireDefinitions(),
            )],
        );
    }

    private function disabledPaymentAnswer(Update $update): ?PaymentQueryAnswer
    {
        if ($update->preCheckoutQuery !== null) {
            return PaymentQueryAnswer::rejectedPreCheckout(
                queryId: $update->preCheckoutQuery->id,
                message: self::PAYMENTS_DISABLED,
            );
        }

        if ($update->shippingQuery !== null) {
            return PaymentQueryAnswer::rejectedShipping(
                queryId: $update->shippingQuery->id,
                message: self::PAYMENTS_DISABLED,
            );
        }

        return null;
    }
}
