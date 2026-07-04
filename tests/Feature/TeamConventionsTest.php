<?php

/*
 * Team conventions, enforced as Pest architecture tests. The canonical copy
 * lives in the quality-gate skill (~/.claude/skills/quality-gate/arch/);
 * install by copying into an app's tests/Feature/ so it runs on every
 * `php artisan test` - violations go red while the code is being written.
 *
 * On a legacy app, adopt with ignoring() on current offenders and burn the
 * list down over time.
 *
 * Deliberately absent:
 *  - No DB-facade ban: DB::transaction() is sanctioned and toUse() is
 *    class-level, so the exception can't be expressed here. The generation-time
 *    hook and the laravel-conventions-reviewer agent carry that rule instead.
 *  - No Controller-suffix rule: invokable controllers named for their action
 *    (e.g. DownloadRecordPatchScript) are house style.
 */

// App-specific sanctioned exceptions: add ->ignoring('Some\Class') to the
// relevant rule IN THE INSTALLED COPY, with a comment saying why. Keep this
// canonical copy generic.

arch('no debug or deprecated functions')
    ->preset()->php();

arch('no insecure functions')
    ->preset()->security()
    // dev fixtures don't need cryptographic randomness (rand() for fake dates)
    ->ignoring('Database\Seeders');

arch('enums are actual enums')
    ->expect('App\Enums')
    ->toBeEnums();

arch('models are classes extending Eloquent')
    ->expect('App\Models')
    ->toBeClasses()
    ->toExtend('Illuminate\Database\Eloquent\Model');

arch('strict equality everywhere')
    ->expect('App')
    ->toUseStrictEquality();

arch('no ray() debugging left behind')
    ->expect('App')
    ->not->toUse('ray');

arch('models stay a readable size')
    ->expect('App\Models')
    ->toHaveLineCountLessThan(500);

arch('livewire components stay a readable size')
    ->expect('App\Livewire')
    ->toHaveLineCountLessThan(700);
