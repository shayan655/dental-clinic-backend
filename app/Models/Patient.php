<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
}
