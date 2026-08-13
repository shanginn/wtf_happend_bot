<?php

declare(strict_types=1);

namespace Tests\Space\Publication;

use Bot\Space\Publication\SpaceCapabilityPublicationInput;
use Bot\Space\Publication\SpaceCapabilityPublicationRejected;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class SpaceCapabilityPublicationInputTest extends TestCase
{
    private const string SPACE_ID = 'spc_0123456789abcdef0123456789abcdef01234567';

    /** @return iterable<string, array{string, string, array<string, mixed>}> */
    public static function privateContent(): iterable
    {
        $emptySchema = [
            'type'                 => 'object',
            'properties'           => [],
            'additionalProperties' => false,
        ];

        yield 'email in description' => [
            'Напиши результат на admin@example.com.',
            'Сформируй результат.',
            $emptySchema,
        ];
        yield 'credential nested in command schema' => [
            'Выполняет действие.',
            'Выполни действие по явному запросу.',
            [
                'type'       => 'object',
                'properties' => [
                    'token' => [
                        'type'        => 'string',
                        'description' => 'Use password: hunter22 for this field.',
                    ],
                ],
            ],
        ];
    }

    public function testAcceptsTheExactAuthorityContractForSkillsAndCommands(): void
    {
        $skill = new SpaceCapabilityPublicationInput(
            spaceId: self::SPACE_ID,
            runtimeSnapshotId: 'snp_test',
            terminalScopeId: 'terminal:42',
            invocationKey: 'tool:1',
            kind: SpaceCapabilityPublicationInput::KIND_SKILL,
            name: 'concise-replies',
            description: 'Отвечает кратко, когда вопрос простой.',
            instructions: 'Сразу дай короткий полезный ответ без вступления.',
            authorizationProvenance: self::provenance(),
        );
        $command = new SpaceCapabilityPublicationInput(
            spaceId: self::SPACE_ID,
            runtimeSnapshotId: 'snp_test',
            terminalScopeId: 'terminal:42',
            invocationKey: 'tool:2',
            kind: SpaceCapabilityPublicationInput::KIND_COMMAND,
            name: 'punish',
            description: 'Наказывает бота за последнее неудачное сообщение.',
            instructions: 'Опиши одновременный разряд, удар и ледяную воду как шуточное наказание.',
            authorizationProvenance: self::provenance(),
        );

        self::assertSame('concise-replies', $skill->name);
        self::assertSame('punish', $command->name);
        self::assertSame(self::provenance(), $command->authorizationProvenance);
    }

    #[DataProvider('privateContent')]
    public function testRejectsPrivateContentBeforeItCanEnterEveryFuturePrompt(
        string $description,
        string $instructions,
        array $schema,
    ): void {
        $this->expectException(SpaceCapabilityPublicationRejected::class);
        $this->expectExceptionMessage('private or sensitive');

        new SpaceCapabilityPublicationInput(
            spaceId: self::SPACE_ID,
            runtimeSnapshotId: 'snp_test',
            terminalScopeId: 'terminal:42',
            invocationKey: 'tool:private',
            kind: SpaceCapabilityPublicationInput::KIND_COMMAND,
            name: 'punish',
            description: $description,
            instructions: $instructions,
            authorizationProvenance: self::provenance(),
            parametersSchema: $schema,
        );
    }

    public function testSkillCannotSmuggleAnUnusedCommandSchema(): void
    {
        $this->expectException(SpaceCapabilityPublicationRejected::class);
        $this->expectExceptionMessage('cannot declare command parameters');

        new SpaceCapabilityPublicationInput(
            spaceId: self::SPACE_ID,
            runtimeSnapshotId: 'snp_test',
            terminalScopeId: 'terminal:42',
            invocationKey: 'tool:schema',
            kind: SpaceCapabilityPublicationInput::KIND_SKILL,
            name: 'concise',
            description: 'Отвечает кратко.',
            instructions: 'Дай короткий ответ.',
            authorizationProvenance: self::provenance(),
            parametersSchema: ['type' => 'object'],
        );
    }

    public function testAuthorityMustBelongToTheExactSpaceAndHaveNoExtraFields(): void
    {
        $provenance             = self::provenance();
        $provenance['spaceId']  = 'spc_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
        $provenance['forgedBy'] = 'model';

        $this->expectException(SpaceCapabilityPublicationRejected::class);
        $this->expectExceptionMessage('unsupported or missing fields');

        new SpaceCapabilityPublicationInput(
            spaceId: self::SPACE_ID,
            runtimeSnapshotId: 'snp_test',
            terminalScopeId: 'terminal:42',
            invocationKey: 'tool:authority',
            kind: SpaceCapabilityPublicationInput::KIND_SKILL,
            name: 'concise',
            description: 'Отвечает кратко.',
            instructions: 'Дай короткий ответ.',
            authorizationProvenance: $provenance,
        );
    }

    /** @return array<string, mixed> */
    private static function provenance(string $batchId = 'batch_test'): array
    {
        return [
            'spaceId'             => self::SPACE_ID,
            'batchId'             => $batchId,
            'authorization'       => 'telegram-admin',
            'actorParticipantKey' => 'telegram_user:42',
            'requestUpdateId'     => 123,
            'requestSha256'       => 'sha256:' . str_repeat('a', 64),
            'quoteSha256'         => 'sha256:' . str_repeat('b', 64),
        ];
    }
}
