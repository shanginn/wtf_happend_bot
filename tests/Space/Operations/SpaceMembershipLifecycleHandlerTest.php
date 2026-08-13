<?php

declare(strict_types=1);

namespace Tests\Space\Operations;

use Bot\Handler\SpaceMembershipLifecycleHandler;
use Bot\Space\Persistence\SpaceMembershipStateStore;
use Bot\Space\Runtime\SpaceIdentity;
use Bot\Space\Runtime\SpaceIdentityResolverInterface;
use Bot\Space\Workflow\SpaceAgentWorkflow;
use Bot\Telegram\Update;
use Cycle\Database\DatabaseInterface;
use Cycle\Database\StatementInterface;
use Mockery;
use Phenogram\Bindings\Factories\ChatFactory;
use Phenogram\Bindings\Factories\ChatMemberLeftFactory;
use Phenogram\Bindings\Factories\ChatMemberMemberFactory;
use Phenogram\Bindings\Factories\ChatMemberRestrictedFactory;
use Phenogram\Bindings\Factories\ChatMemberUpdatedFactory;
use Phenogram\Bindings\Factories\UpdateFactory;
use Phenogram\Framework\TelegramBot;
use Temporal\Client\WorkflowClientInterface;
use Temporal\Client\WorkflowStubInterface;
use Temporal\Exception\Client\WorkflowNotFoundException;
use Temporal\Workflow\WorkflowExecution;
use Tests\TestCase;

final class SpaceMembershipLifecycleHandlerTest extends TestCase
{
    private const int CHAT_ID = -10042;
    private const string RELEASE_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function testLeftRetiresConversationAndTerminatesEveryBoundWorkflow(): void
    {
        $update = self::membershipUpdate(
            updateId: 901,
            date: 1_725_000_000,
            member: ChatMemberLeftFactory::make(status: 'left'),
        );
        $database = self::acceptedDatabase(
            expectedStatus: 'left',
            expectedActive: false,
            spaceIds: ['spc_root_123', 'spc_topic_456'],
        );
        $resolver = Mockery::mock(SpaceIdentityResolverInterface::class);
        $resolver->shouldNotReceive('resolve');

        $rootWorkflow = Mockery::mock(WorkflowStubInterface::class);
        $rootWorkflow->shouldReceive('terminate')
            ->once()
            ->with(
                'Bot membership became inactive for the Space conversation',
                ['telegramUpdateId' => 901, 'membershipStatus' => 'left'],
            );
        $missingWorkflow = Mockery::mock(WorkflowStubInterface::class);
        $missingWorkflow->shouldReceive('terminate')
            ->once()
            ->andThrow(new WorkflowNotFoundException(
                null,
                new WorkflowExecution('space-agent/spc_topic_456/v1/release/' . self::RELEASE_ID),
                SpaceAgentWorkflow::WORKFLOW_TYPE,
            ));
        $client = Mockery::mock(WorkflowClientInterface::class);
        $client->shouldReceive('newUntypedRunningWorkflowStub')
            ->once()
            ->ordered()
            ->with(
                'space-agent/spc_root_123/v1/release/' . self::RELEASE_ID,
                null,
                SpaceAgentWorkflow::WORKFLOW_TYPE,
            )
            ->andReturn($rootWorkflow);
        $client->shouldReceive('newUntypedRunningWorkflowStub')
            ->once()
            ->ordered()
            ->with(
                'space-agent/spc_topic_456/v1/release/' . self::RELEASE_ID,
                null,
                SpaceAgentWorkflow::WORKFLOW_TYPE,
            )
            ->andReturn($missingWorkflow);

        $handler = new SpaceMembershipLifecycleHandler(
            new SpaceMembershipStateStore($database),
            $resolver,
            $client,
            botInstanceId: 'primary-bot',
            hostReleaseId: self::RELEASE_ID,
        );

        self::assertTrue(SpaceMembershipLifecycleHandler::supports($update));
        $handler->handle($update, Mockery::mock(TelegramBot::class));
        $this->addToAssertionCount(1);
    }

