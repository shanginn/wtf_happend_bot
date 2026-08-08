<?php

declare(strict_types=1);

namespace Bot\Telegram;

use Phenogram\Bindings\ApiInterface;

final readonly class PaymentQueryAnswer
{
    public const string ACTION_PRE_CHECKOUT = 'answerPreCheckoutQuery';
    public const string ACTION_SHIPPING     = 'answerShippingQuery';

    /**
     * @param array<mixed>|null $shippingOptions
     * @param string            $action
     * @param string            $queryId
     * @param bool              $ok
     * @param ?string           $errorMessage
     */
    private function __construct(
        public string $action,
        public string $queryId,
        public bool $ok,
        public ?string $errorMessage = null,
        public ?array $shippingOptions = null,
    ) {}

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
