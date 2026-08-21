<?php

declare(strict_types=1);

namespace Bot\Space\Attention;

use InvalidArgumentException;

final readonly class SpaceResponseDecision
{
    public const string MODE_SILENT      = 'silent';
    public const string MODE_DIRECT      = 'direct';
    public const string MODE_SPONTANEOUS = 'spontaneous';
    public const string MODE_SKILL       = 'skill';
    public const string MODE_BASE        = 'base';

    /** @param list<string> $selectedSkillNames */
    public function __construct(
        public string $mode,
        public array $selectedSkillNames = [],
    ) {
        if (!in_array($mode, [
            self::MODE_SILENT,
            self::MODE_DIRECT,
            self::MODE_SPONTANEOUS,
            self::MODE_SKILL,
            self::MODE_BASE,
        ], true)) {
            throw new InvalidArgumentException('Space response decision mode is invalid.');
        }
        if (!array_is_list($selectedSkillNames) || count($selectedSkillNames) > 2) {
            throw new InvalidArgumentException(
                'Space response decision may select at most two skill names.',
            );
        }
        $seen = [];
        foreach ($selectedSkillNames as $name) {
            if (!is_string($name) || trim($name) === '' || isset($seen[$name])) {
                throw new InvalidArgumentException(
                    'Space response decision skill names must be unique non-empty strings.',
                );
            }
            $seen[$name] = true;
        }
        if ($mode === self::MODE_SILENT && $selectedSkillNames !== []) {
            throw new InvalidArgumentException('A silent decision cannot select skills.');
        }
        if ($mode === self::MODE_SKILL && $selectedSkillNames === []) {
            throw new InvalidArgumentException('A skill decision must select at least one skill.');
        }
    }

    public function runsAgent(): bool
    {
        return $this->mode !== self::MODE_SILENT;
    }

    public function isSpontaneous(): bool
    {
        return in_array($this->mode, [self::MODE_SPONTANEOUS, self::MODE_SKILL], true);
    }
}
