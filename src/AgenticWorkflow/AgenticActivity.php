<?php

declare(strict_types=1);

namespace Bot\AgenticWorkflow;

use Bot\Agent\UpdateTransformerInterface;
use Bot\Llm\Skills\SkillInterface;
use Bot\Telegram\TelegramFileUrlResolver;
use Bot\Telegram\TelegramFileUrlResolverInterface;
use Bot\Telegram\TelegramUpdateViewFactory;
use Bot\Telegram\TelegramUpdateViewFactoryInterface;
use Carbon\CarbonInterval;
use Phenogram\Bindings\ApiInterface;
use Phenogram\Bindings\Types\Interfaces\UpdateInterface;
use Shanginn\Openai\ChatCompletion\CompletionResponse;
use Shanginn\Openai\ChatCompletion\ErrorResponse;
use Shanginn\Openai\ChatCompletion\Message\MessageInterface;
use Shanginn\Openai\ChatCompletion\Tool\AbstractTool;
use Shanginn\Openai\Openai;
use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;
use Temporal\Activity\ActivityOptions;
use Temporal\Common\RetryOptions;
use Temporal\Internal\Workflow\ActivityProxy;
use Temporal\Workflow;

#[ActivityInterface(prefix: 'Agentic.')]
class AgenticActivity
{
    private readonly Agent $agent;

    public function __construct(
        Openai $openai,
        ApiInterface $api,
        ?TelegramFileUrlResolverInterface $fileUrlResolver = null,
        ?TelegramUpdateViewFactoryInterface $updateViewFactory = null,
        ?UpdateTransformerInterface $updateTransformer = null,
    ) {
        $fileUrlResolver ??= new TelegramFileUrlResolver($api);
        $updateViewFactory ??= new TelegramUpdateViewFactory($fileUrlResolver);
        $this->agent = new Agent($openai, $updateViewFactory, $updateTransformer);
    }

    public static function getDefinition(): ActivityProxy|self
    {
        return Workflow::newActivityStub(
            self::class,
            ActivityOptions::new()
                ->withStartToCloseTimeout(CarbonInterval::minute())
                ->withRetryOptions(
                    RetryOptions::new()->withNonRetryableExceptions([])
                )
        );
    }

    /**
     * @param array<UpdateInterface> $updates
     * @return array<MessageInterface>
     */
    #[ActivityMethod]
    public function transformUpdates(array $updates): array
    {
        return $this->agent->transformUpdates($updates);
    }

    /**
     * @param array<MessageInterface> $history
     * @param array<class-string<AbstractTool>> $tools
     * @param array<class-string<SkillInterface>> $skills
     */
    #[ActivityMethod]
    public function complete(
        int|string $chatId,
        int|string $threadId,
        array $tools = [],
        array $skills = [],
    ): ErrorResponse|CompletionResponse {
        return $this->agent->complete(
            history: $history,
            tools: $tools,
            skills: $skills,
        );
    }

    /**
     * @param array<UpdateInterface> $updates
     * @param array<MessageInterface> $history
     * @param array<class-string<AbstractTool>> $tools
     * @param array<class-string<SkillInterface>> $skills
     */
    #[ActivityMethod]
    public function processUpdates(
        array $updates,
        array $history = [],
        array $tools = [],
        array $skills = [],
    ): ErrorResponse|CompletionResponse {
        return $this->agent->processUpdates(
            updates: $updates,
            history: $history,
            tools: $tools,
            skills: $skills,
        );
    }
}