    public function testMemberReactivatesConversationBeforeResolvingItsRoot(): void
    {
        $update = self::membershipUpdate(
            updateId: 902,
            date: 1_725_000_100,
            member: ChatMemberMemberFactory::make(status: 'member'),
        );
        $database = self::acceptedDatabase(
            expectedStatus: 'member',
            expectedActive: true,
            spaceIds: ['spc_root_123'],
        );
        $identity = new SpaceIdentity(
            spaceId: 'spc_root_123',
            platform: 'telegram',
            botInstanceId: 'primary-bot',
            externalConversationId: (string) self::CHAT_ID,
            externalThreadId: null,
            chatId: self::CHAT_ID,
            chatType: 'supergroup',
            topicId: null,
        );
        $resolver = Mockery::mock(SpaceIdentityResolverInterface::class);
        $resolver->shouldReceive('resolve')->once()->with($update)->andReturn($identity);
        $client = Mockery::mock(WorkflowClientInterface::class);
        $client->shouldNotReceive('newUntypedRunningWorkflowStub');

        (new SpaceMembershipLifecycleHandler(
            new SpaceMembershipStateStore($database),
            $resolver,
            $client,
            botInstanceId: 'primary-bot',
            hostReleaseId: self::RELEASE_ID,
        ))->handle($update, Mockery::mock(TelegramBot::class));

        $this->addToAssertionCount(1);
    }

    public function testRestrictedMembershipIsConsumedWithoutChangingLifecycle(): void
    {
        $update = self::membershipUpdate(
            updateId: 903,
            date: 1_725_000_200,
            member: ChatMemberRestrictedFactory::make(status: 'restricted'),
        );
        $database = Mockery::mock(DatabaseInterface::class);
        $database->shouldNotReceive('transaction');
        $resolver = Mockery::mock(SpaceIdentityResolverInterface::class);
        $resolver->shouldNotReceive('resolve');
        $client = Mockery::mock(WorkflowClientInterface::class);
        $client->shouldNotReceive('newUntypedRunningWorkflowStub');

        self::assertTrue(SpaceMembershipLifecycleHandler::supports($update));
        (new SpaceMembershipLifecycleHandler(
            new SpaceMembershipStateStore($database),
            $resolver,
            $client,
            botInstanceId: 'primary-bot',
        ))->handle($update, Mockery::mock(TelegramBot::class));
    }

    /**
     * @param \Phenogram\Bindings\Types\Interfaces\ChatMemberInterface $member
     */
    private static function membershipUpdate(int $updateId, int $date, object $member): Update
    {
        $update = UpdateFactory::make(
            updateId: $updateId,
            myChatMember: ChatMemberUpdatedFactory::make(
                chat: ChatFactory::make(id: self::CHAT_ID, type: 'supergroup'),
                date: $date,
                oldChatMember: ChatMemberMemberFactory::make(status: 'member'),
                newChatMember: $member,
            ),
        );
        self::assertInstanceOf(Update::class, $update);

        return $update;
    }

    /** @param list<string> $spaceIds */
    private static function acceptedDatabase(
        string $expectedStatus,
        bool $expectedActive,
        array $spaceIds,
    ): DatabaseInterface {
        $database       = Mockery::mock(DatabaseInterface::class);
        $stateStatement = Mockery::mock(StatementInterface::class);
        $spaceStatement = Mockery::mock(StatementInterface::class);

        $database->shouldReceive('transaction')
            ->once()
            ->andReturnUsing(static fn (callable $callback): mixed => $callback($database));
        $database->shouldReceive('query')
            ->once()
            ->ordered()
            ->withArgs(static function (string $sql, array $parameters) use (
                $expectedStatus,
                $expectedActive,
            ): bool {
                return str_contains($sql, 'INSERT INTO space_membership_states')
                    && $parameters[0] === 'primary-bot'
                    && $parameters[1] === 'telegram'
                    && $parameters[2] === (string) self::CHAT_ID
                    && $parameters[4] === $expectedStatus
                    && $parameters[5] === $expectedActive;
            })
            ->andReturn($stateStatement);
        $stateStatement->shouldReceive('fetch')->once()->andReturn(['last_update_id' => 1]);
        $database->shouldReceive('query')
            ->once()
            ->ordered()
            ->withArgs(static function (string $sql, array $parameters) use ($expectedActive): bool {
                return str_contains($sql, 'UPDATE agent_spaces AS space')
                    && $parameters[0] === ($expectedActive ? 'active' : 'retired')
                    && $parameters[1] === $expectedActive
                    && $parameters[3] === 'primary-bot'
                    && $parameters[4] === 'telegram'
                    && $parameters[5] === (string) self::CHAT_ID;
            })
            ->andReturn($spaceStatement);
        $spaceStatement->shouldReceive('fetchAll')->once()->andReturn(array_map(
            static fn (string $spaceId): array => ['id' => $spaceId],
            $spaceIds,
        ));

        return $database;
    }
}
