<?php

declare(strict_types=1);

namespace Atannex\Workflow\Definition;

use Atannex\Workflow\Engine\Context;
use Atannex\Workflow\Support\Duration;
use Carbon\CarbonInterface;
use Closure;
use DateInterval;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * Takes a transition (as the system) a while after a state is entered, or at
 * a moment read from the subject (e.g. its publication date).
 */
final class Timer
{
    private function __construct(
        public readonly string $transition,
        private readonly int|string|DateInterval|null $after,
        private readonly ?Closure $at,
    ) {}

    public static function after(int|string|DateInterval $after, string $transition): self
    {
        return new self($transition, $after, null);
    }

    /**
     * @param  Closure(Context): (DateTimeInterface|string|null)  $at
     */
    public static function at(Closure $at, string $transition): self
    {
        return new self($transition, null, $at);
    }

    public function dueAt(Context $context, CarbonInterface $enteredAt): ?CarbonInterface
    {
        if ($this->after !== null) {
            return Duration::after($enteredAt, $this->after);
        }

        $moment = ($this->at)($context);

        return match (true) {
            $moment instanceof DateTimeInterface => Carbon::instance($moment),
            is_string($moment) && $moment !== '' => Carbon::parse($moment),
            default => null,
        };
    }
}
