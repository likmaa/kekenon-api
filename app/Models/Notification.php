<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Notification extends Model
{
    protected $fillable = [
        'title',
        'message',
        'type',
        'target',
        'channels',
    ];

    protected $casts = [
        'channels' => 'array',
    ];
}
