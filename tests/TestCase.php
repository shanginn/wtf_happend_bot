<?php

declare(strict_types=1);

namespace Tests;

use Bot\Telegram\Factory;
use Faker\Generator;
use Phenogram\Bindings\Factories\AbstractFactory;

use PHPUnit\Framework\TestCase as BaseTestCase;

class TestCase extends BaseTestCase
{
    private static Generator $faker;
    private static bool $factorySet = false;

    public static function faker(): Generator
    {
        if (!isset(self::$faker)) {
            self::$faker = \Faker\Factory::create();
        }

        return self::$faker;
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Set the custom factory so UpdateFactory produces Bot\Telegram\Update instances
        if (!self::$factorySet) {
            try {
                AbstractFactory::setFactory(new Factory());
            } catch (\RuntimeException) {
                // Already set by another test class
            }
            self::$factorySet = true;
        }
    }
}
