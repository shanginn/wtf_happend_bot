<?php

declare(strict_types=1);

namespace Tests\Space\Operations;

use Bot\Space\Operations\LegacyCommandMigration;
use Cycle\Database\DatabaseInterface;
use Cycle\Database\StatementInterface;
use Mockery;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

final class LegacyCommandMigrationTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testPreviewClassifiesOnlyExplicitDirectReplyCommands(): void
    {
        $database = Mockery::mock(DatabaseInterface::class);
        $database->shouldReceive('query')->once()->andReturn(self::rows([[
            'space_id'                 => 'spc_test',
            'active_release_id'        => 'release-old',
            'release_generation'       => 1,
            'external_conversation_id' => '-1001',
            'manifest_json'            => '{}',
            'enabled_tool_count'       => '8',
        ]]));
        $database->shouldReceive('query')->once()->andReturn(self::rows(array_map(
            static fn (string $name): array => ['name' => $name],
            [
                'dimannews',
                'iddqd_dildak',
                'mezhdustroch',
                'ochkometer',
                'sharada-mutation',
                'update_bot_commands',
                'whois',
                'zaemny_penis',
            ],
        )));

        self::assertSame([
            'mode'               => 'preview',
            'targetSpaces'       => 1,
            'enabledLegacyTools' => 8,
            'migratableCommands' => 6,
            'retiredCommands'    => 2,
            'alreadyMigrated'    => 0,
        ], (new LegacyCommandMigration($database, 'primary'))->preview());
    }

    public function testPreviewFailsClosedForUnclassifiedEnabledTool(): void
    {
        $database = Mockery::mock(DatabaseInterface::class);
        $database->shouldReceive('query')->once()->andReturn(self::rows([[
            'space_id'                 => 'spc_test',
            'active_release_id'        => 'release-old',
            'release_generation'       => 1,
            'external_conversation_id' => '-1001',
            'manifest_json'            => '{}',
            'enabled_tool_count'       => '1',
        ]]));
        $database->shouldReceive('query')->once()->andReturn(self::rows([
            ['name' => 'new_unreviewed_tool'],
        ]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no explicit migration policy');
        (new LegacyCommandMigration($database, 'primary'))->preview();
    }

    public function testReleaseContentEmbedsSixCommandsInlineAndKeepsOnlyOrdinarySkills(): void
    {
        $content = self::releaseContent(
            self::spaceSkillRows(),
            self::legacyToolRows([
                'dimannews',
                'iddqd_dildak',
                'mezhdustroch',
                'ochkometer',
                'sharada-mutation',
                'update_bot_commands',
                'whois',
                'zaemny_penis',
            ]),
        );

        self::assertSame([
            'batman-mode',
            'caveman-reply-to-diman',
            'chunked-responses',
            'cringe-intervention',
            'ignore-foreign-slash-commands',
            'max-toxicity-for-diman',
            'sponsor-detector',
            'sugar-diary',
            'taken_spelling',
            'token-waste-warning',
            'zadneprohod-alert',
        ], array_keys($content['skills']));
        self::assertArrayNotHasKey('command-sync-reminder', $content['skills']);
        self::assertArrayNotHasKey('babka-speak', $content['skills']);
        self::assertSame([
            'dimannews',
            'iddqd_dildak',
            'mezhdustroch',
            'ochkometer',
            'whois',
            'zaemny_penis',
        ], array_keys($content['commands']));

        foreach ($content['commands'] as $name => $command) {
            self::assertSame(
                ['command', 'description', 'instructions', 'parametersSchema'],
                array_keys($command),
            );
            self::assertSame($name, $command['command']);
            self::assertSame("Full immutable instructions for {$name}.", $command['instructions']);
            self::assertArrayNotHasKey('skillName', $command);
            self::assertArrayNotHasKey($name, $content['skills']);
        }

        self::assertCount(6, $content['legacyDigestPayload']);
        self::assertSame(
            array_keys($content['commands']),
            array_column($content['legacyDigestPayload'], 'name'),
        );
        self::assertNotContains('sharada-mutation', array_column($content['legacyDigestPayload'], 'name'));
        self::assertNotContains('update_bot_commands', array_column($content['legacyDigestPayload'], 'name'));
    }

    public function testReleaseContentFailsClosedUnlessAllSixReviewedCommandsArePresent(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('exact six-command migration inventory');

        self::releaseContent(
            self::spaceSkillRows(),
            self::legacyToolRows([
                'dimannews',
                'iddqd_dildak',
                'mezhdustroch',
                'ochkometer',
                'sharada-mutation',
                'update_bot_commands',
                'zaemny_penis',
            ]),
        );
    }

    public function testReleaseContentCanonicalizesAnEmptyLegacyPropertiesObject(): void
    {
        $legacyTools = self::legacyToolRows([
            'dimannews',
            'iddqd_dildak',
            'mezhdustroch',
            'ochkometer',
            'whois',
            'zaemny_penis',
        ]);
        $legacyTools[0]['parameters_schema'] = json_encode([
            'type'                 => 'object',
            'properties'           => new \stdClass(),
            'additionalProperties' => false,
        ], \JSON_THROW_ON_ERROR);

        $content = self::releaseContent(self::spaceSkillRows(), $legacyTools);

        self::assertSame([], $content['commands']['dimannews']['parametersSchema']['properties']);
        self::assertSame(
            [],
            $content['legacyDigestPayload'][0]['parametersSchema']['properties'],
        );
    }

    public function testDataCutoverRequiresTheExactPreparedOrActiveHostRelease(): void
    {
        $assertion = new ReflectionMethod(LegacyCommandMigration::class, 'hostReleaseMigrationPhase');

        foreach ([
            [
                'desired_release_id' => 'local',
                'active_release_id'  => str_repeat('a', 64),
                'phase'              => 'prepared',
            ],
            [
                'desired_release_id' => 'local',
                'active_release_id'  => 'local',
                'phase'              => 'active',
            ],
        ] as $allowed) {
            $database = Mockery::mock(DatabaseInterface::class);
            $database->shouldReceive('query')->once()->andReturn(self::row($allowed));
            self::assertSame(
                $allowed['phase'],
                $assertion->invoke(null, $database, 'local'),
            );
        }

        foreach (['authorized', 'ingress-retired'] as $forwardOnlyPhase) {
            $database = Mockery::mock(DatabaseInterface::class);
            $database->shouldReceive('query')->once()->andReturn(self::row([
                'desired_release_id' => 'local',
                'active_release_id'  => str_repeat('a', 64),
                'phase'              => $forwardOnlyPhase,
            ]));
            self::assertSame(
                $forwardOnlyPhase,
                $assertion->invoke(null, $database, 'local'),
            );
        }

        foreach ([
            [
                'desired_release_id' => str_repeat('b', 64),
                'active_release_id'  => 'local',
                'phase'              => 'prepared',
            ],
            [
                'desired_release_id' => 'local',
                'active_release_id'  => null,
                'phase'              => 'aborted',
            ],
            [
                'desired_release_id' => 'local',
                'active_release_id'  => str_repeat('a', 64),
                'phase'              => 'active',
            ],
        ] as $rejected) {
            $database = Mockery::mock(DatabaseInterface::class);
            $database->shouldReceive('query')->once()->andReturn(self::row($rejected));
            try {
                $assertion->invoke(null, $database, 'local');
                self::fail('An unrelated or irreversible host release state was accepted.');
            } catch (RuntimeException $error) {
                self::assertStringContainsString('host release', $error->getMessage());
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $skills
     * @param list<array<string, mixed>> $tools
     *
     * @return array<string, mixed>
     */
    private static function releaseContent(array $skills, array $tools): array
    {
        return (new ReflectionMethod(LegacyCommandMigration::class, 'releaseContent'))->invoke(
            null,
            $skills,
            $tools,
        );
    }

    /** @return list<array<string, mixed>> */
    private static function spaceSkillRows(): array
    {
        $enabled = [
            'batman-mode',
            'caveman-reply-to-diman',
            'chunked-responses',
            'command-sync-reminder',
            'cringe-intervention',
            'ignore-foreign-slash-commands',
            'max-toxicity-for-diman',
            'sponsor-detector',
            'sugar-diary',
            'taken_spelling',
            'token-waste-warning',
            'zadneprohod-alert',
        ];
        $rows = array_map(
            static fn (string $name): array => [
                'name'          => $name,
                'description'   => "Description for {$name}.",
                'body'          => "Ordinary behavior for {$name}.",
                'manifest_json' => '{}',
                'source_digest' => null,
                'enabled'       => true,
            ],
            $enabled,
        );
        $rows[] = [
            'name'          => 'babka-speak',
            'description'   => 'Disabled skill.',
            'body'          => 'Do not copy.',
            'manifest_json' => '{}',
            'source_digest' => null,
            'enabled'       => false,
        ];

        return $rows;
    }

    /**
     * @param list<string> $names
     *
     * @return list<array<string, mixed>>
     */
    private static function legacyToolRows(array $names): array
    {
        return array_map(
            static fn (string $name): array => [
                'id'                => 1,
                'chat_id'           => -1001,
                'name'              => $name,
                'description'       => "Description for {$name}.",
                'parameters_schema' => json_encode([
                    'type'       => 'object',
                    'properties' => [
                        'topic' => ['type' => 'string'],
                    ],
                    'additionalProperties' => false,
                ], \JSON_THROW_ON_ERROR),
                'instructions' => "Full immutable instructions for {$name}.",
                'enabled'      => true,
                'created_at'   => 1,
                'updated_at'   => 1,
            ],
            $names,
        );
    }

    /** @param list<array<string, mixed>> $rows */
    private static function rows(array $rows): StatementInterface
    {
        $statement = Mockery::mock(StatementInterface::class);
        $statement->shouldReceive('fetchAll')->once()->andReturn($rows);

        return $statement;
    }

    /** @param array<string, mixed> $row */
    private static function row(array $row): StatementInterface
    {
        $statement = Mockery::mock(StatementInterface::class);
        $statement->shouldReceive('fetch')->once()->andReturn($row);

        return $statement;
    }
}
