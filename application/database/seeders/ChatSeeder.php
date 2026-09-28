<?php

namespace Database\Seeders;

use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Creates three demo assistant conversations for `analyst@example.com` so the chat list and
 * thread pages are not empty on a fresh install. Each thread holds 4-8 alternating user /
 * assistant messages in Indonesian, and every assistant message carries an `evidence` array in
 * the shape of the AI engine's `EvidenceRow` (`[['source' => ..., 'data' => [...]]]`), so the
 * answer detail pages can render the tool citations.
 *
 * Threads: "Analisis penjualan Q1 2026" (6 messages), "Risiko stockout gudang pusat"
 * (4 messages), "Segmentasi pelanggan dan program retensi" (8 messages).
 * `message_count` and `last_message_at` are always recalculated from the messages that were
 * actually written. The seeder is idempotent: a thread matched on user + title is reused and its
 * messages are only written when the thread is still empty, so repeated `db:seed --force` runs
 * never duplicate conversations. No credentials are created here; `UserSeeder` runs first.
 */
class ChatSeeder extends Seeder
{
    public function run(): void
    {
        $analyst = $this->demoUser('analyst@example.com');

        foreach ($this->definitions() as $definition) {
            $startedAt = Carbon::parse($definition['started_at']);

            $thread = ChatThread::firstOrCreate(
                [
                    'user_id' => $analyst->getKey(),
                    'title' => $definition['title'],
                ],
                [
                    'ai_conversation_id' => $definition['conversation_id'],
                    'message_count' => 0,
                    'last_message_at' => $startedAt,
                ],
            );

            if (! $thread->wasRecentlyCreated) {
                $thread->forceFill([
                    'created_at' => $startedAt,
                    'updated_at' => $startedAt,
                ])->save();
            }

            if ($thread->messages()->exists()) {
                continue;
            }

            $lastMessageAt = $startedAt;

            foreach ($definition['messages'] as $index => $payload) {
                $at = $index === 0
                    ? $startedAt->copy()
                    : $startedAt->copy()->addMinutes($index * 2);

                $this->createMessage($thread, $payload, $at);
                $lastMessageAt = $at;
            }

            $thread->forceFill([
                'message_count' => count($definition['messages']),
                'last_message_at' => $lastMessageAt,
            ])->save();
        }
    }

    /**
     * Resolve a demo user, seeding the demo accounts first when they are missing.
     */
    protected function demoUser(string $email): User
    {
        $user = User::where('email', $email)->first();

        if (! $user instanceof User) {
            $this->call(UserSeeder::class);
            $user = User::where('email', $email)->firstOrFail();
        }

        return $user;
    }

