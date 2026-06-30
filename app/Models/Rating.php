<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Rating extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'ride_id',
        'driver_id',
        'passenger_id',
        'stars',
        'comment',
        'created_at',
    ];
}
