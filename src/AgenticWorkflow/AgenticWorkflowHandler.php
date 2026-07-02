<?php

declare(strict_types=1);

namespace Bot\AgenticWorkflow;

use Bot\AgenticWorkflow\AgenticWorkflow;
use Bot\AgenticWorkflow\AgenticWorkflowInput;
use Bot\Telegram\InvoiceWorkflowPayload;
use Bot\Telegram\InvoiceWorkflowRoute;
use Bot\Telegram\PaymentQueryAnswer;
use Bot\Telegram\Update;
use Carbon\CarbonInterval;
use Temporal\Client\WorkflowStubInterface;
use Temporal\Client\WorkflowClientInterface;
use Temporal\Client\WorkflowOptions;
use Temporal\Common\IdReusePolicy;
use Temporal\DataConverter\Type;
use Temporal\Exception\Client\WorkflowException;
use Temporal\Exception\Client\WorkflowUpdateRPCTimeoutOrCanceledException;

class AgenticWorkflowHandler
{
    private const string ROUTE_ERROR = 'Не удалось найти активный workflow для этого инвойса. Создайте инвойс заново.';

    public function __construct(
        private WorkflowClientInterface $client,
    ) {}

    public static function generateWorkflowIdForChat(int $chatId): string
    {
        return sprintf('Chat %d [Root]', $chatId);
    }

    public static function generateWorkflowId(Update $update): string
    {
        $chat = $update->effectiveChat;
        if ($chat !== null) {
            return self::generateWorkflowIdForChat($chat->id);
        }

        $route = InvoiceWorkflowPayload::routeFromUpdate($update);
        if ($route !== null) {
            return self::generateWorkflowIdForChat($route->chatId);
        }

        throw new \InvalidArgumentException('Cannot resolve workflow id for Telegram update without chat or invoice route.');
    }

    public function handleUpdate(Update $update): ?PaymentQueryAnswer
    {
        $route = InvoiceWorkflowPayload::routeFromUpdate($update);
        if ($route !== null) {
            return $this->handleRoutedPaymentUpdate($update, $route);
        }

        $unroutableAnswer = $this->paymentQueryRouteError($update);
        if ($unroutableAnswer !== null) {
            return $unroutableAnswer;
        }

        $chatId = $update->effectiveChat?->id;
        if ($chatId === null) {
            return null;
        }

        $this->signalWithStart($update, $chatId);

        return null;
    }

    private function signalWithStart(Update $update, int $chatId): void
    {
        $workflowId = self::generateWorkflowIdForChat($chatId);

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
            startArgs: [new AgenticWorkflowInput(chatId: $chatId)],
        );
    }

    private function handleRoutedPaymentUpdate(Update $update, InvoiceWorkflowRoute $route): ?PaymentQueryAnswer
    {
        try {
            $workflow = $this->runningWorkflowForRoute($route);
            $result = $workflow
                ->update(AgenticWorkflow::PAYMENT_UPDATE_NAME, $update)
                ?->getValue(0, Type::TYPE_ARRAY);

            return PaymentQueryAnswer::fromWorkflowPayload(is_array($result) ? $result : null);
        } catch (WorkflowException | WorkflowUpdateRPCTimeoutOrCanceledException) {
            return $this->paymentQueryRouteError($update);
        }
    }

    private function runningWorkflowForRoute(InvoiceWorkflowRoute $route): WorkflowStubInterface
    {
        return $this->client->newUntypedRunningWorkflowStub(
            self::generateWorkflowIdForChat($route->chatId),
            null,
            AgenticWorkflow::WORKFLOW_TYPE,
        );
    }

    private function paymentQueryRouteError(Update $update): ?PaymentQueryAnswer
    {
        if ($update->preCheckoutQuery !== null) {
            return PaymentQueryAnswer::rejectedPreCheckout(
                queryId: $update->preCheckoutQuery->id,
                message: self::ROUTE_ERROR,
            );
        }

        if ($update->shippingQuery !== null) {
            return PaymentQueryAnswer::rejectedShipping(
                queryId: $update->shippingQuery->id,
                message: self::ROUTE_ERROR,
            );
        }

        return null;
    }
}
