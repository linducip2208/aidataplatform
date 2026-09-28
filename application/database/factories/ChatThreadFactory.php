<?php

namespace Database\Factories;

use App\Models\ChatThread;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChatThread>
 */
class ChatThreadFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $lastMessageAt = fake()->dateTimeBetween('-14 days', 'now');

        return [
            'title' => fake()->randomElement([
                'Analisis penjualan mingguan',
                'Kesehatan stok dan risiko stockout',
                'Segmentasi pelanggan RFM',
                'Proyeksi permintaan produk',
                'Ringkasan biaya operasional',
                'Performa cabang bulan ini',
            ]),
            'ai_conversation_id' => fake()->numberBetween(1, 900),
            'message_count' => fake()->numberBetween(4, 8),
            'last_message_at' => $lastMessageAt,
            'user_id' => User::factory(),
        ];
    }

    /**
     * Attribute the thread to the given user.
     */
    public function forUser(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $user->getKey(),
        ]);
    }

    /**
     * Set the title and keep the thread counters consistent with the message count.
     */
    public function titled(string $title, int $messageCount, ?\DateTimeInterface $lastMessageAt = null): static
    {
        return $this->state(fn (array $attributes) => [
            'title' => $title,
            'message_count' => $messageCount,
            'last_message_at' => $lastMessageAt ?? now(),
        ]);
    }
}
