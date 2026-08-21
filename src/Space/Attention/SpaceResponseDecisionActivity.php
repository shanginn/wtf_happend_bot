<?php

declare(strict_types=1);

namespace Bot\Space\Attention;

use InvalidArgumentException;
use PiPHP\AI\Tool\Tool;
use PiPHP\Temporal\Contract\ModelCompletionGatewayInterface;
use PiPHP\Temporal\DTO\AgentMessage;
use PiPHP\Temporal\DTO\ModelActivityInput;
use PiPHP\Temporal\Serialization\PiPayloadCodec;

/**
 * A short host-owned routing pass. It never answers the chat and never sees
 * full skill bodies; it only chooses whether the main agent should run and
 * which zero-to-two pinned skills are relevant to this exact batch.
 */
final readonly class SpaceResponseDecisionActivity implements SpaceResponseDecisionActivityInterface
{
    private const string TOOL_NAME = 'route_space_batch';

    public function __construct(private ModelCompletionGatewayInterface $models) {}

    public function decide(SpaceResponseDecisionInput $input): SpaceResponseDecision
    {
        $skillRegistry = array_map(
            static fn ($skill): array => [
                'name'        => $skill->name,
                'description' => $skill->description,
            ],
            $input->skills,
        );
        $routingContext = json_encode([
            'direct_reply_required' => $input->directRequired,
            'spontaneous_allowed'   => $input->spontaneousAllowed,
            'enabled_skills'        => $skillRegistry,
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        $messages = array_values(array_filter(
            $input->messages,
            static fn (array $message): bool => ($message['role'] ?? null) !== 'system',
        ));

        $result = $this->models->complete(new ModelActivityInput(
            model: $input->model,
            messages: [
                AgentMessage::text('system', self::prompt())->toArray(),
                ...$messages,
                AgentMessage::text('user', "Host routing constraints:\n{$routingContext}")->toArray(),
            ],
            tools: [(new PiPayloadCodec())->toolToWire(self::tool())],
            metadata: ['spaceAttentionDecision' => true],
            idempotencyKey: $input->idempotencyKey,
        ));

        return self::validatedDecision($input, $result->toolCalls);
    }

    private static function prompt(): string
    {
        return <<<'PROMPT'
            You are a Telegram group attention router, not the chat bot. Do not answer
            the conversation. Call route_space_batch exactly once.

            Preserve natural, occasional participation without reacting to everything:
            - direct: the bot is explicitly addressed, asked a question, replied to, or
              a response is required by the host flag;
            - skill: one or two enabled skill descriptions unambiguously match the
              current batch and executing them is useful now;
            - spontaneous: an unsolicited response would be unusually timely, funny,
              corrective, or useful rather than merely possible;
            - silent: normal participant chatter, weak relevance, repetition, or doubt.

            Full skill instructions are intentionally unavailable. Select a skill only
            from its exact registry name and description. Never select more than two.
            If spontaneous_allowed=false, choose silent for unsolicited participation;
            direct requests remain direct. If direct_reply_required=true, never choose
            silent. Be conservative, but do not require an @mention when the language
            clearly addresses the bot in context.
            PROMPT;
    }

    private static function tool(): Tool
    {
        return new Tool(
            name: self::TOOL_NAME,
            description: 'Choose the host route for exactly one Telegram batch.',
            parameters: [
                'type'       => 'object',
                'properties' => [
                    'mode' => [
                        'type' => 'string',
                        'enum' => [
                            SpaceResponseDecision::MODE_SILENT,
                            SpaceResponseDecision::MODE_DIRECT,
                            SpaceResponseDecision::MODE_SPONTANEOUS,
                            SpaceResponseDecision::MODE_SKILL,
                        ],
                    ],
                    'selected_skills' => [
                        'type'        => 'array',
                        'items'       => ['type' => 'string'],
                        'maxItems'    => 2,
                        'uniqueItems' => true,
                    ],
                ],
                'required'             => ['mode', 'selected_skills'],
                'additionalProperties' => false,
            ],
            strict: true,
        );
    }

    /**
     * Invalid router output fails to a base-agent pass for direct requests and
     * to silence for unsolicited chatter. Provider failures remain retryable at
     * the Temporal activity boundary.
     *
     * @param list<array{id?: string, name: string, arguments: array<string, mixed>}> $calls
     * @param SpaceResponseDecisionInput                                              $input
     */
    private static function validatedDecision(
        SpaceResponseDecisionInput $input,
        array $calls,
    ): SpaceResponseDecision {
        $fallback = static fn (): SpaceResponseDecision => new SpaceResponseDecision(
            $input->directRequired
                ? SpaceResponseDecision::MODE_BASE
                : SpaceResponseDecision::MODE_SILENT,
        );
        if (count($calls) !== 1 || ($calls[0]['name'] ?? null) !== self::TOOL_NAME) {
            return $fallback();
        }
        $arguments = $calls[0]['arguments'] ?? null;
        if (!is_array($arguments)) {
            return $fallback();
        }
        $mode     = $arguments['mode'] ?? null;
        $selected = $arguments['selected_skills'] ?? null;
        if (!is_string($mode) || !is_array($selected) || !array_is_list($selected)) {
            return $fallback();
        }
        $available = array_fill_keys(array_map(
            static fn ($skill): string => $skill->name,
            $input->skills,
        ), true);
        $names = [];
        foreach ($selected as $name) {
            if (!is_string($name) || !isset($available[$name]) || isset($names[$name])) {
                return $fallback();
            }
            $names[$name] = true;
        }
        $selected = array_keys($names);
        if (count($selected) > 2) {
            return $fallback();
        }
        if ($input->directRequired && $mode === SpaceResponseDecision::MODE_SILENT) {
            return new SpaceResponseDecision(SpaceResponseDecision::MODE_DIRECT, $selected);
        }
        if (
            !$input->directRequired
            && !$input->spontaneousAllowed
            && in_array($mode, [
                SpaceResponseDecision::MODE_SPONTANEOUS,
                SpaceResponseDecision::MODE_SKILL,
            ], true)
        ) {
            return new SpaceResponseDecision(SpaceResponseDecision::MODE_SILENT);
        }

        try {
            return new SpaceResponseDecision($mode, $selected);
        } catch (InvalidArgumentException) {
            return $fallback();
        }
    }
}
