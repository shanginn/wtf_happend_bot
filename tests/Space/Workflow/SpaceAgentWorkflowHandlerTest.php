<?php

declare(strict_types=1);

namespace Tests\Space\Workflow;

use Bot\Space\Runtime\SpaceIdentity;
use Bot\Space\Runtime\SpaceIdentityResolverInterface;
use Bot\Space\Workflow\SpaceAgentWorkflow;
use Bot\Space\Workflow\SpaceAgentWorkflowHandler;
use Bot\Space\Workflow\SpaceAgentWorkflowInput;
use Bot\Telegram\Update;
use Carbon\CarbonInterval;
use Mockery;
use Phenogram\Bindings\Factories\ChatFactory;
use Phenogram\Bindings\Factories\MessageFactory;
use Phenogram\Bindings\Factories\UpdateFactory;
use stdClass;
use Temporal\Client\WorkflowClientInterface;
use Temporal\Client\WorkflowOptions;
use Temporal\Common\IdReusePolicy;
use Tests\TestCase;

final class SpaceAgentWorkflowHandlerTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testUpdateLazilySignalsStableSpaceWorkflow(): void
    {
        $update = UpdateFactory::make(
            updateId: 1001,
            message: MessageFactory::make(
                messageId: 2002,
                chat: ChatFactory::make(id: -100123456, type: 'supergroup'),
                text: 'hello',
                messageThreadId: 42,
                isTopicMessage: true,
            ),
        );
        assert($update instanceof Update);

        $space = new SpaceIdentity(
            spaceId: 'spc_0123456789abcdef0123456789abcdef01234567',
            platform: 'telegram',
            botInstanceId: 'primary-bot',
            externalConversationId: '-100123456',
            externalThreadId: null,
            chatId: -100123456,
            chatType: 'supergroup',
            topicId: null,
        );
        $resolver = Mockery::mock(SpaceIdentityResolverInterface::class);
        $resolver->shouldReceive('resolve')->once()->with($update)->andReturn($space);

        $workflowStub = new stdClass();
        $client       = Mockery::mock(WorkflowClientInterface::class);
        $client
            ->shouldReceive('newWorkflowStub')
            ->once()
            ->withArgs(static function (string $class, WorkflowOptions $options): bool {
                return $class === SpaceAgentWorkflow::class
                    && $options->workflowId
                        === 'space-agent/spc_0123456789abcdef0123456789abcdef01234567/v1/release/local'
                    && (int) CarbonInterval::instance($options->workflowTaskTimeout)->totalSeconds === 60
                    && $options->workflowIdReusePolicy === IdReusePolicy::AllowDuplicate->value;
            })
            ->andReturn($workflowStub);
        $client
            ->shouldReceive('signalWithStart')
            ->once()
            ->withArgs(static function (
                object $workflow,
                string $signal,
                array $signalArgs,
                array $startArgs,
            ) use ($workflowStub, $update, $space): bool {
                return $workflow === $workflowStub
                    && $signal === 'addUpdate'
                    && $signalArgs === [$update]
                    && count($startArgs) === 1
                    && $startArgs[0] instanceof SpaceAgentWorkflowInput
                    && $startArgs[0]->spaceId === $space->spaceId
                    && $startArgs[0]->topicId === null;
            });

        (new SpaceAgentWorkflowHandler($client, $resolver))->handleUpdate($update);
        $this->addToAssertionCount(1);
    }
}
