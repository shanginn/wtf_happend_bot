<?php

declare(strict_types=1);

namespace Tests\Telegram;

use Bot\Telegram\InvoiceWorkflowPayload;
use Phenogram\Bindings\Factories\PreCheckoutQueryFactory;
use Phenogram\Bindings\Factories\UpdateFactory;
use Tests\TestCase;

class InvoiceWorkflowPayloadTest extends TestCase
{
    public function testEncodesAndDecodesRouteWithOriginalPayload(): void
    {
        $payload = InvoiceWorkflowPayload::encode(-100123456, 'order-42');
        $route = InvoiceWorkflowPayload::decode($payload);

        self::assertNotNull($route);
        self::assertSame(-100123456, $route->chatId);
        self::assertSame('order-42', $route->originalPayload);
        self::assertNull($route->payloadHash);
        self::assertLessThanOrEqual(128, strlen($payload));
    }

    public function testLongPayloadFallsBackToHashWithoutExceedingTelegramLimit(): void
    {
        $payload = InvoiceWorkflowPayload::encode(-100123456, str_repeat('x', 128));
        $route = InvoiceWorkflowPayload::decode($payload);

        self::assertNotNull($route);
        self::assertSame(-100123456, $route->chatId);
        self::assertNull($route->originalPayload);
        self::assertNotNull($route->payloadHash);
        self::assertLessThanOrEqual(128, strlen($payload));
    }

    public function testExtractsRouteFromPreCheckoutQuery(): void
    {
        $payload = InvoiceWorkflowPayload::encode(-100123456, 'invoice-on-the-fly');
        $update = UpdateFactory::make(
            preCheckoutQuery: PreCheckoutQueryFactory::make(invoicePayload: $payload),
        );

        $route = InvoiceWorkflowPayload::routeFromUpdate($update);

        self::assertNotNull($route);
        self::assertSame(-100123456, $route->chatId);
        self::assertSame('invoice-on-the-fly', $route->originalPayload);
    }
}
