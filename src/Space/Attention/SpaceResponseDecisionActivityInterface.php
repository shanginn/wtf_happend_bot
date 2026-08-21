<?php

declare(strict_types=1);

namespace Bot\Space\Attention;

use Temporal\Activity\ActivityInterface;
use Temporal\Activity\ActivityMethod;

#[ActivityInterface]
interface SpaceResponseDecisionActivityInterface
{
    public const string DECIDE = 'SpaceAttention.decide';

    #[ActivityMethod(name: self::DECIDE)]
    public function decide(SpaceResponseDecisionInput $input): SpaceResponseDecision;
}
