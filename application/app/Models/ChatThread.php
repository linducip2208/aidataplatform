<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChatThread extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'ai_conversation_id',
        'message_count',
        'last_message_at',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
            'ai_conversation_id' => 'integer',
            'message_count' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<ChatMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class);
    }
}
