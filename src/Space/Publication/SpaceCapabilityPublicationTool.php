<?php

declare(strict_types=1);

namespace Bot\Space\Publication;

use Bot\Space\Tools\SpaceCapabilityMutationAuthority;
use Bot\Telegram\TelegramChatAuthorizationPolicy;
use Closure;
use PiPHP\Agent\CancellationToken;
use PiPHP\Agent\Enum\ToolExecutionMode;
use PiPHP\Agent\Tool\AgentToolResult;
use PiPHP\AI\Content\TextContent;
use PiPHP\AI\Tool\Tool;
use PiPHP\Temporal\Tool\DurableAgentTool;
use PiPHP\Temporal\Tool\DurableToolExecutionContext;
use UnexpectedValueException;

/** Host-owned durable publication tool available only to Space agents. */
final readonly class SpaceCapabilityPublicationTool
{
    public const string NAME = 'publish_space_capability';

    public function __construct(
        private SpaceCapabilityPublisher $publisher,
        private TelegramChatAuthorizationPolicy $authorization,
    ) {}

    public static function definition(): Tool
    {
        return new Tool(
            name: self::NAME,
            description: 'Publish one explicitly requested Space skill or slash command in a new immutable release after the host verifies the exact requesting Telegram owner or administrator.',
            parameters: [
                'type'       => 'object',
                'properties' => [
                    'request_update_id' => [
                        'type'        => 'integer',
                        'description' => 'Telegram update reference printed in the exact requesting message.',
                        'minimum'     => 0,
                    ],
                    'request_quote' => [
                        'type'        => 'string',
                        'description' => 'Exact verbatim excerpt that explicitly requests this publication.',
                        'minLength'   => 1,
                        'maxLength'   => 1000,
                    ],
                    'kind' => [
                        'type' => 'string',
                        'enum' => [
                            SpaceCapabilityPublicationInput::KIND_SKILL,
                            SpaceCapabilityPublicationInput::KIND_COMMAND,
                        ],
                    ],
                    'name' => [
                        'type'        => 'string',
                        'description' => 'Canonical lowercase capability name; omit the leading slash.',
                        'minLength'   => 1,
                        'maxLength'   => 64,
                    ],
                ],
                'required' => [
                    'request_update_id',
                    'request_quote',
                    'kind',
                    'name',
                ],
                'additionalProperties' => false,
            ],
        );
    }

    public function durable(): DurableAgentTool
    {
        return new DurableAgentTool(
            tool: self::definition(),
            label: self::NAME,
            handler: function (
                DurableToolExecutionContext $context,
                CancellationToken $_cancellation,
                ?Closure $_onUpdate,
            ): AgentToolResult {
                $spaceId         = self::metadataString($context, 'spaceId');
                $snapshotId      = self::metadataString($context, 'runtimeSnapshotId');
                $terminalScope   = self::metadataString($context, 'terminalScopeId');
                $kind            = self::requiredString($context->arguments, 'kind');
                $name            = self::requiredString($context->arguments, 'name');
                $requestUpdateId = self::requiredInteger($context->arguments, 'request_update_id');
                $requestQuote    = self::requiredString($context->arguments, 'request_quote');

                $persistedAuthority = $this->publisher->persistedAuthority(
                    spaceId: $spaceId,
                    terminalScopeId: $terminalScope,
                    invocationKey: $context->idempotencyKey,
                );
                $mutationAuthority = SpaceCapabilityMutationAuthority::fromMetadata(
                    $context->metadata,
                    $this->authorization,
                );
                $authority = $mutationAuthority->authorize(
                    requestUpdateId: $requestUpdateId,
                    requestQuote: $requestQuote,
                    kind: $kind,
                    name: $name,
                    persistedAuthority: $persistedAuthority,
                );
                $spec = SpaceCapabilityRequestSpec::fromTrustedRequest(
                    kind: $kind,
                    name: $name,
                    requestText: $mutationAuthority->authorizedRequestText($authority),
                );

                $result = $this->publisher->publish(new SpaceCapabilityPublicationInput(
                    spaceId: $spaceId,
                    runtimeSnapshotId: $snapshotId,
                    terminalScopeId: $terminalScope,
                    invocationKey: $context->idempotencyKey,
                    kind: $kind,
                    name: $name,
                    description: $spec->description,
                    instructions: $spec->instructions,
                    authorizationProvenance: $authority,
                    parametersSchema: $spec->parametersSchema,
                ));

                return new AgentToolResult(content: [new TextContent($result->message())]);
            },
            mode: ToolExecutionMode::Sequential,
        );
    }

    /** @param array<string, mixed> $values */
    private static function requiredString(array $values, string $key): string
    {
        $value = $values[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new UnexpectedValueException("{$key} must be a non-empty string.");
        }

        return trim($value);
    }

    /** @param array<string, mixed> $values */
    private static function requiredInteger(array $values, string $key): int
    {
        $value = $values[$key] ?? null;
        if (!is_int($value) || $value < 0) {
            throw new UnexpectedValueException("{$key} must be a non-negative integer.");
        }

        return $value;
    }

    private static function metadataString(DurableToolExecutionContext $context, string $key): string
    {
        $value = $context->metadata[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new UnexpectedValueException("Space publication context is missing {$key}.");
        }

        return $value;
    }
}
