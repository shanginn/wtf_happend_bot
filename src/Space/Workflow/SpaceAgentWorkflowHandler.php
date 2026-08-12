<?php

declare(strict_types=1);

namespace Bot\Space\Workflow;

use Bot\Config\TemporalExecutionIdentity;
use Bot\Space\Runtime\SpaceIdentity;
use Bot\Space\Runtime\SpaceIdentityResolverInterface;
use Bot\Telegram\PaymentQueryAnswer;
use Bot\Telegram\Update;
use Carbon\CarbonInterval;
use Temporal\Client\WorkflowClientInterface;
use Temporal\Client\WorkflowOptions;
use Temporal\Common\IdReusePolicy;

final readonly class SpaceAgentWorkflowHandler
{
    private const string PAYMENTS_DISABLED = 'Платежи через этого бота отключены.';

    public function __construct(
        private WorkflowClientInterface $client,
        private SpaceIdentityResolverInterface $spaces,
        private string $taskQueue = 'space-agent-v1',
        private string $hostReleaseId = 'local',
    ) {}

    public static function workflowId(SpaceIdentity|string $space, string $hostReleaseId = 'local'): string
    {
        $spaceId = $space instanceof SpaceIdentity ? $space->spaceId : $space;

        return TemporalExecutionIdentity::spaceAgentWorkflowId($spaceId, $hostReleaseId);
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
        if ($update->effectiveChat === null) {
            return null;
        }

        $space    = $this->spaces->resolve($update);
        $workflow = $this->client->newWorkflowStub(
            SpaceAgentWorkflow::class,
            options: new WorkflowOptions()
                ->withWorkflowId(self::workflowId($space, $this->hostReleaseId))
                ->withTaskQueue($this->taskQueue)
                ->withWorkflowTaskTimeout(CarbonInterval::minute())
                ->withWorkflowIdReusePolicy(IdReusePolicy::AllowDuplicate),
        );
        $this->client->signalWithStart(
            workflow: $workflow,
            signal: 'addUpdate',
            signalArgs: [$update],
            startArgs: [SpaceAgentWorkflowInput::start($space)],
        );

        return null;
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
