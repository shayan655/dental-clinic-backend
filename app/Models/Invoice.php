<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Enums\Role;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Invoice extends Model
{
    protected $fillable = [
        'patient_id',
        'appointment_id',
        'amount',
        'status',
        'issued_at',
        'paid_at'
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'status' => InvoiceStatus::class,
            'issued_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function patient(): BelongsTo {
        return $this->belongsTo(Patient::class);
    }

    public function appointment(): BelongsTo {
        return $this->belongsTo(Appointment::class);
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder {
        return $user->hasRole(Role::SuperAdmin, Role::ClinicManager, Role::Receptionist)
            ? $query
            : $query->whereHas('patient', fn (Builder $q) => $q->where('user_id', $user->id));
    }
}
