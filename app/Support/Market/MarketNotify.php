<?php

namespace App\Support\Market;

use App\Models\User;
use App\Models\UserNotification;

/** Tells a user about something on the market, in their notifications. */
class MarketNotify
{
    /** @param  string  $type  info, success, alert or danger */
    public static function send(User $to, string $title, ?string $body = null, ?string $link = null, string $type = 'info'): void
    {
        UserNotification::create([
            'user_id' => $to->id,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'link' => $link,
        ]);
    }
}
