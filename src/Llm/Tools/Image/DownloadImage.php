<?php

declare(strict_types=1);

namespace Bot\Llm\Tools\Image;

use Shanginn\Openai\ChatCompletion\Tool\AbstractTool;
use Shanginn\Openai\ChatCompletion\Tool\OpenaiToolSchema;
use Spiral\JsonSchemaGenerator\Attribute\Field;

#[OpenaiToolSchema(
    name: 'download_image',
    description: 'Downloads an image from Telegram for analysis. Use this when you need to see the content of an image shared in the chat.',
)]
class DownloadImage extends AbstractTool
{
    public function __construct(
        #[Field(
            title: 'file_id',
            description: 'The file_id of the image to download (from the message photo or document)'
        )]
        public readonly string $fileId,
    ) {}
}
