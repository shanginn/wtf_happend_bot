<?php

declare(strict_types=1);

namespace Bot\Handler;

use Bot\Space\Persistence\SpaceBindingKey;
use Bot\Space\Persistence\SpaceMembershipStateStore;
use Bot\Space\Runtime\SpaceIdentityResolverInterface;
use Bot\Space\Workflow\SpaceAgentWorkflow;
use Bot\Space\Workflow\SpaceAgentWorkflowHandler;
use Bot\Telegram\Update;
use Phenogram\Bindings\Types\Interfaces\ChatMemberAdministratorInterface;
use Phenogram\Bindings\Types\Interfaces\ChatMemberBannedInterface;
use Phenogram\Bindings\Types\Interfaces\ChatMemberInterface;
use Phenogram\Bindings\Types\Interfaces\ChatMemberLeftInterface;
use Phenogram\Bindings\Types\Interfaces\ChatMemberMemberInterface;
use Phenogram\Bindings\Types\Interfaces\ChatMemberOwnerInterface;
use Phenogram\Bindings\Types\Interfaces\UpdateInterface;
use Phenogram\Framework\Handler\UpdateHandlerInterface;
use Phenogram\Framework\TelegramBot;
use Temporal\Client\GRPC\StatusCode;
use Temporal\Client\WorkflowClientInterface;
use Temporal\Exception\Client\ServiceClientException;
use Temporal\Exception\Client\WorkflowNotFoundException;
use UnexpectedValueException;

final readonly class SpaceMembershipLifecycleHandler implements UpdateHandlerInterface
{
    public function __construct(
        private SpaceMembershipStateStore $states,
        private SpaceIdentityResolverInterface $spaces,
        private WorkflowClientInterface $client,
        private string $botInstanceId,
        private string $hostReleaseId = 'local',
    ) {}

    public static function supports(UpdateInterface $update): bool
    {
        return $update->myChatMember !== null;
    }

    public function handle(UpdateInterface $update, TelegramBot $bot): void
    {
        if (!$update instanceof Update) {
            throw new UnexpectedValueException(
                'Space membership lifecycle requires the bot Telegram update type.',
            );
        }

        $membership = $update->myChatMember;
        if ($membership === null) {
            return;
        }

        $active = self::activeState($membership->newChatMember);
        if ($active === null) {
            return;
        }

        $status   = self::status($membership->newChatMember);
        $spaceIds = $this->states->apply(
            new SpaceBindingKey(
                botInstanceId: $this->botInstanceId,
                platform: 'telegram',
                externalConversationId: $membership->chat->id,
            ),
            updateId: $update->updateId,
            membershipStatus: $status,
            active: $active,
            eventAt: $membership->date,
        );
        if ($spaceIds === null) {
            return;
        }

        if ($active) {
            // The transition above reactivates an existing root before the
            // resolver reads it, or lets the resolver provision a missing root.
            $this->spaces->resolve($update);

            return;
        }

        foreach ($spaceIds as $spaceId) {
            try {
                $this->client->newUntypedRunningWorkflowStub(
                    SpaceAgentWorkflowHandler::workflowId($spaceId, $this->hostReleaseId),
                    null,
                    SpaceAgentWorkflow::WORKFLOW_TYPE,
                )->terminate(
                    reason: 'Bot membership became inactive for the Space conversation',
                    details: [
                        'telegramUpdateId' => $update->updateId,
                        'membershipStatus' => $status,
                    ],
                );
            } catch (WorkflowNotFoundException) {
                // A repeated membership update or an idle Space is already converged.
            } catch (ServiceClientException $error) {
                if ($error->getCode() !== StatusCode::NOT_FOUND) {
                    throw $error;
                }
            }
        }
    }

    private static function activeState(ChatMemberInterface $member): ?bool
    {
        return match (true) {
            $member instanceof ChatMemberOwnerInterface,
            $member instanceof ChatMemberAdministratorInterface,
            $member instanceof ChatMemberMemberInterface => true,
            $member instanceof ChatMemberLeftInterface,
            $member instanceof ChatMemberBannedInterface => false,
            default                                      => null,
        };
    }

    private static function status(ChatMemberInterface $member): string
    {
        return match (true) {
            $member instanceof ChatMemberOwnerInterface         => 'creator',
            $member instanceof ChatMemberAdministratorInterface => 'administrator',
            $member instanceof ChatMemberMemberInterface        => 'member',
            $member instanceof ChatMemberLeftInterface          => 'left',
            $member instanceof ChatMemberBannedInterface        => 'kicked',
            default                                             => throw new UnexpectedValueException('Telegram membership status is unsupported.'),
        };
    }
}
