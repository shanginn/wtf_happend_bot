<?php

declare(strict_types=1);

namespace Bot\Space\Attention;

use Bot\Space\Runtime\SpaceSkillDefinition;
use InvalidArgumentException;
use PiPHP\Temporal\Serialization\HistoryPayloadGuard;
use Temporal\Internal\Marshaller\Meta\MarshalArray;

final readonly class SpaceResponseDecisionInput
{
    /**
     * @param list<array<string, mixed>> $messages
     * @param list<SpaceSkillDefinition> $skills
     * @param string                     $model
     * @param bool                       $directRequired
     * @param bool                       $spontaneousAllowed
     * @param string                     $idempotencyKey
     */
    public function __construct(
        public string $model,
        public array $messages,
        #[MarshalArray(of: SpaceSkillDefinition::class, nullable: false)]
        public array $skills,
        public bool $directRequired,
        public bool $spontaneousAllowed,
        public string $idempotencyKey,
    ) {
        if (trim($model) === '' || trim($idempotencyKey) === '') {
            throw new InvalidArgumentException(
                'Space response decision model and idempotency key must be non-empty.',
            );
        }
        if (!array_is_list($skills)) {
            throw new InvalidArgumentException('Space response decision skills must be a list.');
        }
        foreach ($skills as $skill) {
            if (!$skill instanceof SpaceSkillDefinition) {
                throw new InvalidArgumentException(
                    'Space response decision skills must be Space skill definitions.',
                );
            }
        }
        HistoryPayloadGuard::assertMessages($messages);
        HistoryPayloadGuard::assertJsonValue([
            'model'    => $model,
            'messages' => $messages,
            'skills'   => array_map(
                static fn (SpaceSkillDefinition $skill): array => [
                    'name'        => $skill->name,
                    'description' => $skill->description,
                ],
                $skills,
            ),
            'directRequired'     => $directRequired,
            'spontaneousAllowed' => $spontaneousAllowed,
            'idempotencyKey'     => $idempotencyKey,
        ], 'spaceResponseDecision');
    }
}
