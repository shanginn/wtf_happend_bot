<?php

declare(strict_types=1);

namespace Bot\AgenticWorkflow;

use Bot\Llm\Runtime\RuntimeCapabilityRegistry;
use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;

#[ActivityInterface(prefix: 'PiAgentContext.')]
final readonly class AgentContextActivity
{
    public function __construct(
        private RuntimeCapabilityRegistry $runtimeCapabilities,
    ) {}

    #[ActivityMethod]
    public function runtimeInstructions(int $chatId): string
    {
        $skills = $this->runtimeCapabilities->runtimeSkillsForChat($chatId);
        if ($skills === []) {
            return '';
        }

        $sections = [];
        foreach ($skills as $skill) {
            $sections[] = sprintf(
                "### %s\nWhen to use: %s\n\n%s",
                $skill->name,
                $skill->description,
                $skill->body,
            );
        }

        return implode("\n\n", $sections);
    }
}
