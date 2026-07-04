<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class NotDemotingSelf implements ValidationRule
{
    public function __construct(private bool $editingSelf) {}

    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->editingSelf && ! $value) {
            $fail('You cannot demote yourself.');
        }
    }
}
