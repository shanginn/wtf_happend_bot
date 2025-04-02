<?php

declare(strict_types=1);

$botToken = getenv('TELEGRAM_BOT_TOKEN');
assert(is_string($botToken), 'Bot token must be a string');

$openrouterApiKey = getenv('OPENROUTER_API_KEY');
assert(is_string($openrouterApiKey), 'Anthropic API key must be a string');

$deepseekApiKey = getenv('DEEPSEEK_API_KEY');
assert(is_string($deepseekApiKey), 'DeepSeek API key must be a string');

return [
    'botToken' => $botToken,
    'openrouterApiKey' => $openrouterApiKey,
    'deepseekApiKey' => $deepseekApiKey,
];