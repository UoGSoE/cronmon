<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;

#[Fillable([
    'username',
    'forenames',
    'surname',
    'email',
    'password',
    'notification_email',
    'sender_email',
    'silenced_until',
    'silence_reason',
    'is_admin',
    'is_staff',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'silenced_until' => 'datetime',
            'is_admin' => 'boolean',
            'is_staff' => 'boolean',
        ];
    }

    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class);
    }

    public function jobs(): HasMany
    {
        return $this->hasMany(Job::class);
    }

    public function getFullNameAttribute(): string
    {
        return $this->forenames.' '.$this->surname;
    }

    public function silenceUntil(Carbon $until, ?string $reason = null): void
    {
        $this->update([
            'silenced_until' => $until,
            'silence_reason' => $reason,
        ]);
    }

    public function unsilence(): void
    {
        $this->update([
            'silenced_until' => null,
            'silence_reason' => null,
        ]);
    }

    public function isCurrentlySilenced(): bool
    {
        return (bool) $this->silenced_until?->isFuture();
    }

    public function transferPersonalJobsTo(User $recipient): void
    {
        $this->jobs()->update(['user_id' => $recipient->id]);
    }

    public function reassignAuthoredJobsTo(User $fallback): void
    {
        Job::where('created_by_user_id', $this->id)
            ->where(fn ($query) => $query->whereNotNull('team_id')->orWhere('user_id', '!=', $this->id))
            ->update(['created_by_user_id' => $fallback->id]);
    }
}
