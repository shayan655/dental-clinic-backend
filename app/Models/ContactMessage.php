<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Override;

class ContactMessage extends Model
{
    protected $fillable = ['name', 'email', 'phone', 'message'];

    protected function casts()
    {
        return ['read_at' => 'datetime'];
    }
}
