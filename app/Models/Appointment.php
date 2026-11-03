<?php

namespace App\Models;

use App\Enums\AppointmentStatus;
use App\Enums\Role;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Override;

class Appointment extends Model
{
    protected $fillable = [
        'patient_id', 'doctor_id', 'service_id',
        'duration_minutes', 'status', 'scheduled_at', 'notes',
    ];

    #[Override]
    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'duration_minutes' => 'integer',
            'status' => AppointmentStatus::class,
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'doctor_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class)->withTrashed();
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return match (true) {
            $user->hasRole(Role::SuperAdmin, Role::ClinicManager, Role::Receptionist) => $query,
            $user->hasRole(Role::Doctor) => $query->where('doctor_id', $user->id),
            default => $query->whereHas('patient', fn (Builder $q) => $q->where('user_id', $user->id)),
        };
    }

    public function medicalRecord(): HasOne {
        return $this->hasOne(MedicalRecord::class)->latestOfMany();
    }
}
