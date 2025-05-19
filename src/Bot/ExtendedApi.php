<?php

declare(strict_types=1);

namespace Bot\Bot;

use function Amp\async;
use function Amp\delay;

use Phenogram\Bindings\Api;
use Phenogram\Bindings\Types\Message;
use Phenogram\Bindings\Types\User;
use Throwable;

class ExtendedApi extends Api
{
    private User $me;

    public function sendMessage(...$args): Message
    {
        $text = $args['text'];

        if (mb_strlen($text) > 4095) {
            $chunks = mb_str_split($text, 4095);

            assert(is_array($chunks) && count($chunks) > 1);

            foreach ($chunks as $chunk) {
                $args['text'] = $chunk;
                $lastMessage  = $this->sendMessage(...$args);
            }

            return $lastMessage;
        }

        return parent::sendMessage(...$args);
    }

    public function getMe(): User
    {
        if (!isset($this->me)) {
            $this->me = parent::getMe();
        }

        return $this->me;
    }

    protected function doRequest(
        string $method,
        array $args,
        string $returnType,
        bool $returnsArray = false
    ): mixed {
        $retries      = 5;
        $currentRetry = 0;

        do {
            try {
                return parent::doRequest($method, $args, $returnType, $returnsArray);
            } catch (Throwable $e) {
                dump(
                    sprintf(
                        'Attempt %d/%d failed for API method %s. Error: %s (%s:%d)',
                        $currentRetry + 1,
                        $retries,
                        $method,
                        $e->getMessage(),
                        $e->getFile(),
                        $e->getLine()
                    )
                );

                // Если пользователь заблочил бота, не повторяем сообщение
                $errorMessage = strtolower($e->getMessage());
                if (str_contains($errorMessage, 'blocked')
                    || str_contains($errorMessage, 'deactivated')
                    || str_contains($errorMessage, 'forbidden')
                    || str_contains($errorMessage, 'not found')
                    || str_contains($errorMessage, 'message is not modified')
                    || str_contains($errorMessage, 'can\'t parse entities')
                ) {
                    break;
                }

                if ($currentRetry + 1 >= $retries) {
                    // Don't delay if this was the last attempt
                    break;
                }

                try {
                    async(fn () => delay(2 ** $currentRetry))->await();
                } catch (Throwable $delayError) {
                    dump('Error during retry delay: ' . $delayError->getMessage());

                    break;
                }
            }
        } while (++$currentRetry < $retries);

        throw $e;
    }
}