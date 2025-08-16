<?php

declare(strict_types=1);

$botToken = $_ENV['TELEGRAM_BOT_TOKEN'];
assert(is_string($botToken), 'Bot token must be a string');

$openrouterApiKey = $_ENV['OPENROUTER_API_KEY'];
assert(is_string($openrouterApiKey), 'Anthropic API key must be a string');

$deepseekApiKey = $_ENV['DEEPSEEK_API_KEY'];
assert(is_string($deepseekApiKey), 'DeepSeek API key must be a string');

$mem0ApiKey = $_ENV['MEM0_API_KEY'];
assert(is_string($mem0ApiKey), 'Mem0 API key must be a string');

return [
    'botToken' => $botToken,
    'openrouterApiKey' => $openrouterApiKey,
    'deepseekApiKey' => $deepseekApiKey,
    'mem0ApiKey' => $mem0ApiKey,
];