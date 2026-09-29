<?php

namespace Tests\Fixtures;

/**
 * Deterministic enterprise fixtures for the whole test suite.
 *
 * One fixed retail group, twelve branches, twelve months of 2025. Every number
 * below is produced by a closed-form formula (seasonal factor x trend x
 * hash-based pseudo-noise), so the output is byte-identical on every PHP
 * version, every OS and every run order:
 *
 * - NO mt_rand/random_int/faker calls. The "noise" term is
 *   `md5($key)` mapped to [-amplitude, +amplitude]; the seed constant exists
 *   only to namespace the keys, not to seed an RNG.
 * - NO real personal data. Customer rows carry synthetic shop names
 *   ("Toko Maju 007") and ledger codes ("C-10007"); there are no emails,
 *   phones, persons or addresses anywhere in this file.
 * - NO database writes. Every method returns plain arrays (or a CSV string),
 *   so the fixtures are usable from unit tests, feature tests and seeders.
 *
 * Distributions (documented so a reviewer can tell drift from breakage):
 *
 * - salesMonthly(): 12 branches x 12 months = 144 rows. Monthly branch revenue
 *   = (118jt + branch_index x 7.5jt) x seasonal(month) x (1 + 0.8% x month)
 *   x (1 +/- 3% hash noise). Seasonal peaks at December (1.35, year-end) and
 *   April (1.10, Idul Fitri 2025); troughs at February (0.88). Transactions =
 *   revenue / avg_ticket(branch); qty scales with transactions; discount is
 *   2-4% of revenue; COGS is 68% of net revenue.
 * - inventorySnapshots(): 24 products x 12 branches = 288 rows. Stock, minimum
 *   stock and daily sales are hash-derived per (product, branch); dead stock is
 *   flagged where days_of_stock > 120.
 * - customers(): 150 synthetic B2B customers. Recency/frequency/monetary are
 *   hash-derived; the churn signal is `recency_days > 180 && frequency_12m <= 3`
 *   (a customer nobody has seen for half a year who barely bought before).
 * - expenses(): 12 branches x 11 ledger categories x 12 months = 1584 rows.
 *   Nominal = typical(category) x (1 +/- 5% hash noise), plus exactly three
 *   documented anomalies (see ANOMALIES) so anomaly-detection tests have a
 *   ground truth.
 */
final class EnterpriseDatasets
{
    /**
     * Namespaces the hash-noise keys. Changing it changes every number, so it
     * is a constant and never an argument.
     */
    public const SEED = 20260930;

    public const SALES_YEAR = 2025;

    /**
     * Injected anomalies: [branch, month (1-12), category, multiplier, reason].
     * Exactly three, so a detector that finds anything else is wrong.
     *
     * @var list<array{string, int, string, float, string}>
     */
    public const ANOMALIES = [
        ['BR-03', 6, 'renovasi', 3.2, 'renovasi gudang cabang'],

        ['BR-07', 11, 'listrik', 2.4, 'tagihan susulan PLN 3 bulan'],
        ['BR-11', 2, 'transportasi', 2.9, 'sewa armada darurat banjir'],
    ];

    /**
     * Golden totals for salesMonthly(), recomputed from the formulas above.
     * Pinned so a formula change fails loudly instead of drifting every
     * downstream KPI. Regenerate with: php -r 'require
     * "tests/Fixtures/EnterpriseDatasets.php"; ...' (see salesSummary()).
     */
    public const EXPECTED_SALES_ROWS = 144;

    public const EXPECTED_SALES_REVENUE = 24891605715;

    public const EXPECTED_SALES_TRANSACTIONS = 250355;

    public const EXPECTED_INVENTORY_ROWS = 288;

    public const EXPECTED_CUSTOMER_ROWS = 150;

    public const EXPECTED_CHURNED_CUSTOMERS = 3;

    public const EXPECTED_EXPENSE_ROWS = 1584;

    // ------------------------------------------------------------------
    // core helpers
    // ------------------------------------------------------------------

    /**
     * Deterministic pseudo-noise in [-$amplitude, +$amplitude] for $key.
     *
     * md5 is used as a stable hash, not as security: the same key always maps
     * to the same float on every platform, unlike mt_rand() whose sequence is
     * engine-version dependent.
     */
    public static function noise(string $key, float $amplitude): float
    {
        $unit = hexdec(substr(md5(self::SEED.':'.$key), 0, 8)) / 4294967295;

        return ($unit * 2 - 1) * $amplitude;
    }

