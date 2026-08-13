<?php

declare(strict_types=1);

namespace Tests\Space\Command;

use Bot\Space\Command\SpaceCommandActivity;
use Bot\Space\Command\SpaceCommandExecutionInput;
use Bot\Space\Runtime\SpaceCommandBinding;
use Mockery;
use PiPHP\Temporal\Contract\ModelCompletionGatewayInterface;
use PiPHP\Temporal\DTO\ModelActivityInput;
use PiPHP\Temporal\DTO\ModelActivityResult;
use Temporal\DataConverter\DataConverter;
use Tests\TestCase;

final class SpaceCommandActivityTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testExecutesOnlyPinnedSpecificationWithoutTools(): void
    {
        $models = Mockery::mock(ModelCompletionGatewayInterface::class);
        $models
            ->shouldReceive('complete')
            ->once()
            ->withArgs(static function (ModelActivityInput $input): bool {
                self::assertSame([], $input->tools);
                self::assertSame('command-key', $input->idempotencyKey);
                self::assertSame('dimannews', $input->metadata['spaceCommand']);
                self::assertStringContainsString(
                    'Use the complete Diman News format.',
                    $input->messages[0]['content'][0]['text'],
                );
                self::assertStringNotContainsString(
                    'generic prompt must not survive',
                    json_encode($input->messages, \JSON_THROW_ON_ERROR),
                );
                self::assertStringContainsString(
                    'актуальные события',
                    $input->messages[2]['content'][0]['text'],
                );

                return true;
            })
            ->andReturn(self::modelResult('Готовая новость'));

        $text = (new SpaceCommandActivity($models))->execute(self::input());

        self::assertSame('Готовая новость', $text);
    }

    public function testBoundsTelegramReplyTo4096Characters(): void
    {
        $models = Mockery::mock(ModelCompletionGatewayInterface::class);
        $models->shouldReceive('complete')->once()->andReturn(self::modelResult(str_repeat('я', 5000)));

        $text = (new SpaceCommandActivity($models))->execute(self::input());

        self::assertSame(4096, mb_strlen($text));
        self::assertStringEndsWith('… [обрезано]', $text);
    }

    public function testExecutionInputRoundTripsThroughTemporalJsonConverter(): void
    {
        $converter = DataConverter::createDefault();
        $decoded   = $converter->fromPayload(
            $converter->toPayload(self::input()),
            SpaceCommandExecutionInput::class,
        );

        self::assertInstanceOf(SpaceCommandExecutionInput::class, $decoded);
        self::assertInstanceOf(SpaceCommandBinding::class, $decoded->binding);
        self::assertSame('dimannews', $decoded->binding->name);
        self::assertSame('актуальные события', $decoded->argumentText);
    }

    private static function input(): SpaceCommandExecutionInput
    {
        return new SpaceCommandExecutionInput(
            model: 'test/model',
            binding: new SpaceCommandBinding(
                name: 'dimannews',
                description: 'Generate Diman News.',
                instructions: 'Use the complete Diman News format.',
                parametersSchema: [
                    'type'                 => 'object',
                    'properties'           => [],
                    'additionalProperties' => false,
                ],
            ),
            argumentText: 'актуальные события',
            messages: [
                ['role' => 'system', 'content' => [[
                    'type' => 'text',
                    'text' => 'generic prompt must not survive',
                ]]],
                ['role' => 'user', 'content' => [[
                    'type' => 'text',
                    'text' => 'Telegram batch containing /dimannews.',
                ]]],
            ],
            metadata: ['spaceId' => 'spc_test'],
            idempotencyKey: 'command-key',
        );
    }

    private static function modelResult(string $text): ModelActivityResult
    {
        return new ModelActivityResult(
            assistantMessage: [
                'role'    => 'assistant',
                'content' => [['type' => 'text', 'text' => $text]],
            ],
            toolCalls: [],
            stopReason: 'stop',
        );
    }
}
