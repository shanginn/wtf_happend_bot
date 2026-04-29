<?php

declare(strict_types=1);

namespace Bot\Llm\Tools\Runtime;

use Bot\Entity\RuntimeSkill;
use Bot\Llm\Runtime\RuntimeCapabilityValidator;
use Cycle\ORM\ORMInterface;
use Phenogram\Bindings\ApiInterface;
use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;

#[ActivityInterface(prefix: 'UpsertRuntimeSkillExecutor.')]
class UpsertRuntimeSkillExecutor
{
    public function __construct(
        private readonly ORMInterface $orm,
        private readonly ApiInterface $api,
    ) {}

    #[ActivityMethod]
    public function execute(int $chatId, UpsertRuntimeSkill $schema): string
    {
        $name = RuntimeCapabilityValidator::normalizeName($schema->name);
        $nameError = RuntimeCapabilityValidator::nameError($name);
        if ($nameError !== null) {
            return $nameError;
        }

        $description = trim($schema->description);
        if ($description === '') {
            return 'Skill description cannot be empty.';
        }

        $body = trim($schema->body);
        if ($body === '') {
            return 'Skill body cannot be empty.';
        }

        /** @var \Bot\Entity\RuntimeSkill\RuntimeSkillRepository $repo */
        $repo = $this->orm->getRepository(RuntimeSkill::class);
        $skill = $repo->findByName($chatId, $name);
        $created = $skill === null;

        if ($skill === null) {
            $skill = new RuntimeSkill(
                chatId: $chatId,
                name: $name,
                description: $description,
                body: $body,
                enabled: $schema->enabled,
            );
        } else {
            $skill->description = $description;
            $skill->body = $body;
            $skill->enabled = $schema->enabled;
            $skill->touch();
        }

        $repo->save($skill);

        $this->api->sendMessage(
            chatId: $chatId,
            text: self::notificationText($name, $created),
        );

        return sprintf(
            'Runtime skill "%s" %s and is %s.',
            $name,
            $created ? 'created' : 'updated',
            $skill->enabled ? 'enabled' : 'disabled',
        );
    }

    private static function notificationText(string $name, bool $created): string
    {
        return sprintf(
            'Навык %s: %s',
            $created ? 'добавлен' : 'обновлён',
            $name,
        );
    }
}
