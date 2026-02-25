<?php

declare(strict_types=1);

namespace Bot\Llm\Skills;

interface SkillInterface
{
    public static function name(): string;

    public static function description(): string;

    public static function skill(): string;
}
