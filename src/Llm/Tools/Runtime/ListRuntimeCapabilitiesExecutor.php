<?php

declare(strict_types=1);

namespace Bot\Llm\Tools\Runtime;

use Bot\Entity\RuntimeSkill;
use Bot\Entity\RuntimeSkill\RuntimeSkillRepository;
use Bot\Entity\RuntimeTool;
use Bot\Entity\RuntimeTool\RuntimeToolRepository;
use Bot\Llm\Runtime\RuntimeCapabilityValidator;
use Cycle\ORM\ORMInterface;
use UnexpectedValueException;

final class ListRuntimeCapabilitiesExecutor
{
    public function __construct(
        private readonly ORMInterface $orm,
    ) {}

    public function execute(
        int $chatId,
        string $kind = 'all',
        bool $includeDisabled = false,
        int $limit = RuntimeCapabilityValidator::MAX_LIST_LIMIT,
        int $offset = 0,
    ): string {
        $kind = strtolower(trim($kind));
        if (!in_array($kind, ['all', 'skill', 'tool'], true)) {
            return 'Unknown capability kind. Use "all", "skill", or "tool".';
        }

        $limit   = max(1, min(RuntimeCapabilityValidator::MAX_LIST_LIMIT, $limit));
        $offset  = max(0, $offset);
        $entries = [];
        $payload = [
            'chat_id'            => $chatId,
            'skills'             => [],
            'tools'              => [],
            'static_skill_names' => RuntimeCapabilityValidator::staticSkillNames(),
            'static_tool_names'  => RuntimeCapabilityValidator::staticToolNames(),
        ];

        if ($kind === 'all' || $kind === 'skill') {
            /** @var RuntimeSkillRepository $repo */
            $repo = $this->orm->getRepository(RuntimeSkill::class);
            foreach ($repo->findByChatId($chatId, !$includeDisabled) as $skill) {
                $entries[] = [
                    'kind'       => 'skill',
                    'updated_at' => $skill->updatedAt,
                    'payload'    => [
                        'name' => self::boundedText(
                            $skill->name,
                            RuntimeCapabilityValidator::MAX_NAME_BYTES,
                        ),
                        'description' => self::boundedText(
                            $skill->description,
                            RuntimeCapabilityValidator::MAX_DESCRIPTION_BYTES,
                        ),
                        'body' => self::boundedText(
                            $skill->body,
                            RuntimeCapabilityValidator::MAX_SKILL_BODY_BYTES,
                        ),
                        'content_truncated' => strlen($skill->name) > RuntimeCapabilityValidator::MAX_NAME_BYTES
                            || strlen($skill->description) > RuntimeCapabilityValidator::MAX_DESCRIPTION_BYTES
                            || strlen($skill->body) > RuntimeCapabilityValidator::MAX_SKILL_BODY_BYTES,
                        'enabled'    => $skill->enabled,
                        'updated_at' => $skill->updatedAt,
                    ],
                ];
            }
        }

        if ($kind === 'all' || $kind === 'tool') {
            /** @var RuntimeToolRepository $repo */
            $repo = $this->orm->getRepository(RuntimeTool::class);
            foreach ($repo->findByChatId($chatId, !$includeDisabled) as $tool) {
                [$parametersSchema, $schemaError] = self::decodedSchema($tool);
                $entries[]                        = [
                    'kind'       => 'tool',
                    'updated_at' => $tool->updatedAt,
                    'payload'    => [
                        'name' => self::boundedText(
                            $tool->name,
                            RuntimeCapabilityValidator::MAX_NAME_BYTES,
                        ),
                        'description' => self::boundedText(
                            $tool->description,
                            RuntimeCapabilityValidator::MAX_DESCRIPTION_BYTES,
                        ),
                        'parameters_schema' => $parametersSchema,
                        'schema_error'      => $schemaError,
                        'instructions'      => self::boundedText(
                            $tool->instructions,
                            RuntimeCapabilityValidator::MAX_TOOL_INSTRUCTIONS_BYTES,
                        ),
                        'content_truncated' => strlen($tool->name) > RuntimeCapabilityValidator::MAX_NAME_BYTES
                            || strlen($tool->description) > RuntimeCapabilityValidator::MAX_DESCRIPTION_BYTES
                            || strlen($tool->instructions) > RuntimeCapabilityValidator::MAX_TOOL_INSTRUCTIONS_BYTES,
                        'enabled'    => $tool->enabled,
                        'updated_at' => $tool->updatedAt,
                    ],
                ];
            }
        }

        usort(
            $entries,
            static fn (array $left, array $right): int => ($right['updated_at'] <=> $left['updated_at'])
                ?: strcmp($left['kind'], $right['kind'])
                ?: strcmp($left['payload']['name'], $right['payload']['name']),
        );

        $total = count($entries);
        foreach (array_slice($entries, $offset, $limit) as $entry) {
            $payload[$entry['kind'] === 'skill' ? 'skills' : 'tools'][] = $entry['payload'];
        }

        $payload['pagination'] = [
            'limit'    => $limit,
            'offset'   => $offset,
            'total'    => $total,
            'has_more' => $offset + $limit < $total,
        ];

        return json_encode(
            $payload,
            \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
        );
    }

    /**
     * @param RuntimeTool $tool
     *
     * @return array{0: ?array<string, mixed>, 1: ?string}
     */
    private static function decodedSchema(RuntimeTool $tool): array
    {
        try {
            return [
                RuntimeCapabilityValidator::decodeParametersSchema($tool->parametersSchema),
                null,
            ];
        } catch (UnexpectedValueException $error) {
            return [null, $error->getMessage()];
        }
    }

    private static function boundedText(string $value, int $maxBytes): string
    {
        return strlen($value) <= $maxBytes
            ? $value
            : mb_strcut($value, 0, $maxBytes, 'UTF-8');
    }
}
