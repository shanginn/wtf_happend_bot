<?php

declare(strict_types=1);

namespace Bot\Activity;

use Bot\Llm\Skills\ImageAnalysisSkill;
use Carbon\CarbonInterval;
use Phenogram\Bindings\ApiInterface;
use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;
use Temporal\Activity\ActivityOptions;
use Temporal\Common\RetryOptions;
use Temporal\Internal\Workflow\ActivityProxy;
use Temporal\Workflow;

#[ActivityInterface(prefix: 'ImageSkill.')]
class ImageSkillActivity
{
    public function __construct(
        private ApiInterface $api,
    ) {}

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

    #[ActivityMethod]
    public function execute(string $toolName, array $arguments): ?string
    {
        return ImageAnalysisSkill::executeTool($toolName, $arguments, $this->api);
    }
}
