<?php

declare(strict_types=1);

namespace Bot\Space\Workflow;

use Bot\Telegram\Update;
use InvalidArgumentException;
use Phenogram\Bindings\Types\Interfaces\MessageEntityInterface;

/**
 * A Telegram bot_command entity resolved from the start of one message.
 * The command name is transport data only; release binding happens later
 * against the immutable runtime snapshot for the batch.
 */
final readonly class SpaceCommandInvocation
{
    public string $name;
    public string $argumentText;
    public ?string $targetUsername;

    public function __construct(
        string $name,
        string $argumentText = '',
        ?string $targetUsername = null,
    ) {
        $name = strtolower(trim($name));
        if (preg_match('/\A[a-z0-9_]{1,32}\z/D', $name) !== 1) {
            throw new InvalidArgumentException('A Space command name must be canonical Telegram syntax.');
        }

        $this->name         = $name;
        $this->argumentText = trim($argumentText);
        $targetUsername     = $targetUsername === null
            ? null
            : strtolower(trim($targetUsername));
        if (
            $targetUsername !== null
            && preg_match('/\A[a-z0-9_]{5,64}\z/D', $targetUsername) !== 1
        ) {
            throw new InvalidArgumentException('A targeted Space command needs a valid bot username.');
        }
        $this->targetUsername = $targetUsername;
    }

    public static function fromUpdate(Update $update): ?self
    {
        $message  = $update->effectiveMessage;
        $text     = $message?->text;
        $entities = $message?->entities;
        if (!is_string($text) || $text === '' || !is_array($entities)) {
            return null;
        }

        foreach ($entities as $entity) {
            if (
                !$entity instanceof MessageEntityInterface
                || $entity->type !== 'bot_command'
                || $entity->offset !== 0
                || $entity->length < 2
            ) {
                continue;
            }

            $token = self::utf16Prefix($text, $entity->length);
            if (preg_match(
                '/\A\/([a-z0-9_]{1,32})(?:@([a-z0-9_]{5,64}))?\z/Di',
                $token,
                $matches,
            ) !== 1) {
                return null;
            }

            return new self(
                name: strtolower($matches[1]),
                argumentText: substr($text, strlen($token)),
                // Keep the suffix in durable workflow state. The workflow
                // compares it with the trusted username resolved by the host.
                targetUsername: isset($matches[2]) ? strtolower($matches[2]) : null,
            );
        }

        return null;
    }

    public function isForBot(string $botUsername): bool
    {
        $botUsername = strtolower(trim($botUsername));
        if (preg_match('/\A[a-z0-9_]{5,64}\z/D', $botUsername) !== 1) {
            throw new InvalidArgumentException('Trusted bot username is invalid.');
        }

        return $this->targetUsername === null
            || hash_equals($botUsername, $this->targetUsername);
    }

    private static function utf16Prefix(string $text, int $codeUnits): string
    {
        $utf16 = mb_convert_encoding($text, 'UTF-16LE', 'UTF-8');
        $slice = substr($utf16, 0, $codeUnits * 2);

        return mb_convert_encoding($slice, 'UTF-8', 'UTF-16LE');
    }
}
