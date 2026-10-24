<?php

declare(strict_types=1);

namespace Atannex\Workflow\Support;

use Carbon\CarbonInterface;
use Carbon\CarbonInterval;
use DateInterval;
use InvalidArgumentException;

/**
 * Durations in definitions: seconds (int), "36 hours", "2 days 4 hours",
 * an ISO 8601 period ("P1DT2H") or a DateInterval.
 */
final class Duration
{
    public static function seconds(int|string|DateInterval $duration): int
    {
        if (is_int($duration)) {
            return max(0, $duration);
        }

        if (is_string($duration) && ctype_digit($duration)) {
            return (int) $duration;
        }

        try {
            $interval = $duration instanceof DateInterval
                ? CarbonInterval::instance($duration)
                : (str_starts_with($duration, 'P') ? new CarbonInterval($duration) : CarbonInterval::fromString($duration));
        } catch (\Throwable $e) {
            throw new InvalidArgumentException("Cannot read the duration [{$duration}].", 0, $e);
        }

        return (int) round($interval->totalSeconds);
    }

    public static function after(CarbonInterface $from, int|string|DateInterval $duration): CarbonInterface
    {
        return $from->copy()->addSeconds(self::seconds($duration));
    }

    public static function human(int $seconds): string
    {
        return CarbonInterval::seconds($seconds)->cascade()->forHumans(['short' => true, 'parts' => 2]);
    }
}
