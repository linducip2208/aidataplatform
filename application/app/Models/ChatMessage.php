<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'chat_thread_id',
        'role',
        'content',
        'evidence',
        'steps',
    ];

    protected function casts(): array
    {
        return [
            'evidence' => 'array',
            'steps' => 'integer',
        ];
    }

    /** @return BelongsTo<ChatThread, $this> */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(ChatThread::class, 'chat_thread_id');
    }
}
