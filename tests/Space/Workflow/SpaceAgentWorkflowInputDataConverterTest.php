<?php

declare(strict_types=1);

namespace Tests\Space\Workflow;

use Bot\Space\Runtime\SpaceCommandBinding;
use Bot\Space\Runtime\SpaceRuntimeSnapshot;
use Bot\Space\Runtime\SpaceSkillDefinition;
use Bot\Space\Workflow\QueuedSpaceUpdate;
use Bot\Space\Workflow\SpaceAgentWorkflow;
use Bot\Space\Workflow\SpaceAgentWorkflowInput;
use Bot\Space\Workflow\SpaceAgentWorkflowInputDataConverter;
use Bot\Space\Workflow\SpaceCommandInvocation;
use Bot\Telegram\Update;
use Phenogram\Bindings\Factories\ChatFactory;
use Phenogram\Bindings\Factories\MessageFactory;
use Phenogram\Bindings\Factories\UpdateFactory;
use ReflectionMethod;
use Temporal\DataConverter\Type;
use Tests\TestCase;

final class SpaceAgentWorkflowInputDataConverterTest extends TestCase
{
    public function testTelegramHistoryAnchorSurvivesContinueAsNew(): void
    {
        $input = new SpaceAgentWorkflowInput(
            spaceId: self::snapshot()->spaceId,
            platform: 'telegram',
            botInstanceId: 'primary-bot',
            externalConversationId: '7001',
            externalThreadId: null,
            chatId: 7001,
            chatType: 'supergroup',
            topicId: null,
            botUsername: 'wtf_happend_bot',
            messages: [
                [
                    'role'     => 'user',
                    'content'  => [['type' => 'text', 'text' => 'old turn']],
                    'metadata' => ['telegramMessageTimestamp' => 1_700_000_000],
                ],
                [
                    'role'     => 'user',
                    'content'  => [['type' => 'text', 'text' => 'first current update']],
                    'metadata' => ['telegramMessageTimestamp' => 1_710_000_000],
                ],
                [
                    'role'     => 'user',
                    'content'  => [['type' => 'text', 'text' => 'last current update']],
                    'metadata' => ['telegramMessageTimestamp' => 1_710_000_321],
                ],
            ],
            pipelinePendingSince: 1_799_999_999,
            pendingBatchMessageCount: 2,
            pendingBatchId: 'batch-1',
            pendingActorUserIds: [7],
        );

        $converter = new SpaceAgentWorkflowInputDataConverter();
        $payload   = $converter->toPayload($input);
        self::assertNotNull($payload);
        $continued = $converter->fromPayload(
            $payload,
            Type::create(SpaceAgentWorkflowInput::class),
        );

        $anchor = (new ReflectionMethod(
            SpaceAgentWorkflow::class,
            'pendingBatchHistoryReferenceTimestamp',
        ))->invoke(
            null,
            $continued->messages,
            $continued->pendingBatchMessageCount,
        );

        self::assertSame(1_710_000_321, $anchor);
        self::assertNotSame($continued->pipelinePendingSince, $anchor);
    }

    public function testContinuationRoundTripPreservesPinnedRuntimeAndPendingUpdates(): void
    {
        $update = UpdateFactory::make(
            updateId: 1001,
            message: MessageFactory::make(
                chat: ChatFactory::make(id: 7001, type: 'supergroup'),
                text: 'hello',
                messageThreadId: 42,
                isTopicMessage: true,
            ),
        );
        assert($update instanceof Update);

        $snapshot = self::snapshot();
        $input    = new SpaceAgentWorkflowInput(
            spaceId: $snapshot->spaceId,
            platform: 'telegram',
            botInstanceId: 'primary-bot',
            externalConversationId: '7001',
            externalThreadId: null,
            chatId: 7001,
            chatType: 'supergroup',
            topicId: null,
            botUsername: 'wtf_happend_bot',
            messages: [[
                'role'    => 'user',
                'content' => [['type' => 'text', 'text' => 'hello']],
            ]],
            pendingUpdates: [new QueuedSpaceUpdate($update, true, 'ingestion-1')],
            pendingBatchMessageCount: 1,
            pendingBatchId: 'batch-1',
            pendingTopicId: 42,
            pendingCommandInvocation: new SpaceCommandInvocation(
                'dimannews',
                'сделай про утро',
            ),
            pendingActorUserIds: [7001],
            pendingRuntimeSnapshot: $snapshot,
        );

        $converter = new SpaceAgentWorkflowInputDataConverter();
        $payload   = $converter->toPayload($input);
        self::assertNotNull($payload);

        $decoded = $converter->fromPayload(
            $payload,
            Type::create(SpaceAgentWorkflowInput::class),
        );

        self::assertSame('batch-1', $decoded->pendingBatchId);
        self::assertSame('wtf_happend_bot', $decoded->botUsername);
        self::assertSame(42, $decoded->pendingTopicId);
        self::assertSame('dimannews', $decoded->pendingCommandInvocation?->name);
        self::assertSame('сделай про утро', $decoded->pendingCommandInvocation?->argumentText);
        self::assertSame('release-7', $decoded->pendingRuntimeSnapshot?->releaseId);
        self::assertSame('totalizator', $decoded->pendingRuntimeSnapshot?->skills[0]->name);
        self::assertSame('Full skill body.', $decoded->pendingRuntimeSnapshot?->skills[0]->body);
        self::assertSame('dimannews', $decoded->pendingRuntimeSnapshot?->commands[0]->name);
        self::assertSame(
            'Full immutable specification.',
            $decoded->pendingRuntimeSnapshot?->commands[0]->instructions,
        );
        self::assertSame('sha256:capsule', $decoded->pendingRuntimeSnapshot?->capsuleArtifactRefs[0]['digest']);
        self::assertSame(
            '00000000-0000-4000-8000-000000000000',
            $decoded->pendingRuntimeSnapshot?->capsuleRuntimeImageBuildId,
        );
        self::assertCount(1, $decoded->pendingUpdates);
        self::assertSame(1001, $decoded->pendingUpdates[0]->update->updateId);
        self::assertSame($converter->encodedBytes($input), strlen($payload->getData()));
    }

    private static function snapshot(): SpaceRuntimeSnapshot
    {
        return new SpaceRuntimeSnapshot(
            snapshotId: 'snapshot-7',
            spaceId: 'spc_0123456789abcdef0123456789abcdef01234567',
            releaseId: 'release-7',
            releaseDigest: 'sha256:release',
            model: 'test/model',
            systemPrompt: 'Pinned prompt',
            tools: [['name' => 'stay_silent']],
            skills: [new SpaceSkillDefinition(
                name: 'totalizator',
                description: 'Use for an exact lottery trigger.',
                body: 'Full skill body.',
            )],
            commands: [new SpaceCommandBinding(
                name: 'dimannews',
                description: 'Generate Diman News.',
                instructions: 'Full immutable specification.',
                parametersSchema: [
                    'type'                 => 'object',
                    'properties'           => [],
                    'additionalProperties' => false,
                ],
            )],
            capsuleArtifactRefs: [['name' => 'calculator', 'digest' => 'sha256:capsule']],
            capsuleRuntimeImageBuildId: '00000000-0000-4000-8000-000000000000',
            memoryRevision: 'memory-3',
            capabilityPolicyRevision: 'policy-2',
        );
    }
}
