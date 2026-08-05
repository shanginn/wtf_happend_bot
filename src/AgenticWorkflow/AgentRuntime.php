<?php

declare(strict_types=1);

namespace Bot\AgenticWorkflow;

final class AgentRuntime
{
    public const string MODEL                    = 'deepseek/deepseek-v4-flash';
    public const int MAX_TURNS                   = 32;
    public const int MAX_RETAINED_MESSAGES       = 120;
    public const int CONTINUE_AS_NEW_EVERY_TURNS = 12;
}
