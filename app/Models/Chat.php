<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A messenger chat: direct (two users, one chat per pair through direct_key) or a named group.
 * Members carry how far they have read (last_read_message_id) and when they last typed.
 */
class Chat extends Model
{
    public const DIRECT = 'direct';

    public const GROUP = 'group';

    /** Someone counts as typing for this long after their last keystroke ping. */
    public const TYPING_SECONDS = 5;

    protected $fillable = ['type', 'name', 'direct_key', 'created_by', 'last_message_at'];

    protected function casts(): array
    {
        return ['last_message_at' => 'datetime'];
    }

    public static function directKey(int $a, int $b): string
    {
        return min($a, $b).':'.max($a, $b);
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'chat_members')->withPivot(['last_read_message_id', 'typing_at'])->withTimestamps();
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class);
    }

    public function hasMember(User $user): bool
    {
        return $this->members()->whereKey($user->id)->exists();
    }

    /** Adds a message (system ones say who joined, left or renamed) and moves the chat to the top. */
    public function post(?User $user, string $body, string $kind = 'text'): ChatMessage
    {
        $message = $this->messages()->create(['user_id' => $user?->id, 'body' => $body, 'kind' => $kind]);
        $this->forceFill(['last_message_at' => $message->created_at])->save();
        if ($user) {
            // Writing a message means having read the chat up to it, and no longer typing.
            $this->members()->updateExistingPivot($user->id, ['last_read_message_id' => $message->id, 'typing_at' => null]);
        }

        return $message;
    }
}
