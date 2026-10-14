<?php

namespace App\Models;

use App\Enums\Role;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Override;

class Patient extends Model
{
    protected $fillable = ['name', 'email', 'phone', 'date_of_birth'];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date:Y-m-d',
        ];
    }

    public function user(): BelongsTo {
        return $this->belongsTo(User::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return match (true) {
            $user->hasRole(Role::SuperAdmin, Role::ClinicManager, Role::Receptionist) => $query,
            $user->hasRole(Role::Doctor) => $query->whereHas(
                'appointments',
                fn (Builder $q) => $q->where('doctor_id', $user->id)
            ),
            default => $query->where('user_id', $user->id),
        };
    }
}