    /**
     * @return list<array{code: string, name: string, city: string, region: string, tier: string}>
     */
    public static function branches(): array
    {
        $cities = [
            ['Jakarta Pusat', 'Jabodetabek'],
            ['Bandung', 'Jawa Barat'],
            ['Semarang', 'Jawa Tengah'],
            ['Surabaya', 'Jawa Timur'],
            ['Medan', 'Sumatera Utara'],
            ['Palembang', 'Sumatera Selatan'],
            ['Denpasar', 'Bali'],
            ['Makassar', 'Sulawesi Selatan'],
            ['Balikpapan', 'Kalimantan Timur'],
            ['Pontianak', 'Kalimantan Barat'],
            ['Manado', 'Sulawesi Utara'],
            ['Jayapura', 'Papua'],
        ];

        $branches = [];

        foreach ($cities as $index => [$city, $region]) {
            $code = sprintf('BR-%02d', $index + 1);
            $branches[] = [
                'code' => $code,
                'name' => 'Cabang '.$city,
                'city' => $city,
                'region' => $region,
                'tier' => $index < 3 ? 'flagship' : 'regular',
            ];
        }

        return $branches;
    }

    /**
     * @return list<array{code: string, category: string, typical_monthly_idr: int}>
     */
    public static function chartOfAccounts(): array
    {
        return [
            ['code' => '5101', 'category' => 'sewa', 'typical_monthly_idr' => 4500000],
            ['code' => '5102', 'category' => 'listrik', 'typical_monthly_idr' => 1875000],
            ['code' => '5103', 'category' => 'air', 'typical_monthly_idr' => 420000],
            ['code' => '5104', 'category' => 'gaji', 'typical_monthly_idr' => 18400000],
            ['code' => '5105', 'category' => 'transportasi', 'typical_monthly_idr' => 920000],
            ['code' => '5106', 'category' => 'atk', 'typical_monthly_idr' => 310000],
            ['code' => '5107', 'category' => 'pemeliharaan', 'typical_monthly_idr' => 760000],
            ['code' => '5108', 'category' => 'pemasaran', 'typical_monthly_idr' => 1500000],
            ['code' => '5109', 'category' => 'asuransi', 'typical_monthly_idr' => 640000],
            ['code' => '5110', 'category' => 'komunikasi', 'typical_monthly_idr' => 380000],
            ['code' => '5111', 'category' => 'renovasi', 'typical_monthly_idr' => 500000],
        ];
    }

    // ------------------------------------------------------------------
    // sales: 12 branches x 12 months
    // ------------------------------------------------------------------

    /** @return array<int, float> month (1-12) => seasonal factor */
    public static function salesSeasonality(): array
    {
        return [
            1 => 0.92, 2 => 0.88, 3 => 1.02, 4 => 1.10, 5 => 1.05, 6 => 0.98,
            7 => 1.00, 8 => 0.97, 9 => 0.99, 10 => 1.04, 11 => 1.12, 12 => 1.35,
        ];
    }

    /**
     * @return list<array{year: int, month: int, kode_cabang: string, transactions: int, qty: int, revenue_idr: int, discount_idr: int, cogs_idr: int}>
     */
    public static function salesMonthly(): array
    {
        $seasonal = self::salesSeasonality();
        $branches = self::branches();
        $rows = [];

        foreach ($branches as $branchIndex => $branch) {
            $base = 118000000 + $branchIndex * 7500000;
            $avgTicket = 85000 + $branchIndex * 2500;

            foreach (range(1, 12) as $month) {
                $trend = 1 + 0.008 * ($month - 1);
                $revenue = (int) round($base * $seasonal[$month] * $trend * (1 + self::noise("sales:{$branchIndex}:{$month}", 0.03)));
                $transactions = (int) round($revenue / $avgTicket);
                $qty = $transactions * (2 + ($branchIndex + $month) % 3);
                $discount = (int) round($revenue * (0.02 + (($branchIndex * $month) % 5) * 0.005));
                $cogs = (int) round(($revenue - $discount) * 0.68);

                $rows[] = [
                    'year' => self::SALES_YEAR,
                    'month' => $month,
                    'kode_cabang' => $branch['code'],
                    'transactions' => $transactions,
                    'qty' => $qty,
                    'revenue_idr' => $revenue,
                    'discount_idr' => $discount,
                    'cogs_idr' => $cogs,
                ];
            }
        }

        return $rows;
    }

