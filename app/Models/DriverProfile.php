<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DriverProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'vehicle_number',
        'license_number',
        'vehicle_make',
        'vehicle_model',
        'vehicle_year',
        'vehicle_color',
        'license_plate',
        'vehicle_type',
        'photo',
        'status',
        'documents',
        'contract_accepted_at',
        'subscription_remaining_rides',
    ];

    protected $casts = [
        'documents' => 'array',
        'contract_accepted_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
