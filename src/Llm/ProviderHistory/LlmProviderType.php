<?php

declare(strict_types=1);

namespace Bot\Llm\ProviderHistory;

enum LlmProviderType: string
{
    case Openai = 'openai';
    case Anthropic = 'anthropic';
}
