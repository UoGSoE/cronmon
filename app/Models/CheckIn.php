<?php

namespace App\Models;

use Database\Factories\CheckInFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use Illuminate\Support\Number;

class CheckIn extends Model
{
    /** @use HasFactory<CheckInFactory> */
    use HasFactory;

    protected $fillable = [
        'job_id',
        'checked_in_at',
        'source_ip',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'checked_in_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(Job::class);
    }

    /**
     * Every metadata key used by any of the given check-ins, alphabetically.
     *
     * @param  Collection<int, CheckIn>  $checkIns
     * @return Collection<int, string>
     */
    public static function metadataKeysAcross(Collection $checkIns): Collection
    {
        return $checkIns
            ->flatMap(fn (CheckIn $checkIn) => array_keys($checkIn->metadata ?? []))
            ->unique()
            ->sort()
            ->values();
    }

    public function metadataForDisplay(string $key): string
    {
        $value = $this->metadata[$key] ?? null;

        if (is_int($value) || is_float($value)) {
            return Number::format($value);
        }

        return $value ?? '—';
    }
}
