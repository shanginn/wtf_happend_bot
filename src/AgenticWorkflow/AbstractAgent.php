<?php

declare(strict_types=1);

namespace Bot\AgenticWorkflow;

use Bot\Agent\OpenaiMessageTransformer;
use Bot\Llm\Runtime\RuntimeSkillDefinition;
use Bot\Llm\Runtime\RuntimeToolDefinition;
use Bot\Llm\Skills\SkillInterface;
use Bot\Telegram\TelegramUpdateViewFactory;
use Bot\Telegram\TelegramUpdateViewFactoryInterface;
use Phenogram\Bindings\Types\Interfaces\UpdateInterface;
use Shanginn\Openai\ChatCompletion\ErrorResponse;
use Shanginn\Openai\ChatCompletion\Message\MessageInterface;
use Shanginn\Openai\ChatCompletion\Tool\AbstractTool;
use Shanginn\Openai\Openai;

abstract class AbstractAgent
{
    protected readonly TelegramUpdateViewFactoryInterface $updateViewFactory;
    protected readonly OpenaiMessageTransformer $updateTransformer;

    public function __construct(
        public readonly Openai $openai,
        ?TelegramUpdateViewFactoryInterface $updateViewFactory = null,
        ?OpenaiMessageTransformer $updateTransformer = null,
    ) {
        $this->updateViewFactory = $updateViewFactory ?? new TelegramUpdateViewFactory();
        $this->updateTransformer = $updateTransformer ?? new OpenaiMessageTransformer();
    }

    /**
     * @param array<class-string<SkillInterface>|RuntimeSkillDefinition> $skills
     */
    protected static function buildSkillsPrompt(array $skills): string
    {
        if ($skills === []) {
            return '';
        }

        $parts = array_map(
            static function (string|RuntimeSkillDefinition $skill): string {
                $name = self::skillName($skill);
                $description = self::skillDescription($skill);
                $body = self::skillBody($skill);

                return <<<XML
                <skill name="{$name}" description="{$description}">
                    {$body}
                </skill>
                XML;
            },
            $skills,
        );

        return "\n<available_skills>\n" . implode("\n\n", $parts) . "\n</available_skills>\n";
    }

    /**
     * @param array<class-string<AbstractTool>|RuntimeToolDefinition> $tools
     */
    protected static function buildToolsPrompt(array $tools): string
    {
        if ($tools === []) {
            return '';
        }

        $toolParts = array_map(
            static function (string|RuntimeToolDefinition $tool): string {
                $name = self::toolName($tool);
                $description = self::toolDescription($tool);

                return <<<XML
                <tool name="{$name}" description="{$description}"/>
                XML;
            },
            $tools,
        );

        return "\n<available_tools>\n" . implode("\n\n", $toolParts) . "\n</available_tools>\n";
    }

    private static function skillName(string|RuntimeSkillDefinition $skill): string
    {
        return $skill instanceof RuntimeSkillDefinition ? $skill->name : $skill::name();
    }

    private static function skillDescription(string|RuntimeSkillDefinition $skill): string
    {
        return $skill instanceof RuntimeSkillDefinition ? $skill->description : $skill::description();
    }

    private static function skillBody(string|RuntimeSkillDefinition $skill): string
    {
        return $skill instanceof RuntimeSkillDefinition ? $skill->body : $skill::skill();
    }

    private static function toolName(string|RuntimeToolDefinition $tool): string
    {
        return $tool instanceof RuntimeToolDefinition ? $tool->name : $tool::getName();
    }

    private static function toolDescription(string|RuntimeToolDefinition $tool): string
    {
        return $tool instanceof RuntimeToolDefinition ? $tool->description : $tool::getDescription();
    }

    /**
     * @param array<UpdateInterface> $updates
     * @return array<MessageInterface>
     */
    public function transformUpdates(array $updates): array
    {
        return array_map(
            fn (UpdateInterface $update): MessageInterface => $this->updateTransformer->toChatUserMessage(
                $this->updateViewFactory->create($update)
            ),
            $updates,
        );
    }

    protected function emptyHistoryError(): ErrorResponse
    {
        return new ErrorResponse(
            message: 'No messages to process.',
            type: null,
            param: null,
            code: null,
            rawResponse: '',
        );
    }
}
