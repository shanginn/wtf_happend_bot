<?php

declare(strict_types=1);

namespace Bot\Agent;

class UserMessageView
{
    public function __construct(
        public ?string $text = null,
        public ?string $name = null,
        public array $images = [],
        public array $files = [],
    ) {
        if ($text === null && count($images) === 0 && count($files) === 0) {
            throw new \InvalidArgumentException('At least one of text, images, or files must be provided.');
        }
    }
}