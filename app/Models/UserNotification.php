<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Something a user should know about: type is info, success, alert or danger.
 * notification_meta_id is the notification meta that sent it, if one did (see Notifier).
 * email_status is null when it wasn't emailed, else queued, sent or failed (email_error says why).
 */
class UserNotification extends Model
{
    public const TYPES = ['info', 'success', 'alert', 'danger'];

    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'notification_meta_id', 'type', 'title', 'body', 'link', 'read_at'];

    protected $casts = ['read_at' => 'datetime', 'emailed_at' => 'datetime'];
}