    /**
     * @param  array{role: string, content: string, evidence?: list<array<string, mixed>>, steps?: int}  $payload
     */
    protected function createMessage(ChatThread $thread, array $payload, Carbon $at): ChatMessage
    {
        $message = new ChatMessage([
            'chat_thread_id' => $thread->getKey(),
            'role' => $payload['role'],
            'content' => $payload['content'],
            'evidence' => $payload['evidence'] ?? null,
            'steps' => $payload['steps'] ?? 0,
        ]);

        $message->created_at = $at;
        $message->updated_at = $at;
        $message->save();

        return $message;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function definitions(): array
    {
        return [
            [
                'title' => 'Analisis penjualan Q1 2026',
                'conversation_id' => 7001,
                'started_at' => '2026-09-22 09:12:00',
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => 'Berapa omzet penjualan kita di kuartal 1 2026 dan seberapa pertumbuhannya dibanding paruh sebelumnya?',
                    ],
                    [
                        'role' => 'assistant',
                        'content' => 'Omzet Q1 2026 mencapai Rp 1.284.500.000 dari 41.230 transaksi dengan 96.340 unit terjual. Rata-rata nilai per transaksi Rp 31.143,65. Paruh kedua lebih baik 8,42% dibanding paruh pertama, jadi trennya positif. Angka ini dihitung dari fact_sales, yang menghimpun 3 dataset committed: 18.420 baris penjualan, 1.860 baris stok, dan 3.240 baris pesanan pembelian.',
                        'evidence' => [
                            [
                                'source' => 'warehouse.fact_sales',
                                'data' => [
                                    'revenue' => 1284500000.0,
                                    'orders' => 41230,
                                    'units' => 96340.0,
                                    'aov' => 31143.65,
                                    'growth_pct' => 8.42,
                                    'margin_pct' => 0.0,
                                ],
                            ],
                        ],
                        'steps' => 2,
                    ],
                    [
                        'role' => 'user',
                        'content' => 'Produk apa yang paling besar kontribusinya dan produk mana yang mulai melambat?',
                    ],
                    [
                        'role' => 'assistant',
                        'content' => 'Analisis ABC menunjukkan 5 produk kelas A menyumbang 38% omzet. Minyak Goreng 1L (P-000231) adalah penyumbang terbesar dengan porsi 9,4%. Di sisi lain, 3 produk kategori Minuman masuk daftar perlambatan: kontribusi turun dan frekuensi pembelian pelanggan turun lebih dari 20% dibanding rata-rata 90 hari sebelumnya.',
                        'evidence' => [
                            [
                                'source' => 'warehouse.fact_sales:abc',
                                'data' => [
                                    ['product' => 'Minyak Goreng 1L', 'revenue' => 120743000.0, 'share_pct' => 9.4, 'cumulative_pct' => 9.4, 'class' => 'A'],
                                    ['product' => 'Beras Premium 5kg', 'revenue' => 96420000.0, 'share_pct' => 7.51, 'cumulative_pct' => 16.91, 'class' => 'A'],
                                    ['product' => 'Gula Pasir 1kg', 'revenue' => 64260000.0, 'share_pct' => 5.0, 'cumulative_pct' => 21.91, 'class' => 'A'],
                                    ['product' => 'Minyak Goreng 2L', 'revenue' => 51320000.0, 'share_pct' => 3.99, 'cumulative_pct' => 25.9, 'class' => 'A'],
                                    ['product' => 'Teh Celup 25s', 'revenue' => 41070000.0, 'share_pct' => 3.2, 'cumulative_pct' => 29.1, 'class' => 'A'],
                                ],
                            ],
                        ],
                        'steps' => 3,
                    ],
                    [
                        'role' => 'user',
                        'content' => 'Buatkan ringkasan singkat yang bisa saya jadikan bahan rapat mingguan.',
                    ],
                    [
                        'role' => 'assistant',
                        'content' => "Ringkasan rapat mingguan:\n1. Omzet Q1 2026 Rp 1,28 miliar, naik 8,42% dari paruh pertama.\n2. Lima produk kelas A menyumbang 38% omzet; jaga ketersediaan Minyak Goreng 1L dan Beras Premium 5kg.\n3. Tiga produk kategori Minuman melambat; tinjau harga dan promo.\n4. Kualitas data: dataset penjualan lolos dengan skor 0,94, sedangkan dataset biaya operasional terkarantina dengan skor 0,42 dan belum boleh dipakai untuk laporan.\n5. Master pelanggan masih diimpor, sehingga angka RFM belum final.",
                        'evidence' => [
                            [
                                'source' => 'warehouse.fact_sales',
                                'data' => [
                                    'revenue' => 1284500000.0,
                                    'orders' => 41230,
                                    'units' => 96340.0,
                                    'aov' => 31143.65,
                                    'growth_pct' => 8.42,
                                    'margin_pct' => 0.0,
                                ],
                            ],
                            [
                                'source' => 'raw.data_quality_reports',
                                'data' => [
                                    ['dataset' => 'Penjualan Retail Harian 2026', 'score' => 0.9412, 'passed' => true],
                                    ['dataset' => 'Stok Akhir Gudang & Toko', 'score' => 0.9685, 'passed' => true],
                                    ['dataset' => 'Biaya Operasional Toko', 'score' => 0.4216, 'passed' => false],
                                    ['dataset' => 'Master Pelanggan & Segmen', 'score' => null, 'passed' => false],
                                ],
                            ],
                        ],
                        'steps' => 3,
                    ],
                ],
            ],

            [
                'title' => 'Risiko stockout gudang pusat',
                'conversation_id' => 7002,
                'started_at' => '2026-09-25 14:35:00',
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => 'Produk apa yang berisiko habis dalam 7 hari ke depan, dan berapa titik pesan ulang yang harus saya keluarkan?',
                    ],
                    [
                        'role' => 'assistant',
                        'content' => 'Ada 1 produk dengan risiko kritis dan 1 berisiko tinggi. Gula Pasir 1kg hanya tersisa 2,4 hari (stok 21 unit, rata-rata jual 8,7 per hari) sehingga titik pesan ulang wajib keluar hari ini. Teh Celup 25s tersisa 7,4 hari dan masih aman, tetapi sudah masuk zona tinggi. Total permintaan 7 hari untuk seluruh produk berisiko adalah 1.940 unit.',
                        'evidence' => [
                            [
                                'source' => 'warehouse.fact_inventory',
                                'data' => [
                                    ['product' => 'Gula Pasir 1kg', 'stock_qty' => 21.0, 'avg_daily_sales' => 8.7, 'days_of_stock' => 2.41, 'turnover' => 12.43, 'stockout_risk' => 'critical', 'reorder_point' => 60.9, 'dead_stock' => false],
                                    ['product' => 'Teh Celup 25s', 'stock_qty' => 305.0, 'avg_daily_sales' => 41.2, 'days_of_stock' => 7.4, 'turnover' => 4.05, 'stockout_risk' => 'high', 'reorder_point' => 288.4, 'dead_stock' => false],
                                    ['product' => 'Minyak Goreng 1L', 'stock_qty' => 148.0, 'avg_daily_sales' => 18.4, 'days_of_stock' => 8.04, 'turnover' => 3.73, 'stockout_risk' => 'medium', 'reorder_point' => 128.8, 'dead_stock' => false],
                                    ['product' => 'Beras Premium 5kg', 'stock_qty' => 62.0, 'avg_daily_sales' => 6.1, 'days_of_stock' => 10.16, 'turnover' => 2.95, 'stockout_risk' => 'medium', 'reorder_point' => 42.7, 'dead_stock' => false],
                                ],
                            ],
                        ],
                        'steps' => 2,
                    ],
                    [
                        'role' => 'user',
                        'content' => 'Kalau saya mengikuti titik pesan ulang itu, apakah ada produk yang justru menjadi overstock?',
                    ],
                    [
                        'role' => 'assistant',
                        'content' => 'Ya. Jika Teh Celup 25S dipesan sesuai titik pesan ulang penuh (288 unit), stoknya menjadi 593 unit atau 14,4 hari. Angka itu masih aman, tetapi sudah berada di zona overstock. Sebaliknya, ada 12 SKU dead stock: produk tanpa penjualan dalam 30 hari terakhir yang masih menyumbangkan Rp 84 juta nilai persediaan. Rekomendasi: pesan maksimal 2x titik pesan ulang untuk SKU dead stock dan 1,5x untuk SKU berisiko tinggi.',
                        'evidence' => [
                            [
                                'source' => 'warehouse.fact_inventory',
                                'data' => [
                                    ['product' => 'Teh Celup 25s', 'stock_qty' => 593.0, 'avg_daily_sales' => 41.2, 'days_of_stock' => 14.39, 'turnover' => 2.08, 'stockout_risk' => 'low', 'reorder_point' => 288.4, 'dead_stock' => false],
                                    ['product' => 'Mi Instan Goreng 85g (Cold)', 'stock_qty' => 1240.0, 'avg_daily_sales' => 0.0, 'days_of_stock' => 999.0, 'turnover' => 0.0, 'stockout_risk' => 'low', 'reorder_point' => 0.0, 'dead_stock' => true],
                                ],
                            ],
                        ],
                        'steps' => 3,
                    ],
                ],
            ],

            [
                'title' => 'Segmentasi pelanggan dan program retensi',
                'conversation_id' => 7003,
                'started_at' => '2026-09-27 08:05:00',
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => 'Siapa pelanggan terbaik kita menurut RFM, dan berapa banyak pelanggan di tiap segmen?',
                    ],
                    [
                        'role' => 'assistant',
                        'content' => 'Segmentasi RFM atas 5.620 pelanggan menghasilkan 5 segmen. Segmen champion berisi 318 pelanggan dan menyumbang 21,4% nilai moneter, sedangkan segmen at_risk berisi 604 pelanggan yang berpotensi hilang bila tidak ada outreach segera. Catatan penting: dataset master pelanggan masih berstatus importing, sehingga angka ini akan berubah setelah impor selesai.',
                        'evidence' => [
                            [
                                'source' => 'warehouse.fact_sales:rfm',
                                'data' => [
                                    ['customer' => 'CV Sinar Abadi', 'recency_days' => 2, 'frequency' => 184, 'monetary' => 268400000.0, 'r_score' => 5, 'f_score' => 5, 'm_score' => 5, 'segment' => 'champions'],
                                    ['customer' => 'Toko Berkah Jaya', 'recency_days' => 5, 'frequency' => 141, 'monetary' => 192600000.0, 'r_score' => 5, 'f_score' => 5, 'm_score' => 5, 'segment' => 'champions'],
                                    ['customer' => 'Toko Maju Bersama', 'recency_days' => 138, 'frequency' => 62, 'monetary' => 41800000.0, 'r_score' => 1, 'f_score' => 3, 'm_score' => 2, 'segment' => 'at_risk'],
                                ],
                            ],
                        ],
                        'steps' => 2,
                    ],
                    [
                        'role' => 'user',
                        'content' => 'Buatkan program retensi untuk segmen at_risk dan loyal.',
                    ],
                    [
                        'role' => 'assistant',
                        'content' => "Dua program yang saya rekomendasikan:\n\n**at_risk (604 pelanggan, R <= 2)**\n- Pelanggan yang lebih dari 60 hari tidak bertransaksi: voucher 10% berlaku 14 hari.\n- Satu voucher per pelanggan, dengan estimasi budget Rp 41 juta.\n\n**loyal (1.142 pelanggan, R dan F tinggi, M menengah)**\n- Program harga bertingkat: diskon 3% mulai 12 transaksi per kuartal.\n- Target: menaikkan frekuensi 15% dan CLV 8% dalam dua kuartal.\n\nPerlu diwaspadai, 97 pelanggan loyal berisiko turun ke segmen at_risk dalam 60 hari karena recency naik di atas 90 hari atau frequency turun 40%. Nilai yang terdampak Rp 38,6 juta, jadi hubungi mereka sebelum voucher loyal diberikan.\n\nKedua program perlu dihitung ulang setelah impor master pelanggan selesai.",
                        'evidence' => [
                            [
                                'source' => 'warehouse.fact_sales:rfm',
                                'data' => [
                                    ['segment' => 'champions', 'customers' => 318, 'monetary' => 274800000.0, 'share_pct' => 21.4],
                                    ['segment' => 'loyal', 'customers' => 1142, 'monetary' => 481300000.0, 'share_pct' => 37.46],
                                    ['segment' => 'potential', 'customers' => 2194, 'monetary' => 336700000.0, 'share_pct' => 26.22],
                                    ['segment' => 'new', 'customers' => 1362, 'monetary' => 71400000.0, 'share_pct' => 5.56],
                                    ['segment' => 'at_risk', 'customers' => 604, 'monetary' => 120300000.0, 'share_pct' => 9.36],
                                    ['segment' => 'loyal_at_risk_of_downgrade', 'customers' => 97, 'monetary' => 38600000.0, 'share_pct' => 3.01],
                                ],
                            ],
                        ],
                        'steps' => 3,
                    ],
                    [
                        'role' => 'user',
                        'content' => 'Siapkan kalimat ajakan yang bisa langsung saya kirim ke WhatsApp untuk pelanggan at_risk.',
                    ],
                    [
                        'role' => 'assistant',
                        'content' => "Berikut dua variasi yang bisa langsung dikirim:\n\n1. \"Selamat siang Bapak/Ibu, kami catat pesanan terakhir Bapak/Ibu di toko. Kali ini kami siapkan voucher 10% untuk tiga produk pilihan, berlaku sampai 14 hari ke depan.\"\n\n2. \"Bapak/Ibu, ada tambahan stok [PRODUK TERLARIS] yang biasanya Bapak/Ibu ambil. Tinggal balas pesan ini saja, kami siapkan di toko [KOTA] sebelum jam 15.00.\"\n\nHindari menyebut nilai diskon pada voucher yang sudah dipakai, dan selalu konfirmasi sebelum mengirim.",
                        'evidence' => [
                            [
                                'source' => 'warehouse.fact_sales:rfm',
                                'data' => [
                                    ['segment' => 'at_risk', 'customers' => 604, 'monetary' => 120300000.0, 'avg_recency_days' => 104],
                                ],
                            ],
                        ],
                        'steps' => 2,
                    ],
                    [
                        'role' => 'user',
                        'content' => 'Tutup dengan ringkasan tiga poin dan metrik yang harus saya pantau minggu depan.',
                    ],
                    [
                        'role' => 'assistant',
                        'content' => "Tiga poin:\n1. Fokus minggu ini adalah 604 pelanggan at_risk dengan voucher 10% dan budget Rp 41 juta.\n2. 97 pelanggan loyal berisiko turun segmen, sehingga mereka harus dihubungi sebelum voucher loyal diberikan.\n3. Semua angka menunggu master pelanggan selesai diimpor, jadi jangan dijadikan angka final.\n\nMetrik yang dipantau: jumlah pelanggan yang kembali bertransaksi, nilai voucher yang terpakai, tingkat perpindahan loyal ke at_risk, dan CLV per segmen.",
                        'evidence' => [
                            [
                                'source' => 'warehouse.fact_sales:rfm',
                                'data' => [
                                    ['metric' => 'reactivated_at_risk', 'target' => 184, 'baseline' => 0],
                                    ['metric' => 'loyal_to_at_risk_downgrade', 'target' => 0, 'baseline' => 97],
                                    ['metric' => 'clv_per_segment_idr', 'target' => 88400000.0, 'baseline' => 81900000.0],
                                ],
                            ],
                        ],
                        'steps' => 3,
                    ],
                ],
            ],
        ];
    }
}