    /** @return array{rows: int, revenue_idr: int, transactions: int, qty: int} */
    public static function salesSummary(): array
    {
        $rows = self::salesMonthly();

        return [
            'rows' => count($rows),
            'revenue_idr' => array_sum(array_column($rows, 'revenue_idr')),
            'transactions' => array_sum(array_column($rows, 'transactions')),
            'qty' => array_sum(array_column($rows, 'qty')),
        ];
    }

    public static function salesCsv(): string
    {
        $lines = ['year,month,kode_cabang,transactions,qty,revenue_idr,discount_idr,cogs_idr'];

        foreach (self::salesMonthly() as $row) {
            $lines[] = implode(',', [
                $row['year'], $row['month'], $row['kode_cabang'], $row['transactions'],
                $row['qty'], $row['revenue_idr'], $row['discount_idr'], $row['cogs_idr'],
            ]);
        }

        return implode("\n", $lines)."\n";
    }

    // ------------------------------------------------------------------
    // inventory snapshots: 24 products x 12 branches
    // ------------------------------------------------------------------

    /** @return list<array{kode_produk: string, nama_barang: string, kategori: string, cost_price: int}> */
    public static function products(): array
    {
        $catalog = [
            ['Minyak Goreng 1L', 'Sembako', 24300], ['Beras Premium 5kg', 'Sembako', 68200],
            ['Gula Pasir 1kg', 'Sembako', 14900], ['Teh Celup 25s', 'Minuman', 5900],
            ['Kopi Bubuk 200g', 'Minuman', 21400], ['Susu UHT 1L', 'Minuman', 17800],
            ['Sabun Mandi 90g', 'Perawatan', 4200], ['Sampo 340ml', 'Perawatan', 26700],
            ['Deterjen 800g', 'Perawatan', 18900], ['Pasta Gigi 190g', 'Perawatan', 15200],
            ['Biskuit Kaleng 600g', 'Snack', 32500], ['Mi Instan 5s', 'Snack', 14800],
            ['Cokelat Batang 150g', 'Snack', 22900], ['Keripik Singkong 250g', 'Snack', 11500],
            ['Baterai AA 4s', 'Elektronik', 27600], ['Lampu LED 9W', 'Elektronik', 31800],
            ['Obat Nyamuk Elektrik', 'Rumah Tangga', 45300], ['Pembersih Lantai 800ml', 'Rumah Tangga', 16700],
            ['Tisu Wajah 250s', 'Rumah Tangga', 19400], ['Minyak Kayu Putih 60ml', 'Kesehatan', 23100],
            ['Vitamin C 30 tablet', 'Kesehatan', 38900], ['Plester Luka 20s', 'Kesehatan', 9800],
            ['Buku Tulis 58 lembar', 'ATK', 6400], ['Pulpen Gel 0.5', 'ATK', 5100],
        ];

        $products = [];

        foreach ($catalog as $index => [$name, $category, $cost]) {
            $products[] = [
                'kode_produk' => sprintf('P-%06d', 101 + $index),
                'nama_barang' => $name,
                'kategori' => $category,
                'cost_price' => $cost,
            ];
        }

        return $products;
    }

    /**
     * @return list<array{kode_produk: string, kode_cabang: string, stock_qty: int, min_stock: int, avg_daily_sales: int, days_of_stock: float, dead_stock: bool}>
     */
    public static function inventorySnapshots(): array
    {
        $rows = [];

        foreach (self::products() as $productIndex => $product) {
            foreach (self::branches() as $branchIndex => $branch) {
                $key = "inv:{$productIndex}:{$branchIndex}";
                $avgDaily = 2 + (int) (abs(self::noise($key.':v', 1)) * 22);
                $minStock = 20 + (int) (abs(self::noise($key.':m', 1)) * 60);
                // Most branches carry a healthy cover; every 9th cell is
                // overstocked on purpose so dead-stock tests have positives.
                $cover = ($productIndex + $branchIndex) % 9 === 0 ? 150 : 28;
                $stock = max(0, (int) round($avgDaily * $cover * (1 + self::noise($key.':s', 0.2))));
                $days = $avgDaily > 0 ? round($stock / $avgDaily, 1) : 0.0;

                $rows[] = [
                    'kode_produk' => $product['kode_produk'],
                    'kode_cabang' => $branch['code'],
                    'stock_qty' => $stock,
                    'min_stock' => $minStock,
                    'avg_daily_sales' => $avgDaily,
                    'days_of_stock' => $days,
                    'dead_stock' => $days > 120,
                ];
            }
        }

        return $rows;
    }

