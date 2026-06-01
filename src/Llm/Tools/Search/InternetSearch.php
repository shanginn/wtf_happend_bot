<?php

declare(strict_types=1);

namespace Bot\Llm\Tools\Search;

use Shanginn\Openai\ChatCompletion\Tool\AbstractTool;
use Shanginn\Openai\ChatCompletion\Tool\OpenaiToolSchema;
use Spiral\JsonSchemaGenerator\Attribute\Field;

#[OpenaiToolSchema(
    name: 'internet_search',
    description: 'Search the public internet through the configured SearXNG instance. Use this for current events, external facts, documentation, product information, or anything not reliably present in chat history.',
)]
class InternetSearch extends AbstractTool
{
    public function __construct(
        #[Field(
            title: 'query',
            description: 'Search query. SearXNG search syntax such as site:example.com is allowed.'
        )]
        public readonly string $query,
        #[Field(
            title: 'limit',
            description: 'Maximum number of web results to return (default 5, max 10).'
        )]
        public readonly int $limit = 5,
        #[Field(
            title: 'time_range',
            description: 'Optional SearXNG time range: day, month, or year. Leave empty for no date filter.'
        )]
        public readonly ?string $timeRange = null,
        #[Field(
            title: 'language',
            description: 'Optional language code such as en, ru, or en-US. Use auto or empty to let SearXNG choose.'
        )]
        public readonly string $language = 'auto',
        #[Field(
            title: 'categories',
            description: 'Optional comma-separated SearXNG categories such as general, news, images, videos, it, or science.'
        )]
        public readonly string $categories = 'general',
        #[Field(
            title: 'safe_search',
            description: 'SearXNG safe-search level: 0 off, 1 moderate, 2 strict. Defaults to 1.'
        )]
        public readonly int $safeSearch = 1,
    ) {}
}
