<?php

namespace App\Rules;

use App\Models\Job;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class JobHasOneOwner implements ValidationRule
{
    /**
     * Indicates whether the rule should be implicit.
     *
     * @var bool
     */
    public $implicit = true;

    public function __construct(private Job $job) {}

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $ownerless = $value === null && $this->job->user_id === null;
        $ownedByBoth = $value !== null && $this->job->user_id !== null;

        if ($ownerless || $ownedByBoth) {
            $fail('A job must belong to exactly one of a team or a user.');
        }
    }
}
