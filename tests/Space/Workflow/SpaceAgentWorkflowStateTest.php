<?php

declare(strict_types=1);

namespace Tests\Space\Workflow;

use Bot\Activity\TelegramActivity;
use Bot\Space\Runtime\SpaceRuntimeSnapshot;
use Bot\Space\Runtime\SpaceRuntimeSnapshotLoaderActivityInterface;
use Bot\Space\Runtime\SpaceRuntimeSnapshotRequest;
use Bot\Space\Workflow\QueuedSpaceUpdate;
use Bot\Space\Workflow\SpaceAgentWorkflow;
use Bot\Space\Workflow\SpaceAgentWorkflowInput;
use Bot\Space\Workflow\SpaceCommandInvocation;
use Bot\Space\Workflow\SpaceMessageQueue;
use Bot\Telegram\Update;
use Mockery;
use Phenogram\Bindings\Factories\ChatFactory;
use Phenogram\Bindings\Factories\MessageEntityFactory;
use Phenogram\Bindings\Factories\MessageFactory;
use Phenogram\Bindings\Factories\UpdateFactory;
use ReflectionClass;
use ReflectionMethod;
use Spiral\Attributes\AttributeReader;
use Temporal\Internal\Declaration\Reader\ActivityReader;
use Temporal\Internal\Declaration\Reader\WorkflowReader;
use Tests\TestCase;

final class SpaceAgentWorkflowStateTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testOneImmutableRuntimeSnapshotIsLoadedAndPinnedPerBatch(): void
    {
        $snapshot = self::snapshot();
        $loader   = Mockery::mock(SpaceRuntimeSnapshotLoaderActivityInterface::class);
        $loader
            ->shouldReceive('loadSnapshot')
            ->once()
            ->withArgs(static fn (SpaceRuntimeSnapshotRequest $request): bool => $request->spaceId === $snapshot->spaceId && $request->batchId === 'batch-1')
            ->andReturn($snapshot);

        $reflection = new ReflectionClass(SpaceAgentWorkflow::class);
        $workflow   = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('input')->setValue($workflow, self::input());
        $reflection->getProperty('pendingBatchId')->setValue($workflow, 'batch-1');
        $reflection->getProperty('pendingTopicId')->setValue($workflow, 42);
        $reflection->getProperty('pendingBatchMessageCount')->setValue($workflow, 1);
        $reflection->getProperty('runtimeSnapshotActivity')->setValue($workflow, $loader);

        $method = new ReflectionMethod(SpaceAgentWorkflow::class, 'runtimeSnapshotForPendingBatch');
        self::assertSame($snapshot, $method->invoke($workflow));
        self::assertSame($snapshot, $method->invoke($workflow));
    }

    public function testWorkflowExposesOnlyTheNewCleanCutType(): void
    {
        $definition = (new WorkflowReader(new AttributeReader()))->fromClass(
            SpaceAgentWorkflow::class,
        );

        self::assertSame('SpaceAgentWorkflowV1', $definition->getID());
        self::assertArrayHasKey(SpaceAgentWorkflow::PAUSE_SIGNAL_NAME, $definition->getSignalHandlers());
        self::assertArrayHasKey(SpaceAgentWorkflow::RESUME_SIGNAL_NAME, $definition->getSignalHandlers());
    }

    public function testRuntimeSnapshotActivityUsesStableName(): void
    {
        $activities = (new ActivityReader(new AttributeReader()))->fromClass(
            SpaceRuntimeSnapshotLoaderActivityInterface::class,
        );

        self::assertCount(1, $activities);
        self::assertSame('SpaceRuntime.loadSnapshot', $activities[0]->getID());
    }

    public function testFinishingBatchReleasesPinnedRuntimeForNextNightRelease(): void
    {
        $snapshot   = self::snapshot();
        $reflection = new ReflectionClass(SpaceAgentWorkflow::class);
        $workflow   = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('pipelinePendingSince')->setValue($workflow, 100);
        $reflection->getProperty('pendingBatchMessageCount')->setValue($workflow, 1);
        $reflection->getProperty('pendingBatchId')->setValue($workflow, 'batch-1');
        $reflection->getProperty('pendingRuntimeSnapshot')->setValue($workflow, $snapshot);
        $reflection->getProperty('runtimeSnapshotFailureCount')->setValue($workflow, 2);

        (new ReflectionMethod(SpaceAgentWorkflow::class, 'finishPipeline'))->invoke($workflow);

        self::assertNull($reflection->getProperty('pendingBatchId')->getValue($workflow));
        self::assertNull($reflection->getProperty('pendingTopicId')->getValue($workflow));
        self::assertNull($reflection->getProperty('pendingRuntimeSnapshot')->getValue($workflow));
        self::assertSame(
            0,
            $reflection->getProperty('runtimeSnapshotFailureCount')->getValue($workflow),
        );
    }

    public function testSignalIdentityGuardAcceptsEveryTopicInTheSameChatOnly(): void
    {
        $reflection = new ReflectionClass(SpaceAgentWorkflow::class);
        $workflow   = $reflection->newInstanceWithoutConstructor();
        $belongs    = new ReflectionMethod(SpaceAgentWorkflow::class, 'updateBelongsToSpace');

        $reflection->getProperty('input')->setValue($workflow, self::input());
        self::assertTrue($belongs->invoke($workflow, self::update(7001)));
        self::assertTrue($belongs->invoke($workflow, self::update(7001, 0)));
        self::assertTrue($belongs->invoke($workflow, self::update(7001, 193132)));
        self::assertTrue($belongs->invoke($workflow, self::update(7001, 42, true)));
        self::assertTrue($belongs->invoke($workflow, self::update(7001, 43, true)));
        self::assertFalse($belongs->invoke($workflow, self::update(7002)));
        self::assertFalse($belongs->invoke($workflow, self::update(7002, 42, true)));
    }

    public function testMismatchedSignalIsDroppedBeforeItCanEnterDurableQueue(): void
    {
        $reflection = new ReflectionClass(SpaceAgentWorkflow::class);
        $workflow   = $reflection->newInstanceWithoutConstructor();
        $queue      = new SpaceMessageQueue();
        $reflection->getProperty('input')->setValue($workflow, self::input());
        $reflection->getProperty('updatesQueue')->setValue($workflow, $queue);

        (new ReflectionMethod(SpaceAgentWorkflow::class, 'enqueueUpdate'))->invoke(
            $workflow,
            self::update(7002, 42, true),
        );

        self::assertSame([], $queue->all());
    }

    public function testBatchStopsBeforeAnotherTopicsUpdate(): void
    {
        $topic42a = new QueuedSpaceUpdate(self::update(7001, 42, true), true, 'ingestion-1');
        $topic42b = new QueuedSpaceUpdate(self::update(7001, 42, true, 1), true, 'ingestion-2');
        $topic43  = new QueuedSpaceUpdate(self::update(7001, 43, true, 2), true, 'ingestion-3');

        $method = new ReflectionMethod(SpaceAgentWorkflow::class, 'nextIngestionBatch');
        [$selected, $remaining] = $method->invoke(
            null,
            [$topic42a, $topic43, $topic42b],
            null,
            false,
        );

        self::assertSame([$topic42a, $topic42b], $selected);
        self::assertSame([$topic43], $remaining);
    }

    public function testQueuedDifferentTopicRunsCurrentBatchImmediately(): void
    {
        $reflection = new ReflectionClass(SpaceAgentWorkflow::class);
        $workflow   = $reflection->newInstanceWithoutConstructor();
        $queue      = new SpaceMessageQueue();
        $queue->push(new QueuedSpaceUpdate(
            self::update(7001, 43, true),
            true,
            'ingestion-43',
        ));
        $reflection->getProperty('updatesQueue')->setValue($workflow, $queue);
        $reflection->getProperty('pipelinePendingSince')->setValue($workflow, 100);
        $reflection->getProperty('pendingBatchMessageCount')->setValue($workflow, 1);
        $reflection->getProperty('pendingTopicId')->setValue($workflow, 42);

        self::assertTrue(
            (new ReflectionMethod(SpaceAgentWorkflow::class, 'shouldRunAgentImmediately'))
                ->invoke($workflow),
        );
    }

    public function testSlashCommandIsIsolatedFromOrdinaryMessages(): void
    {
        $ordinary = new QueuedSpaceUpdate(self::update(7001), true, 'ordinary');
        $command  = new QueuedSpaceUpdate(self::commandUpdate('/dimannews', 9001), true, 'command');
        $method   = new ReflectionMethod(SpaceAgentWorkflow::class, 'nextIngestionBatch');

        [$selected, $remaining] = $method->invoke(
            null,
            [$ordinary, $command],
            null,
            false,
        );
        self::assertSame([$ordinary], $selected);
        self::assertSame([$command], $remaining);

        $earlierCommand = new QueuedSpaceUpdate(
            self::commandUpdate('/dimannews', 7001),
            true,
            'earlier-command',
        );
        [$selected, $remaining] = $method->invoke(
            null,
            [$ordinary, $earlierCommand],
            null,
            false,
        );
        self::assertSame([$earlierCommand], $selected);
        self::assertSame([$ordinary], $remaining);
    }

    public function testPendingSlashCommandRunsImmediately(): void
    {
        $reflection = new ReflectionClass(SpaceAgentWorkflow::class);
        $workflow   = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('updatesQueue')->setValue($workflow, new SpaceMessageQueue());
        $reflection->getProperty('pipelinePendingSince')->setValue($workflow, 100);
        $reflection->getProperty('pendingCommandInvocation')->setValue(
            $workflow,
            new SpaceCommandInvocation('dimannews'),
        );

        self::assertTrue(
            (new ReflectionMethod(SpaceAgentWorkflow::class, 'shouldRunAgentImmediately'))
                ->invoke($workflow),
        );
    }

    public function testForeignTargetCommandIsPersistedButNeverEntersAgentPipeline(): void
    {
        $update   = self::commandUpdate('/dimannews@otherbot', 9002);
        $telegram = Mockery::mock(TelegramActivity::class);
        $telegram->shouldReceive('saveUpdates')->once()->andReturn(true);
        $telegram->shouldNotReceive('updateToView');

        $reflection = new ReflectionClass(SpaceAgentWorkflow::class);
        $workflow   = $reflection->newInstanceWithoutConstructor();
        $queue      = new SpaceMessageQueue();
        $queue->push(new QueuedSpaceUpdate($update, true, 'foreign-command'));
        $reflection->getProperty('input')->setValue($workflow, self::input());
        $reflection->getProperty('updatesQueue')->setValue($workflow, $queue);
        $reflection->getProperty('telegramActivity')->setValue($workflow, $telegram);

        (new ReflectionMethod(SpaceAgentWorkflow::class, 'ingestQueuedUpdates'))->invoke($workflow);

        self::assertSame(0, $reflection->getProperty('pipelinePendingSince')->getValue($workflow));
        self::assertNull($reflection->getProperty('pendingBatchId')->getValue($workflow));
        self::assertNull($reflection->getProperty('pendingCommandInvocation')->getValue($workflow));
        self::assertSame(0, $reflection->getProperty('pendingBatchMessageCount')->getValue($workflow));
        self::assertSame([], $reflection->getProperty('messages')->getValue($workflow));
        self::assertSame(1, $reflection->getProperty('processedCount')->getValue($workflow));
    }

    public function testUnregisteredUntargetedGroupCommandIsSilentlyDiscarded(): void
    {
        $reflection = new ReflectionClass(SpaceAgentWorkflow::class);
        $workflow   = $reflection->newInstanceWithoutConstructor();
        $prior      = ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => 'prior']]];
        $command    = ['role' => 'user', 'content' => [['type' => 'text', 'text' => '/edit']]];
        $reflection->getProperty('input')->setValue($workflow, self::input());
        $reflection->getProperty('messages')->setValue($workflow, [$prior, $command]);
        $reflection->getProperty('pendingBatchMessageCount')->setValue($workflow, 1);
        $reflection->getProperty('pendingCommandInvocation')->setValue(
            $workflow,
            new SpaceCommandInvocation('edit'),
        );

        self::assertTrue((new ReflectionMethod(SpaceAgentWorkflow::class, 'runCommand'))->invoke(
            $workflow,
            self::snapshot(),
            null,
            new SpaceCommandInvocation('edit'),
            'terminal-scope',
            'idempotency-key',
        ));
        self::assertSame([$prior], $reflection->getProperty('messages')->getValue($workflow));
        self::assertSame(
            0,
            $reflection->getProperty('pendingBatchMessageCount')->getValue($workflow),
        );
        self::assertNull($reflection->getProperty('pendingTerminalText')->getValue($workflow));
    }

    public function testExplicitlyAddressedOrPrivateUnknownCommandIsNotSilentlyIgnored(): void
    {
        $reflection = new ReflectionClass(SpaceAgentWorkflow::class);
        $workflow   = $reflection->newInstanceWithoutConstructor();
        $method     = new ReflectionMethod(SpaceAgentWorkflow::class, 'shouldSilentlyIgnoreUnboundCommand');
        $reflection->getProperty('input')->setValue($workflow, self::input());

        self::assertFalse($method->invoke(
            $workflow,
            new SpaceCommandInvocation('edit', targetUsername: 'wtf_happend_bot'),
        ));

        $reflection->getProperty('input')->setValue($workflow, new SpaceAgentWorkflowInput(
            spaceId: self::snapshot()->spaceId,
            platform: 'telegram',
            botInstanceId: 'primary-bot',
            externalConversationId: '7001',
            externalThreadId: null,
            chatId: 7001,
            chatType: 'private',
            topicId: null,
            botUsername: 'wtf_happend_bot',
        ));
        self::assertFalse($method->invoke($workflow, new SpaceCommandInvocation('edit')));
    }

    private static function input(): SpaceAgentWorkflowInput
    {
        return new SpaceAgentWorkflowInput(
            spaceId: self::snapshot()->spaceId,
            platform: 'telegram',
            botInstanceId: 'primary-bot',
            externalConversationId: '7001',
            externalThreadId: null,
            chatId: 7001,
            chatType: 'supergroup',
            topicId: null,
            botUsername: 'wtf_happend_bot',
        );
    }

    private static function update(
        int $chatId,
        ?int $messageThreadId = null,
        ?bool $isTopicMessage = null,
        int $updateIdOffset = 0,
    ): Update {
        $update = UpdateFactory::make(
            updateId: 1000 + $chatId + ($messageThreadId ?? 0) + $updateIdOffset,
            message: MessageFactory::make(
                chat: ChatFactory::make(
                    id: $chatId,
                    type: $messageThreadId === null ? 'private' : 'supergroup',
                ),
                messageThreadId: $messageThreadId,
                isTopicMessage: $isTopicMessage,
            ),
        );
        assert($update instanceof Update);

        return $update;
    }

    private static function commandUpdate(string $text, int $updateId): Update
    {
        $update = UpdateFactory::make(
            updateId: $updateId,
            message: MessageFactory::make(
                chat: ChatFactory::make(id: 7001, type: 'supergroup'),
                text: $text,
                entities: [MessageEntityFactory::make(
                    type: 'bot_command',
                    offset: 0,
                    length: strlen($text),
                )],
            ),
        );
        assert($update instanceof Update);

        return $update;
    }

    private static function snapshot(): SpaceRuntimeSnapshot
    {
        return new SpaceRuntimeSnapshot(
            snapshotId: 'snapshot-1',
            spaceId: 'spc_0123456789abcdef0123456789abcdef01234567',
            releaseId: 'release-1',
            releaseDigest: 'sha256:release',
            model: 'test/model',
            systemPrompt: 'Pinned prompt',
            tools: [],
            capsuleArtifactRefs: [['digest' => 'sha256:capsule']],
            capsuleRuntimeImageBuildId: '00000000-0000-4000-8000-000000000000',
        );
    }
}
