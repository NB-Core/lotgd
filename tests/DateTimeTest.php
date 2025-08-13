<?php

declare(strict_types=1);

namespace Lotgd\Tests;

use Lotgd\DateTime;
use Lotgd\Dhms;
use Lotgd\Settings;
use PHPUnit\Framework\TestCase;

final class DateTimeTest extends TestCase
{
    public function testReadableTimeShortFormat(): void
    {
        $seconds = 2 * 86400 + 3 * 3600 + 5;
        $this->assertSame('2d3h', DateTime::readableTime($seconds));
    }

    public function testReadableTimeLongFormat(): void
    {
        $seconds = 2 * 86400 + 3 * 3600 + 5;
        $this->assertSame('2 days, 3 hours', DateTime::readableTime($seconds, false));
    }

    public function testReadableTimeAcceptsFloat(): void
    {
        $seconds = 61.7; // 1 minute and 1 second (fraction ignored)
        $this->assertSame('1m1s', DateTime::readableTime($seconds));
    }

    public function testRelTimeShortFormat(): void
    {
        $diff = 3600 + 60; // 1 hour and 1 minute
        $timestamp = time() - $diff;
        $this->assertSame('1h1m', DateTime::relTime($timestamp));
    }

    public function testDateDifference(): void
    {
        $date1 = '2023-01-10';
        $date2 = '2023-01-01';
        $expected = (new \DateTime($date2))->diff(new \DateTime($date1))->format('%R%a');
        $this->assertSame($expected, DateTime::dateDifference($date1, $date2));
        $this->assertSame('-9', DateTime::dateDifference($date2, $date1));
    }

    public function testDateDifferenceEvents(): void
    {
        $event = '01-15';
        $year = (int) date('Y');

        $diff1 = (int) DateTime::dateDifference("$year-$event");
        $diff2 = (int) DateTime::dateDifference(($year + 1) . "-$event");
        $diff3 = (int) DateTime::dateDifference(($year - 1) . "-$event");

        if (abs($diff1) < abs($diff2) && abs($diff1) < abs($diff3)) {
            $expected = $diff1;
        } elseif (abs($diff2) < abs($diff1) && abs($diff2) < abs($diff3)) {
            $expected = $diff2;
        } else {
            $expected = $diff3;
        }

        $this->assertSame($expected, DateTime::dateDifferenceEvents($event));
        $this->assertSame(abs($expected), DateTime::dateDifferenceEvents($event, true));
    }

    public function testDhmsFormat(): void
    {
        $this->assertSame('1d1h1m1s', Dhms::format(90061));
        $this->assertSame('0d1h1m1.5s', Dhms::format(3661.5, true));
    }
}
