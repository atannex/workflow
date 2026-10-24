<?php

declare(strict_types=1);

namespace Atannex\Workflow\Guards;

use Atannex\Workflow\Contracts\Guard;
use Atannex\Workflow\Engine\Context;
use Illuminate\Support\Str;

/**
 * "filled:title,category_id": the subject has these attributes (or
 * relations, with "relation.") filled in. Checked for the system too.
 */
final class Filled implements Guard
{
    /**
     * @param  array<int, string>  $attributes
     */
    public function __construct(private readonly array $attributes) {}

    public function check(Context $context): GuardResult
    {
        $missing = [];

        foreach ($this->attributes as $attribute) {
            $value = data_get($context->subject, $attribute);

            if ($value instanceof \Countable ? count($value) === 0 : blank($value)) {
                $missing[] = Str::lower(Str::headline(Str::before($attribute, '_id')));
            }
        }

        return $missing === []
            ? GuardResult::allow()
            : GuardResult::deny(__('Fill in first: :fields.', ['fields' => implode(', ', $missing)]));
    }
}
