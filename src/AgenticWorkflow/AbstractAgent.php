<?php

declare(strict_types=1);

namespace Bot\AgenticWorkflow;

use Bot\Agent\OpenaiMessageTransformer;
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
     * @param array<class-string<SkillInterface>> $skills
     */
    protected static function buildSkillsPrompt(array $skills): string
    {
        if ($skills === []) {
            return '';
        }

        $parts = array_map(
            fn (string $skill) => <<<XML
            <skill name="{$skill::name()}" description="{$skill::description()}">
                {$skill::skill()}
            </skill>
            XML,
            $skills,
        );

        return "\n<available_skills>\n" . implode("\n\n", $parts) . "\n</available_skills>\n";
    }

    /**
     * @param array<class-string<AbstractTool>> $tools
     */
    protected static function buildToolsPrompt(array $tools): string
    {
        if ($tools === []) {
            return '';
        }

        $toolParts = array_map(
            fn (string $toolClass) => <<<XML
            <tool name="{$toolClass::getName()}" description="{$toolClass::getDescription()}"/>
            XML,
            $tools,
        );

        return "\n<available_tools>\n" . implode("\n\n", $toolParts) . "\n</available_tools>\n";
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
