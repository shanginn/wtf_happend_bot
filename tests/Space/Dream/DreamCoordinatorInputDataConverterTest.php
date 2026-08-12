<?php

declare(strict_types=1);

namespace Tests\Space\Dream;

use Bot\Space\Dream\DreamCoordinatorInput;
use Bot\Space\Dream\DreamPolicy;
use Temporal\DataConverter\BinaryConverter;
use Temporal\DataConverter\DataConverter;
use Temporal\DataConverter\JsonConverter;
use Temporal\DataConverter\NullConverter;
use Temporal\DataConverter\ProtoConverter;
use Temporal\DataConverter\ProtoJsonConverter;
use Tests\TestCase;

final class DreamCoordinatorInputDataConverterTest extends TestCase
{
    public function testPolicyRoundTripsThroughTemporalJsonConverter(): void
    {
        $converter = new DataConverter(
            new NullConverter(),
            new BinaryConverter(),
            new ProtoJsonConverter(),
            new ProtoConverter(),
            new JsonConverter(),
        );
        $input = new DreamCoordinatorInput(
            dreamDate: '2026-08-12',
            policy: new DreamPolicy(minimumEvidenceItems: 8, minimumReplayCases: 3),
        );

        $decoded = $converter->fromPayload(
            $converter->toPayload($input),
            DreamCoordinatorInput::class,
        );

        self::assertInstanceOf(DreamCoordinatorInput::class, $decoded);
        self::assertSame(8, $decoded->policy->minimumEvidenceItems);
        self::assertSame(3, $decoded->policy->minimumReplayCases);
        self::assertTrue($decoded->policy->autoPromoteSameAuthority);
    }

    public function testDefaultPolicyIsStableForWorkflowHistory(): void
    {
        $first    = new DreamCoordinatorInput();
        $replayed = new DreamCoordinatorInput();

        self::assertEquals($first->policy, $replayed->policy);
        self::assertSame(6, $replayed->policy->minimumEvidenceItems);
        self::assertSame(6, $replayed->policy->minimumRegressionEvidenceItems);
    }
}
