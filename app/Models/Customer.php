<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class Customer extends Authenticatable
{
    use Notifiable;

    protected $fillable = ['phone', 'name', 'email'];

    protected function casts(): array
    {
        return ['phone_verified_at' => 'datetime'];
    }
}
