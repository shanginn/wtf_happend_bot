<?php

declare(strict_types=1);

namespace Bot\AgenticWorkflow;

use Bot\Agent\OpenaiMessageTransformer;
use Bot\Llm\Skills\RelevantMemoriesSkill;
use Bot\Llm\Skills\SkillInterface;
use Bot\Telegram\TelegramUpdateViewFactoryInterface;
use Phenogram\Bindings\Types\Interfaces\UpdateInterface;
use Shanginn\Openai\ChatCompletion\CompletionResponse;
use Shanginn\Openai\ChatCompletion\ErrorResponse;
use Shanginn\Openai\ChatCompletion\Message\MessageInterface;
use Shanginn\Openai\ChatCompletion\Tool\AbstractTool;
use Shanginn\Openai\Openai;

class Agent
{
    private readonly DecisionAgent $decisionAgent;
    private readonly RelevantMemoriesAgent $relevantMemoriesAgent;
    private readonly ResponseAgent $responseAgent;

    public function __construct(
        public readonly Openai $openai,
        ?Openai $decisionOpenai = null,
        ?Openai $memoryRecollectionOpenai = null,
        ?TelegramUpdateViewFactoryInterface $updateViewFactory = null,
        ?OpenaiMessageTransformer $updateTransformer = null,
    ) {
        $updateViewFactory ??= new \Bot\Telegram\TelegramUpdateViewFactory();
        $updateTransformer ??= new OpenaiMessageTransformer();
        $decisionOpenai ??= $openai;
        $memoryRecollectionOpenai ??= $openai;

        $this->decisionAgent = new DecisionAgent(
            openai: $decisionOpenai,
            updateViewFactory: $updateViewFactory,
            updateTransformer: $updateTransformer,
        );
        $this->relevantMemoriesAgent = new RelevantMemoriesAgent(
            openai: $memoryRecollectionOpenai,
            updateViewFactory: $updateViewFactory,
            updateTransformer: $updateTransformer,
        );
        $this->responseAgent = new ResponseAgent(
            openai: $openai,
            updateViewFactory: $updateViewFactory,
            updateTransformer: $updateTransformer,
        );
    }

    /**
     * @param array<UpdateInterface> $updates
     * @return array<MessageInterface>
     */
    public function transformUpdates(array $updates): array
    {
        return $this->decisionAgent->transformUpdates($updates);
    }

    /**
     * @param array<MessageInterface> $history
     * @param array<class-string<AbstractTool>> $tools
     * @param array<class-string<SkillInterface>> $skills
     */
    public function complete(
        array $history,
        array $tools = [],
        array $skills = [],
    ): CompletionResponse|ErrorResponse {
        return $this->decisionAgent->decide($history, $tools, $skills);
    }

    /**
     * @param array<MessageInterface> $history
     * @param array<class-string<SkillInterface>> $skills
     */
    public function recollectRelevantMemories(
        array $history,
        string $allMemories,
        array $skills = [RelevantMemoriesSkill::class],
    ): CompletionResponse|ErrorResponse {
        return $this->relevantMemoriesAgent->recollect($history, $allMemories, $skills);
    }

    /**
     * @param array<MessageInterface> $history
     * @param array<class-string<AbstractTool>> $tools
     * @param array<class-string<SkillInterface>> $skills
     */
    public function respond(
        array $history,
        array $tools = [],
        array $skills = [],
    ): CompletionResponse|ErrorResponse {
        return $this->responseAgent->respond($history, $tools, $skills);
    }

    /**
     * @param array<UpdateInterface> $updates
     * @param array<MessageInterface> $history
     * @param array<class-string<AbstractTool>> $tools
     * @param array<class-string<SkillInterface>> $skills
     */
    public function processUpdates(
        array $updates,
        array $history = [],
        array $tools = [],
        array $skills = [],
    ): CompletionResponse|ErrorResponse {
        return $this->complete(
            history: [...$history, ...$this->transformUpdates($updates)],
            tools: $tools,
            skills: $skills,
        );
    }
}
