<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PassengerInboxNotification extends Model
{
    protected $table = 'passenger_inbox_notifications';

    protected $fillable = [
        'user_id',
        'admin_notification_id',
        'title',
        'message',
        'type',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
