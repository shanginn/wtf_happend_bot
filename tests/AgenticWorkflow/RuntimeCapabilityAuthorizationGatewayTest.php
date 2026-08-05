<?php

declare(strict_types=1);

namespace Tests\AgenticWorkflow;

use Bot\AgenticWorkflow\RuntimeCapabilityAuthorizationGateway;
use Bot\Telegram\TelegramChatAuthorizationPolicy;
use Phenogram\Bindings\ApiInterface;
use Phenogram\Bindings\Factories\ChatMemberAdministratorFactory;
use Phenogram\Bindings\Factories\UserFactory;
use PiPHP\Temporal\Contract\ToolExecutionGatewayInterface;
use PiPHP\Temporal\DTO\ToolActivityInput;
use PiPHP\Temporal\DTO\ToolActivityResult;
use RuntimeException;
use Tests\TestCase;

final class RuntimeCapabilityAuthorizationGatewayTest extends TestCase
{
    public function testAuthorizedGroupMutationDelegates(): void
    {
        $api = $this->createMock(ApiInterface::class);
        $api
            ->expects($this->once())
            ->method('getChatMember')
            ->with(-10042, 11)
            ->willReturn(ChatMemberAdministratorFactory::make(
                status: 'administrator',
                user: UserFactory::make(id: 11),
                isAnonymous: false,
            ));
        $inner = new RuntimeAuthorizationRecordingGateway();
        $gateway = new RuntimeCapabilityAuthorizationGateway(
            $inner,
            new TelegramChatAuthorizationPolicy($api),
        );

        $result = $gateway->execute($this->input(metadata: [
            'chatId' => -10042,
            'chatType' => 'supergroup',
            'actorUserIds' => [11],
            'actorIdentityComplete' => true,
        ]));

        self::assertSame(1, $inner->calls);
        self::assertFalse($result->isError);
    }

    public function testIncompleteActorIdentityFailsClosedBeforeLookupOrDelegation(): void
    {
        $api = $this->createMock(ApiInterface::class);
        $api->expects($this->never())->method('getChatMember');
        $inner = new RuntimeAuthorizationRecordingGateway();
        $gateway = new RuntimeCapabilityAuthorizationGateway(
            $inner,
            new TelegramChatAuthorizationPolicy($api),
        );

        foreach ([
            'upsert_runtime_skill',
            'upsert_runtime_tool',
            'set_runtime_capability_status',
        ] as $toolName) {
            $result = $gateway->execute($this->input(name: $toolName, metadata: [
                'chatId' => -10042,
                'chatType' => 'supergroup',
                'actorUserIds' => [11],
                'actorIdentityComplete' => false,
            ]));

            self::assertTrue($result->isError);
            self::assertTrue($result->metadata['authorizationDenied']);
        }

        self::assertSame(0, $inner->calls);
    }

    public function testTelegramFailureOccursBeforeInnerIdempotencyGatewayCanClaim(): void
    {
        $api = $this->createMock(ApiInterface::class);
        $api
            ->expects($this->once())
            ->method('getChatMember')
            ->willThrowException(new RuntimeException('telegram unavailable'));
        $inner = new RuntimeAuthorizationRecordingGateway();
        $gateway = new RuntimeCapabilityAuthorizationGateway(
            $inner,
            new TelegramChatAuthorizationPolicy($api),
        );

        try {
            $gateway->execute($this->input(metadata: [
                'chatId' => -10042,
                'chatType' => 'supergroup',
                'actorUserIds' => [11],
                'actorIdentityComplete' => true,
            ]));
            self::fail('Telegram lookup failure was expected.');
        } catch (RuntimeException $error) {
            self::assertSame('telegram unavailable', $error->getMessage());
        }

        self::assertSame(0, $inner->calls);
    }

    public function testUnprotectedToolBypassesAuthorizationMetadata(): void
    {
        $api = $this->createMock(ApiInterface::class);
        $api->expects($this->never())->method('getChatMember');
        $inner = new RuntimeAuthorizationRecordingGateway();
        $gateway = new RuntimeCapabilityAuthorizationGateway(
            $inner,
            new TelegramChatAuthorizationPolicy($api),
        );

        $gateway->execute($this->input(name: 'get_current_time', metadata: []));

        self::assertSame(1, $inner->calls);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function input(
        string $name = 'upsert_runtime_skill',
        array $metadata = [],
    ): ToolActivityInput {
        return new ToolActivityInput(
            callId: 'call-1',
            name: $name,
            arguments: ['name' => 'new_skill'],
            idempotencyKey: 'workflow/run/tool/call-1',
            metadata: $metadata,
        );
    }
}

final class RuntimeAuthorizationRecordingGateway implements ToolExecutionGatewayInterface
{
    public int $calls = 0;

    public function execute(ToolActivityInput $input): ToolActivityResult
    {
        ++$this->calls;

        return new ToolActivityResult(
            callId: $input->callId,
            name: $input->name,
            content: [['type' => 'text', 'text' => 'executed']],
        );
    }
}
