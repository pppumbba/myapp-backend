<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One message in a chat; kind system is a line the messenger wrote itself (joined, left, renamed),
 * kind record a shared grid row whose card is in data (the body is the note sent with it).
 */
class ChatMessage extends Model
{
    protected $fillable = ['chat_id', 'user_id', 'kind', 'body', 'data'];

    protected $casts = ['data' => 'array'];

    public function chat(): BelongsTo
    {
        return $this->belongsTo(Chat::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
