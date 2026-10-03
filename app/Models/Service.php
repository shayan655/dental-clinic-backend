<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Override;

class Service extends Model
{
    use SoftDeletes;

    protected $fillable = ['name', 'description', 'duration_minutes', 'price'];

    #[Override]
    protected function casts(): array
    {
        return [
            'duration_minutes' => 'integer',
            'price' => 'decimal:2',
        ];
    }
}
