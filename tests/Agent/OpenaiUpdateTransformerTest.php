<?php

declare(strict_types=1);

namespace Tests\Agent;

use Bot\Agent\OpenaiMessageTransformer;
use Bot\Telegram\InputMessageView;
use Shanginn\Openai\ChatCompletion\Message\User\ImageContentPart;
use Shanginn\Openai\ChatCompletion\Message\User\TextContentPart;
use Tests\TestCase;

class OpenaiUpdateTransformerTest extends TestCase
{
    public function testMapsTextOnlyViewToPlainOpenaiUserMessage(): void
    {
        $message = (new OpenaiMessageTransformer())->toChatUserMessage(new InputMessageView(
            text: "Telegram update: message\n\nText:\nhello there",
            participantReference: 'alice',
        ));

        $this->assertSame('tg_alice', $message->name);
        $this->assertSame("Telegram update: message\n\nText:\nhello there", $message->content);
    }

    public function testMapsMultimodalViewToOpenaiContentParts(): void
    {
        $message = (new OpenaiMessageTransformer())->toChatUserMessage(new InputMessageView(
            text: "Telegram update: edited message\n\nCaption:\nCat tax",
            participantReference: 'user_11',
            imageUrls: [
                'https://cdn.example.test/photo-big.jpg',
                'https://cdn.example.test/thumb.jpg',
            ],
        ));

        $this->assertSame('tg_user_11', $message->name);
        $this->assertIsArray($message->content);
        $this->assertCount(3, $message->content);
        $this->assertInstanceOf(TextContentPart::class, $message->content[0]);
        $this->assertInstanceOf(ImageContentPart::class, $message->content[1]);
        $this->assertInstanceOf(ImageContentPart::class, $message->content[2]);
        $this->assertSame("Telegram update: edited message\n\nCaption:\nCat tax", $message->content[0]->text);
        $this->assertSame('https://cdn.example.test/photo-big.jpg', $message->content[1]->url);
        $this->assertSame('https://cdn.example.test/thumb.jpg', $message->content[2]->url);
    }
}
