<?php

declare(strict_types=1);

namespace Bot\Telegram;

use Phenogram\Bindings\Types\Interfaces\MessageInterface;
use Phenogram\Bindings\Types\Interfaces\UpdateInterface;

final class InvoiceWorkflowPayload
{
    private const string PREFIX = 'wtfh1';
    private const int MAX_TELEGRAM_PAYLOAD_BYTES = 128;
    private const int HASH_BYTES = 12;

    public static function encode(int $chatId, string $payload): string
    {
        $existing = self::decode($payload);
        if ($existing !== null && $existing->chatId === $chatId) {
            return $payload;
        }

        $encodedPayload = self::base64UrlEncode($payload);
        $candidate = self::format($chatId, 'p', $encodedPayload);

        if (strlen($candidate) <= self::MAX_TELEGRAM_PAYLOAD_BYTES) {
            return $candidate;
        }

        return self::format(
            $chatId,
            'h',
            self::base64UrlEncode(substr(hash('sha256', $payload, true), 0, self::HASH_BYTES)),
        );
    }

    public static function decode(?string $payload): ?InvoiceWorkflowRoute
    {
        if ($payload === null || !str_starts_with($payload, self::PREFIX . ':')) {
            return null;
        }

        $parts = explode(':', $payload, 4);
        if (count($parts) !== 4 || $parts[0] !== self::PREFIX) {
            return null;
        }

        $chatId = filter_var($parts[1], \FILTER_VALIDATE_INT);
        if (!is_int($chatId)) {
            return null;
        }

        return match ($parts[2]) {
            'p' => new InvoiceWorkflowRoute(
                chatId: $chatId,
                originalPayload: self::base64UrlDecode($parts[3]),
            ),
            'h' => new InvoiceWorkflowRoute(
                chatId: $chatId,
                payloadHash: $parts[3] === '' ? null : $parts[3],
            ),
            default => null,
        };
    }

    public static function routeFromUpdate(UpdateInterface $update): ?InvoiceWorkflowRoute
    {
        return self::decode(self::payloadFromUpdate($update));
    }

    public static function payloadFromUpdate(UpdateInterface $update): ?string
    {
        if ($update->shippingQuery !== null) {
            return $update->shippingQuery->invoicePayload;
        }

        if ($update->preCheckoutQuery !== null) {
            return $update->preCheckoutQuery->invoicePayload;
        }

        foreach (self::messageSources($update) as $message) {
            $payload = $message->successfulPayment?->invoicePayload
                ?? $message->refundedPayment?->invoicePayload
                ?? null;

            if ($payload !== null) {
                return $payload;
            }
        }

        if ($update->purchasedPaidMedia !== null) {
            return $update->purchasedPaidMedia->paidMediaPayload;
        }

        return null;
    }

    private static function format(int $chatId, string $kind, string $data): string
    {
        return self::PREFIX . ':' . $chatId . ':' . $kind . ':' . $data;
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): ?string
    {
        $padded = str_pad($value, strlen($value) + ((4 - strlen($value) % 4) % 4), '=');
        $decoded = base64_decode(strtr($padded, '-_', '+/'), strict: true);

        return is_string($decoded) ? $decoded : null;
    }

    /**
     * @return iterable<MessageInterface>
     */
    private static function messageSources(UpdateInterface $update): iterable
    {
        foreach (['message', 'editedMessage', 'channelPost', 'editedChannelPost', 'businessMessage', 'editedBusinessMessage'] as $field) {
            $message = $update->{$field};

            if ($message instanceof MessageInterface) {
                yield $message;
            }
        }
    }
}
