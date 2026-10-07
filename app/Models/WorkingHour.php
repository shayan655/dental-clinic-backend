<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Override;

class WorkingHour extends Model
{
    public const DAYS = [
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
        7 => 'Sunday',
    ];

    protected $fillable = ['is_open', 'open_time', 'close_time'];

    #[Override]
    protected function casts(): array
    {
        return ['is_open' => 'boolean'];
    }
}
