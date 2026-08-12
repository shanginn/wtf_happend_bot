<?php

declare(strict_types=1);

namespace Tests\Space\Persistence;

use Bot\Space\Memory\SpaceMemoryContentPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class SpaceMemoryContentPolicyTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function privateData(): iterable
    {
        yield 'api token' => ['api_key=very-secret-key'];
        yield 'email' => ['Reach me at private@example.com'];
        yield 'phone' => ['My phone is +1 (415) 555-2671'];
        yield 'medical' => ['The participant was diagnosed with diabetes.'];
        yield 'payment card' => ['4111 1111 1111 1111'];
        yield 'Russian password' => ['пароль: supersecret123'];
        yield 'Russian secret' => ['секрет = abcdefghi'];
        yield 'Russian token' => ['токен: abcdefghi'];
        yield 'Russian API key' => ['api ключ = abcdefghi'];
        yield 'Russian diagnosis' => ['У участника диагноз рак.'];
        yield 'Russian HIV status' => ['Участник сообщил про ВИЧ.'];
        yield 'Russian diabetes' => ['У участника диабет.'];
        yield 'Russian pregnancy' => ['Участница беременна.'];
        yield 'Russian medical record' => ['Это медицинская информация.'];
    }

    #[DataProvider('privateData')]
    public function testPrivateDataIsRejectedDeterministically(string $value): void
    {
        self::assertNotSame([], SpaceMemoryContentPolicy::violations($value));
    }

    public function testOrdinaryDurablePreferenceIsAllowed(): void
    {
        self::assertSame([], SpaceMemoryContentPolicy::violations(
            'The participant prefers concise Russian replies.',
            'Отвечай короче, пожалуйста.',
            'Stable response-style preference.',
        ));
    }
}