    public static function inventoryCsv(): string
    {
        $lines = ['kode_produk,kode_cabang,stock_qty,min_stock,avg_daily_sales,days_of_stock,dead_stock'];

        foreach (self::inventorySnapshots() as $row) {
            $lines[] = implode(',', [
                $row['kode_produk'], $row['kode_cabang'], $row['stock_qty'], $row['min_stock'],
                $row['avg_daily_sales'], $row['days_of_stock'], $row['dead_stock'] ? '1' : '0',
            ]);
        }

        return implode("\n", $lines)."\n";
    }

    // ------------------------------------------------------------------
    // customers: 150 rows with a churn signal
    // ------------------------------------------------------------------

    /**
     * @return list<array{kode_pelanggan: string, nama_toko: string, kota: string, segment: string, recency_days: int, frequency_12m: int, monetary_12m: int, churned: bool}>
     */
    public static function customers(): array
    {
        $words = ['Maju', 'Berkah', 'Jaya', 'Abadi', 'Makmur', 'Sejahtera', 'Lancar', 'Sentosa', 'Tentram', 'Subur'];
        $segments = ['champions', 'loyal', 'potential', 'at_risk', 'hibernating'];
        $branches = self::branches();
        $rows = [];

        for ($i = 1; $i <= 150; $i++) {
            $key = "cust:{$i}";
            $recency = 5 + (int) (abs(self::noise($key.':r', 1)) * 340);
            $frequency = 1 + (int) (abs(self::noise($key.':f', 1)) * 48);
            $ticket = 50000 + (int) (abs(self::noise($key.':t', 1)) * 450000);
            $monetary = $frequency * $ticket;
            $churned = $recency > 180 && $frequency <= 3;

            $rows[] = [
                'kode_pelanggan' => sprintf('C-%05d', 10000 + $i),
                'nama_toko' => 'Toko '.$words[($i - 1) % count($words)].' '.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                'kota' => $branches[($i - 1) % count($branches)]['city'],
                'segment' => $churned ? 'hibernating' : $segments[($i - 1) % (count($segments) - 1)],
                'recency_days' => $recency,
                'frequency_12m' => $frequency,
                'monetary_12m' => $monetary,
                'churned' => $churned,
            ];
        }

        return $rows;
    }

    public static function churnedCustomers(): array
    {
        return array_values(array_filter(self::customers(), static fn (array $c): bool => $c['churned']));
    }

    public static function customersCsv(): string
    {
        $lines = ['kode_pelanggan,nama_toko,kota,segment,recency_days,frequency_12m,monetary_12m,churned'];

        foreach (self::customers() as $row) {
            $lines[] = implode(',', [
                $row['kode_pelanggan'], '"'.$row['nama_toko'].'"', '"'.$row['kota'].'"',
                $row['segment'], $row['recency_days'], $row['frequency_12m'],
                $row['monetary_12m'], $row['churned'] ? '1' : '0',
            ]);
        }

        return implode("\n", $lines)."\n";
    }

    // ------------------------------------------------------------------
    // expenses: 12 branches x 11 categories x 12 months (+ 3 anomalies)
    // ------------------------------------------------------------------

    /**
     * @return list<array{year: int, month: int, kode_cabang: string, kategori: string, nominal_idr: int, is_anomaly: bool}>
     */
    public static function expenses(): array
    {
        $rows = [];
        $anomalyKeys = [];

        foreach (self::ANOMALIES as [$branch, $month, $category, $multiplier]) {
            $anomalyKeys[$branch.'|'.$month.'|'.$category] = $multiplier;
        }

        foreach (self::branches() as $branchIndex => $branch) {
            foreach (self::chartOfAccounts() as $account) {
                foreach (range(1, 12) as $month) {
                    $key = $branch['code'].'|'.$month.'|'.$account['category'];
                    $nominal = (int) round(
                        $account['typical_monthly_idr']
                        * (1 + self::noise("exp:{$branchIndex}:{$account['category']}:{$month}", 0.05))
                        * ($anomalyKeys[$key] ?? 1.0)
                    );

                    $rows[] = [
                        'year' => self::SALES_YEAR,
                        'month' => $month,
                        'kode_cabang' => $branch['code'],
                        'kategori' => $account['category'],
                        'nominal_idr' => $nominal,
                        'is_anomaly' => isset($anomalyKeys[$key]),
                    ];
                }
            }
        }

        return $rows;
    }

