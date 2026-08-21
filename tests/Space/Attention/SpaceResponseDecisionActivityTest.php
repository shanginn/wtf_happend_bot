<?php

declare(strict_types=1);

namespace Tests\Space\Attention;

use Bot\Space\Attention\SpaceResponseDecision;
use Bot\Space\Attention\SpaceResponseDecisionActivity;
use Bot\Space\Attention\SpaceResponseDecisionActivityInterface;
use Bot\Space\Attention\SpaceResponseDecisionInput;
use Bot\Space\Runtime\SpaceSkillDefinition;
use Mockery;
use PiPHP\Temporal\Contract\ModelCompletionGatewayInterface;
use PiPHP\Temporal\DTO\ModelActivityInput;
use PiPHP\Temporal\DTO\ModelActivityResult;
use Temporal\DataConverter\DataConverter;
use Spiral\Attributes\AttributeReader;
use Temporal\Internal\Declaration\Reader\ActivityReader;
use Tests\TestCase;

final class SpaceResponseDecisionActivityTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testSelectsOnlyPinnedSkillsWithoutExposingTheirBodies(): void
    {
        $models = Mockery::mock(ModelCompletionGatewayInterface::class);
        $models->shouldReceive('complete')->once()->withArgs(
            static function (ModelActivityInput $input): bool {
                $encoded = json_encode($input->messages, \JSON_THROW_ON_ERROR);
                self::assertStringContainsString('lottery intervention', $encoded);
                self::assertStringNotContainsString('SECRET FULL BODY', $encoded);
                self::assertSame('route_space_batch', $input->tools[0]['name']);

                return true;
            },
        )->andReturn(self::modelResult('skill', ['totalizator']));

        $decision = (new SpaceResponseDecisionActivity($models))->decide(self::input());

        self::assertSame(SpaceResponseDecision::MODE_SKILL, $decision->mode);
        self::assertSame(['totalizator'], $decision->selectedSkillNames);
    }

    public function testHostDirectSignalOverridesAnInvalidSilentRoute(): void
    {
        $models = Mockery::mock(ModelCompletionGatewayInterface::class);
        $models->shouldReceive('complete')->once()->andReturn(self::modelResult('silent', []));

        $decision = (new SpaceResponseDecisionActivity($models))->decide(self::input(
            directRequired: true,
        ));

        self::assertSame(SpaceResponseDecision::MODE_DIRECT, $decision->mode);
        self::assertTrue($decision->runsAgent());
    }

    public function testCooldownSuppressesUnsolicitedRouterReply(): void
    {
        $models = Mockery::mock(ModelCompletionGatewayInterface::class);
        $models->shouldReceive('complete')->once()->andReturn(self::modelResult('spontaneous', []));

        $decision = (new SpaceResponseDecisionActivity($models))->decide(self::input(
            spontaneousAllowed: false,
        ));

        self::assertSame(SpaceResponseDecision::MODE_SILENT, $decision->mode);
        self::assertFalse($decision->runsAgent());
    }

    public function testUnknownSkillFailsClosed(): void
    {
        $models = Mockery::mock(ModelCompletionGatewayInterface::class);
        $models->shouldReceive('complete')->once()->andReturn(self::modelResult('skill', ['invented']));

        $decision = (new SpaceResponseDecisionActivity($models))->decide(self::input());

        self::assertSame(SpaceResponseDecision::MODE_SILENT, $decision->mode);
    }

    public function testInputRoundTripsThroughTemporalConverter(): void
    {
        $converter = DataConverter::createDefault();
        $decoded   = $converter->fromPayload(
            $converter->toPayload(self::input()),
            SpaceResponseDecisionInput::class,
        );

        self::assertInstanceOf(SpaceResponseDecisionInput::class, $decoded);
        self::assertInstanceOf(SpaceSkillDefinition::class, $decoded->skills[0]);
        self::assertSame('totalizator', $decoded->skills[0]->name);
    }

    public function testActivityKeepsAStableReleaseQualifiedName(): void
    {
        $activities = (new ActivityReader(new AttributeReader()))->fromClass(
            SpaceResponseDecisionActivity::class,
        );

        self::assertCount(1, $activities);
        self::assertSame(SpaceResponseDecisionActivityInterface::DECIDE, $activities[0]->getID());
    }

    private static function input(
        bool $directRequired = false,
        bool $spontaneousAllowed = true,
    ): SpaceResponseDecisionInput {
        return new SpaceResponseDecisionInput(
            model: 'test/model',
            messages: [[
                'role'    => 'user',
                'content' => [['type' => 'text', 'text' => 'ordinary Telegram chatter']],
            ]],
            skills: [new SpaceSkillDefinition(
                name: 'totalizator',
                description: 'lottery intervention',
                body: 'SECRET FULL BODY',
            )],
            directRequired: $directRequired,
            spontaneousAllowed: $spontaneousAllowed,
            idempotencyKey: 'attention-key',
        );
    }

    /** @param list<string> $skills */
    private static function modelResult(string $mode, array $skills): ModelActivityResult
    {
        return new ModelActivityResult(
            assistantMessage: [
                'role'    => 'assistant',
                'content' => [[
                    'type'      => 'toolCall',
                    'id'        => 'route-call',
                    'name'      => 'route_space_batch',
                    'arguments' => [
                        'mode'            => $mode,
                        'selected_skills' => $skills,
                    ],
                ]],
            ],
            toolCalls: [[
                'id'        => 'route-call',
                'name'      => 'route_space_batch',
                'arguments' => [
                    'mode'            => $mode,
                    'selected_skills' => $skills,
                ],
            ]],
            stopReason: 'tool_use',
        );
    }
}
