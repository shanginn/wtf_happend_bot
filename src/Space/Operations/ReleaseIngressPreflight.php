<?php

declare(strict_types=1);

namespace Bot\Space\Operations;

use Cycle\Database\DatabaseInterface;
use Phenogram\Bindings\ApiInterface;
use RuntimeException;

final readonly class ReleaseIngressPreflight
{
    public function __construct(
        private DatabaseInterface $database,
        private ApiInterface $telegram,
    ) {}

    /** @return array{database: string, telegramBotId: int} */
    public function run(): array
    {
        $database = $this->database->query('SELECT 1 AS ready')->fetch();
        if (!is_array($database) || (int) ($database['ready'] ?? 0) !== 1) {
            throw new RuntimeException('Ingress preflight could not verify the primary database.');
        }

        $bot = $this->telegram->getMe();
        if (!$bot->isBot || $bot->id <= 0) {
            throw new RuntimeException('Ingress preflight Telegram credentials do not identify a bot.');
        }

        return [
            'database'      => 'ready',
            'telegramBotId' => $bot->id,
        ];
    }
}
