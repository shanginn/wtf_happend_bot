<?php

declare(strict_types=1);

namespace Tests\Space\Dream;

use Bot\Space\Dream\DreamPolicy;
use InvalidArgumentException;
use Tests\TestCase;

final class DreamPolicyTest extends TestCase
{
    public function testPolicyRequiresBoundedPositiveLimits(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new DreamPolicy(maximumEdits: 0);
    }

    public function testSafeAutomaticPromotionIsEnabledByDefault(): void
    {
        $policy = new DreamPolicy();

        self::assertTrue($policy->autoPromoteSameAuthority);
        self::assertSame(4, $policy->maximumEdits);
        self::assertSame(72, $policy->lookbackHours);
    }
}
