<?php

declare(strict_types=1);

namespace Bot\Llm\Skills;

use Phenogram\Bindings\ApiInterface;
use Shanginn\Openai\ChatCompletion\Tool\OpenaiToolSchema;

interface SkillInterface
{
    public static function name(): string;

    public static function description(): string;

    public static function skill(): string;
}
