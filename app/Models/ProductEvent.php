<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['user_id', 'event_type', 'app_type', 'meta', 'created_at'];

    protected $casts = [
        'meta' => 'array',
        'created_at' => 'datetime',
    ];
}