    public static function anomalousExpenses(): array
    {
        return array_values(array_filter(self::expenses(), static fn (array $e): bool => $e['is_anomaly']));
    }

    public static function expensesCsv(): string
    {
        $lines = ['year,month,kode_cabang,kategori,nominal_idr,is_anomaly'];

        foreach (self::expenses() as $row) {
            $lines[] = implode(',', [
                $row['year'], $row['month'], $row['kode_cabang'], $row['kategori'],
                $row['nominal_idr'], $row['is_anomaly'] ? '1' : '0',
            ]);
        }

        return implode("\n", $lines)."\n";
    }

    // ------------------------------------------------------------------
    // seeder / factory friendly: mass-assignment-safe Dataset attributes
    // ------------------------------------------------------------------

    /**
     * Mass-assignment-safe attributes for Dataset::factory()->create() or
     * Dataset::create(). Every key is in Dataset::$fillable (which covers
     * import_job_id), so nothing is dropped silently by guarded writes.
     *
     * @param  array<string, mixed>  $overrides  caller-owned keys (e.g. user_id, import_job_id, status)
     * @return array<string, mixed>
     */
    public static function datasetAttributes(string $type, array $overrides = []): array
    {
        $csv = match ($type) {
            'inventory' => self::inventoryCsv(),
            'customers' => self::customersCsv(),
            'expenses' => self::expensesCsv(),
            default => self::salesCsv(),
        };

        $columns = match ($type) {
            'inventory' => [
                ['name' => 'kode_produk', 'dtype' => 'string'],
                ['name' => 'kode_cabang', 'dtype' => 'string'],
                ['name' => 'stock_qty', 'dtype' => 'integer'],
                ['name' => 'min_stock', 'dtype' => 'integer'],
                ['name' => 'avg_daily_sales', 'dtype' => 'integer'],
            ],
            'customers' => [
                ['name' => 'kode_pelanggan', 'dtype' => 'string'],
                ['name' => 'nama_toko', 'dtype' => 'string'],
                ['name' => 'kota', 'dtype' => 'string'],
                ['name' => 'segment', 'dtype' => 'string'],
                ['name' => 'recency_days', 'dtype' => 'integer'],
                ['name' => 'frequency_12m', 'dtype' => 'integer'],
                ['name' => 'monetary_12m', 'dtype' => 'integer'],
            ],
            'expenses' => [
                ['name' => 'year', 'dtype' => 'integer'],
                ['name' => 'month', 'dtype' => 'integer'],
                ['name' => 'kode_cabang', 'dtype' => 'string'],
                ['name' => 'kategori', 'dtype' => 'string'],
                ['name' => 'nominal_idr', 'dtype' => 'integer'],
            ],
            default => [
                ['name' => 'year', 'dtype' => 'integer'],
                ['name' => 'month', 'dtype' => 'integer'],
                ['name' => 'kode_cabang', 'dtype' => 'string'],
                ['name' => 'transactions', 'dtype' => 'integer'],
                ['name' => 'revenue_idr', 'dtype' => 'integer'],
            ],
        };

        $mappings = [];

        foreach ($columns as $column) {
            $mappings[$column['name']] = $column['name'];
        }

        $rowCount = substr_count($csv, "\n") - 1;

        return array_merge([
            'name' => 'Enterprise '.ucfirst($type).' '.self::SALES_YEAR,
            'dataset_type' => $type,
            'source_filename' => 'enterprise_'.$type.'_'.self::SALES_YEAR.'.csv',
            'disk' => 'local',
            'path' => 'datasets/enterprise/'.$type.'_'.self::SALES_YEAR.'.csv',
            'size_bytes' => strlen($csv),
            'mime' => 'text/csv',
            'checksum_sha256' => hash('sha256', $csv),
            'status' => 'uploaded',
            'row_count' => $rowCount,
            'column_count' => count($columns),
            'columns' => $columns,
            'mappings' => $mappings,
            'metadata' => ['fixture' => 'enterprise', 'seed' => self::SEED],
        ], $overrides);
    }
}
