<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One message in a chat; kind system is a line the messenger wrote itself (joined, left, renamed). */
class ChatMessage extends Model
{
    protected $fillable = ['chat_id', 'user_id', 'kind', 'body'];

    public function chat(): BelongsTo
    {
        return $this->belongsTo(Chat::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
