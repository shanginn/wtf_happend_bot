<?php

declare(strict_types=1);

namespace Tests\Activity;

use Bot\Activity\DatabaseActivity;
use Cycle\ORM\ORMInterface;
use Mockery;
use Tests\TestCase;
use ReflectionMethod;

class DatabaseActivityTest extends TestCase
{
    private DatabaseActivity $activity;
    private ReflectionMethod $isSimilar;

    protected function setUp(): void
    {
        parent::setUp();

        $orm = Mockery::mock(ORMInterface::class);
        $this->activity = new DatabaseActivity($orm);
        $this->isSimilar = new ReflectionMethod(DatabaseActivity::class, 'isSimilar');
    }

    public function testIsSimilarExactMatch(): void
    {
        self::assertTrue($this->isSimilar->invoke($this->activity, 'hello world', 'hello world'));
    }

    public function testIsSimilarSubstring(): void
    {
        self::assertTrue($this->isSimilar->invoke($this->activity, 'hello world', 'hello'));
        self::assertTrue($this->isSimilar->invoke($this->activity, 'hello', 'hello world'));
    }

    public function testIsSimilarCaseInsensitive(): void
    {
        self::assertTrue($this->isSimilar->invoke($this->activity, 'Hello World', 'hello world'));
    }

    public function testIsSimilarDifferentStrings(): void
    {
        self::assertFalse($this->isSimilar->invoke($this->activity, 'apples', 'oranges'));
    }

    public function testIsSimilarCloseEditDistance(): void
    {
        // Very similar strings (typo correction)
        self::assertTrue($this->isSimilar->invoke($this->activity, 'software engineer', 'software enginear'));
    }

    // --- getCurrentTime ---

    public function testGetCurrentTimeUTC(): void
    {
        $result = $this->activity->getCurrentTime('UTC');
        self::assertStringContainsString('UTC', $result);
        self::assertMatchesRegularExpression('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', $result);
    }

    public function testGetCurrentTimeMoscow(): void
    {
        $result = $this->activity->getCurrentTime('Europe/Moscow');
        self::assertStringContainsString('Europe/Moscow', $result);
    }

    public function testGetCurrentTimeInvalidTimezone(): void
    {
        $result = $this->activity->getCurrentTime('Not/A/Timezone');
        self::assertStringContainsString('Unknown timezone', $result);
    }

    public function testGetCurrentTimeIncludesDayOfWeek(): void
    {
        $result = $this->activity->getCurrentTime('UTC');
        $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
        $containsDay = false;
        foreach ($days as $day) {
            if (str_contains($result, $day)) {
                $containsDay = true;
                break;
            }
        }
        self::assertTrue($containsDay, "Result should contain day of week: {$result}");
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
