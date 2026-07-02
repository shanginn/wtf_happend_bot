<?php

declare(strict_types=1);

namespace Bot\Telegram;

use Phenogram\Bindings\ApiInterface;

final readonly class PaymentQueryAnswer
{
    public const string ACTION_PRE_CHECKOUT = 'answerPreCheckoutQuery';
    public const string ACTION_SHIPPING = 'answerShippingQuery';
    public const string ACTION_NONE = 'none';

    /**
     * @param array<mixed>|null $shippingOptions
     */
    private function __construct(
        public string $action,
        public string $queryId,
        public bool $ok,
        public ?string $errorMessage = null,
        public ?array $shippingOptions = null,
    ) {}

    /**
     * @param array<string, mixed>|null $payload
     */
    public static function fromWorkflowPayload(?array $payload): ?self
    {
        if ($payload === null || ($payload['action'] ?? null) === self::ACTION_NONE) {
            return null;
        }

        $action = $payload['action'] ?? null;
        $queryId = $payload['query_id'] ?? null;
        if (!is_string($action) || !is_string($queryId) || $queryId === '') {
            return null;
        }

        if (!in_array($action, [self::ACTION_PRE_CHECKOUT, self::ACTION_SHIPPING], true)) {
            return null;
        }

        $ok = (bool) ($payload['ok'] ?? false);
        $errorMessage = $payload['error_message'] ?? null;
        $shippingOptions = $payload['shipping_options'] ?? null;

        return new self(
            action: $action,
            queryId: $queryId,
            ok: $ok,
            errorMessage: is_string($errorMessage) ? $errorMessage : null,
            shippingOptions: is_array($shippingOptions) ? $shippingOptions : null,
        );
    }

    public static function rejectedPreCheckout(string $queryId, string $message): self
    {
        return new self(
            action: self::ACTION_PRE_CHECKOUT,
            queryId: $queryId,
            ok: false,
            errorMessage: $message,
        );
    }

    public static function rejectedShipping(string $queryId, string $message): self
    {
        return new self(
            action: self::ACTION_SHIPPING,
            queryId: $queryId,
            ok: false,
            errorMessage: $message,
        );
    }

    public function send(ApiInterface $api): void
    {
        if ($this->action === self::ACTION_PRE_CHECKOUT) {
            $api->answerPreCheckoutQuery(
                preCheckoutQueryId: $this->queryId,
                ok: $this->ok,
                errorMessage: $this->errorMessage,
            );
            return;
        }

        if ($this->action === self::ACTION_SHIPPING) {
            $api->answerShippingQuery(
                shippingQueryId: $this->queryId,
                ok: $this->ok,
                shippingOptions: $this->shippingOptions,
                errorMessage: $this->errorMessage,
            );
        }
    }
}
