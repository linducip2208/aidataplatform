<?php

namespace Database\Factories;

use App\Models\ChatMessage;
use App\Models\ChatThread;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChatMessage>
 */
class ChatMessageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'chat_thread_id' => ChatThread::factory(),
            'role' => 'user',
            'content' => fake()->paragraph(),
            'evidence' => null,
            'steps' => 0,
        ];
    }

    /**
     * Attach the message to an existing thread.
     */
    public function forThread(ChatThread $thread): static
    {
        return $this->state(fn (array $attributes) => [
            'chat_thread_id' => $thread->getKey(),
        ]);
    }

    /**
     * Indicate that the message was written by the analyst.
     */
    public function fromUser(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'user',
            'evidence' => null,
            'steps' => 0,
        ]);
    }

    /**
     * Indicate that the message was produced by the AI agent, with tool evidence.
     *
     * @param  list<array<string, mixed>>  $evidence
     */
    public function fromAssistant(array $evidence = []): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'assistant',
            'evidence' => $evidence === [] ? null : $evidence,
            'steps' => $evidence === [] ? 0 : fake()->numberBetween(1, 3),
        ]);
    }
}
