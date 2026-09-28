<?php

namespace Database\Factories;

use App\Enums\DatasetStatus;
use App\Enums\QualityVerdict;
use App\Models\Dataset;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Dataset>
 */
class DatasetFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return static::profile('sales');
    }

    /**
     * Use the column profile, row count and file size of the given dataset type.
     */
    public function forType(string $type): static
    {
        return $this->state(function (array $attributes) use ($type): array {
            $profile = static::profile($type);

            return [
                ...$profile,
                'metadata' => array_merge(
                    $attributes['metadata']['quality'] ?? [],
                    $profile['metadata'],
                ),
            ];
        });
    }

    /**
     * Indicate that the dataset passed quality checks and is loaded into the warehouse.
     */
    public function committed(): static
    {
        return $this->state(function (array $attributes): array {
            $score = fake()->randomFloat(4, 0.86, 0.98);
            $checkedAt = now()->subHours(fake()->numberBetween(2, 96));

            return [
                'status' => DatasetStatus::Committed,
                'import_job_id' => static::importJobId(),
                'quality_score' => $score,
                'quality_verdict' => QualityVerdict::Pass->value,
                'quality_checked_at' => $checkedAt,
                'committed_at' => $checkedAt,
                'metadata' => array_merge($attributes['metadata'] ?? [], [
                    'quality' => static::quality($score, []),
                ]),
            ];
        });
    }

    /**
     * Indicate that the dataset scored below the quality threshold and was isolated.
     */
    public function quarantined(): static
    {
        return $this->state(function (array $attributes): array {
            $score = fake()->randomFloat(4, 0.38, 0.48);
            $checkedAt = now()->subHours(fake()->numberBetween(2, 72));

            return [
                'status' => DatasetStatus::Quarantined,
                'import_job_id' => static::importJobId(),
                'quality_score' => $score,
                'quality_verdict' => QualityVerdict::Quarantine->value,
                'quality_checked_at' => $checkedAt,
                'committed_at' => null,
                'metadata' => array_merge($attributes['metadata'] ?? [], [
                    'quality' => static::quality($score, static::quarantineIssues()),
                ]),
            ];
        });
    }

    /**
     * Indicate that an import job is currently loading the dataset.
     */
    public function importing(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => DatasetStatus::Importing,
            'import_job_id' => static::importJobId(),
            'quality_score' => null,
            'quality_verdict' => null,
            'quality_checked_at' => null,
            'committed_at' => null,
        ]);
    }

    /**
     * Indicate that the import job failed before the dataset was committed.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => DatasetStatus::Failed,
            'import_job_id' => static::importJobId(),
            'quality_score' => null,
            'quality_verdict' => null,
            'quality_checked_at' => null,
            'committed_at' => null,
            'metadata' => array_merge($attributes['metadata'] ?? [], [
                'error' => static::failureReason(),
            ]),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected static function profile(string $type): array
    {
        $profiles = static::profiles();
        $profile = $profiles[$type] ?? $profiles['generic'];
        $columns = $profile['columns'];

        return [
            'uuid' => (string) fake()->uuid(),
            'name' => $profile['name'],
            'dataset_type' => $type,
            'source_filename' => $profile['filename'],
            'disk' => 'local',
            'path' => 'datasets/'.$profile['filename'],
            'size_bytes' => $profile['rows'] * $profile['bytes_per_row'],
            'mime' => 'text/csv',
            'checksum_sha256' => fake()->sha256(),
            'status' => DatasetStatus::Uploaded,
            'import_job_id' => null,
            'row_count' => $profile['rows'],
            'column_count' => count($columns),
            'columns' => $columns,
            'mappings' => $profile['mappings'],
            'metadata' => [
                'preview' => [
                    'source' => $profile['filename'],
                    'encoding' => 'utf-8',
                    'delimiter' => ',',
                    'has_header' => true,
                    'sample_rows' => $profile['sample_rows'],
                ],
            ],
            'quality_score' => null,
            'quality_verdict' => null,
            'quality_checked_at' => null,
            'committed_at' => null,
        ];
    }

    /**
     * Column profiles for realistic Indonesian retail / wholesale data.
     *
     * @return array<string, array<string, mixed>>
     */
    protected static function profiles(): array
    {
        return [
            'sales' => [
                'name' => 'Penjualan Retail Harian 2026',
                'filename' => 'penjualan_retail_harian_2026.csv',
                'rows' => 18420,
                'bytes_per_row' => 78,
                'columns' => [
                    ['name' => 'tanggal', 'dtype' => 'date', 'missing' => 0, 'missing_pct' => 0.0, 'unique' => 273, 'sample' => '2026-01-05'],
                    ['name' => 'kode_pelanggan', 'dtype' => 'string', 'missing' => 312, 'missing_pct' => 0.0169, 'unique' => 2148, 'sample' => 'C-10432'],
                    ['name' => 'kode_produk', 'dtype' => 'string', 'missing' => 0, 'missing_pct' => 0.0, 'unique' => 486, 'sample' => 'P-000231'],
                    ['name' => 'kode_cabang', 'dtype' => 'string', 'missing' => 0, 'missing_pct' => 0.0, 'unique' => 12, 'sample' => 'BR-03'],
                    ['name' => 'qty', 'dtype' => 'integer', 'missing' => 0, 'missing_pct' => 0.0, 'unique' => 41, 'sample' => 3],
                    ['name' => 'harga_jual', 'dtype' => 'float', 'missing' => 0, 'missing_pct' => 0.0, 'unique' => 612, 'sample' => 27500.0],
                    ['name' => 'diskon', 'dtype' => 'float', 'missing' => 0, 'missing_pct' => 0.0, 'unique' => 9, 'sample' => 0.0],
                ],
                'mappings' => [
                    'tanggal' => 'transaction_date',
                    'kode_pelanggan' => 'customer_code',
                    'kode_produk' => 'product_code',
                    'kode_cabang' => 'branch_code',
                    'qty' => 'quantity',
                    'harga_jual' => 'selling_price',
                    'diskon' => 'discount',
                ],
                'sample_rows' => [
                    ['tanggal' => '2026-01-05', 'kode_pelanggan' => 'C-10432', 'kode_produk' => 'P-000231', 'kode_cabang' => 'BR-03', 'qty' => 3, 'harga_jual' => 27500.0, 'diskon' => 0.0],
                    ['tanggal' => '2026-01-05', 'kode_pelanggan' => 'C-10017', 'kode_produk' => 'P-000874', 'kode_cabang' => 'BR-01', 'qty' => 12, 'harga_jual' => 8450.0, 'diskon' => 500.0],
                    ['tanggal' => '2026-01-05', 'kode_pelanggan' => null, 'kode_produk' => 'P-000231', 'kode_cabang' => 'BR-07', 'qty' => 1, 'harga_jual' => 27500.0, 'diskon' => 0.0],
                    ['tanggal' => '2026-01-05', 'kode_pelanggan' => 'C-10880', 'kode_produk' => 'P-001455', 'kode_cabang' => 'BR-03', 'qty' => 6, 'harga_jual' => 15200.0, 'diskon' => 0.0],
                    ['tanggal' => '2026-01-05', 'kode_pelanggan' => 'C-10255', 'kode_produk' => 'P-000903', 'kode_cabang' => 'BR-11', 'qty' => 2, 'harga_jual' => 63900.0, 'diskon' => 2500.0],
                ],
            ],

            'inventory' => [
                'name' => 'Stok Akhir Gudang & Toko',
                'filename' => 'stok_akhir_gudang_toko.csv',
                'rows' => 1860,
                'bytes_per_row' => 96,
                'columns' => [
                    ['name' => 'kode_produk', 'dtype' => 'string', 'missing' => 0, 'missing_pct' => 0.0, 'unique' => 1860, 'sample' => 'P-000231'],
                    ['name' => 'nama_barang', 'dtype' => 'string', 'missing' => 0, 'missing_pct' => 0.0, 'unique' => 1742, 'sample' => 'Minyak Goreng 1L'],
                    ['name' => 'kategori', 'dtype' => 'string', 'missing' => 0, 'missing_pct' => 0.0, 'unique' => 18, 'sample' => 'Sembako'],
                    ['name' => 'stok_akhir', 'dtype' => 'integer', 'missing' => 0, 'missing_pct' => 0.0, 'unique' => 214, 'sample' => 148],
                    ['name' => 'stok_minimum', 'dtype' => 'integer', 'missing' => 0, 'missing_pct' => 0.0, 'unique' => 32, 'sample' => 40],
                    ['name' => 'harga_beli', 'dtype' => 'float', 'missing' => 0, 'missing_pct' => 0.0, 'unique' => 908, 'sample' => 24300.0],
                ],
                'mappings' => [
                    'kode_produk' => 'product_code',
                    'nama_barang' => 'product_name',
                    'kategori' => 'category',
                    'stok_akhir' => 'stock_qty',
                    'stok_minimum' => 'min_stock_qty',
                    'harga_beli' => 'cost_price',
                ],
                'sample_rows' => [
                    ['kode_produk' => 'P-000231', 'nama_barang' => 'Minyak Goreng 1L', 'kategori' => 'Sembako', 'stok_akhir' => 148, 'stok_minimum' => 40, 'harga_beli' => 24300.0],
                    ['kode_produk' => 'P-000874', 'nama_barang' => 'Beras Premium 5kg', 'kategori' => 'Sembako', 'stok_akhir' => 62, 'stok_minimum' => 35, 'harga_beli' => 68200.0],
                    ['kode_produk' => 'P-001455', 'nama_barang' => 'Gula Pasir 1kg', 'kategori' => 'Sembako', 'stok_akhir' => 21, 'stok_minimum' => 50, 'harga_beli' => 14900.0],
                    ['kode_produk' => 'P-000903', 'nama_barang' => 'Teh Celup 25s', 'kategori' => 'Minuman', 'stok_akhir' => 305, 'stok_minimum' => 60, 'harga_beli' => 5900.0],
                ],
            ],

            'purchases' => [
                'name' => 'Pesanan Pembelian ke Supplier',
                'filename' => 'pesanan_pembelian_supplier.csv',
                'rows' => 3240,
                'bytes_per_row' => 82,
                'columns' => [
                    ['name' => 'tanggal_po', 'dtype' => 'date', 'missing' => 0, 'missing_pct' => 0.0, 'unique' => 298, 'sample' => '2026-01-08'],
                    ['name' => 'kode_supplier', 'dtype' => 'string', 'missing' => 0, 'missing_pct' => 0.0, 'unique' => 47, 'sample' => 'S-0031'],
                    ['name' => 'kode_produk', 'dtype' => 'string', 'missing' => 0, 'missing_pct' => 0.0, 'unique' => 903, 'sample' => 'P-000231'],
                    ['name' => 'qty_po', 'dtype' => 'integer', 'missing' => 0, 'missing_pct' => 0.0, 'unique' => 87, 'sample' => 240],
                    ['name' => 'harga_satuan', 'dtype' => 'float', 'missing' => 0, 'missing_pct' => 0.0, 'unique' => 1104, 'sample' => 24150.0],
                    ['name' => 'status_po', 'dtype' => 'string', 'missing' => 18, 'missing_pct' => 0.0056, 'unique' => 4, 'sample' => 'diterima'],
                ],
                'mappings' => [
                    'tanggal_po' => 'order_date',
                    'kode_supplier' => 'supplier_code',
                    'kode_produk' => 'product_code',
                    'qty_po' => 'order_qty',
                    'harga_satuan' => 'unit_cost',
                    'status_po' => 'status',
                ],
                'sample_rows' => [
                    ['tanggal_po' => '2026-01-08', 'kode_supplier' => 'S-0031', 'kode_produk' => 'P-000231', 'qty_po' => 240, 'harga_satuan' => 24150.0, 'status_po' => 'diterima'],
                    ['tanggal_po' => '2026-01-08', 'kode_supplier' => 'S-0012', 'kode_produk' => 'P-000874', 'qty_po' => 120, 'harga_satuan' => 67800.0, 'status_po' => 'diproses'],
                    ['tanggal_po' => '2026-01-08', 'kode_supplier' => 'S-0031', 'kode_produk' => 'P-001455', 'qty_po' => 300, 'harga_satuan' => 14750.0, 'status_po' => 'diterima'],
                    ['tanggal_po' => '2026-01-08', 'kode_supplier' => 'S-0044', 'kode_produk' => 'P-000903', 'qty_po' => 500, 'harga_satuan' => 5850.0, 'status_po' => 'dikirim'],
                ],
            ],

            'expenses' => [
                'name' => 'Biaya Operasional Toko',
                'filename' => 'biaya_operasional_toko.csv',
                'rows' => 2410,
                'bytes_per_row' => 88,
                'columns' => [
                    ['name' => 'tanggal', 'dtype' => 'date', 'missing' => 0, 'missing_pct' => 0.0, 'unique' => 273, 'sample' => '2026-02-01'],
                    ['name' => 'kategori_biaya', 'dtype' => 'string', 'missing' => 0, 'missing_pct' => 0.0, 'unique' => 11, 'sample' => 'sewa'],
                    ['name' => 'kode_cabang', 'dtype' => 'string', 'missing' => 0, 'missing_pct' => 0.0, 'unique' => 12, 'sample' => 'BR-05'],
                    ['name' => 'nominal', 'dtype' => 'float', 'missing' => 0, 'missing_pct' => 0.0, 'unique' => 1841, 'sample' => 4500000.0],
                    ['name' => 'keterangan', 'dtype' => 'string', 'missing' => 96, 'missing_pct' => 0.0398, 'unique' => 1602, 'sample' => 'Sewa kios Bulanan'],
                ],
                'mappings' => [
                    'tanggal' => 'expense_date',
                    'kategori_biaya' => 'category',
                    'kode_cabang' => 'branch_code',
                    'nominal' => 'amount',
                    'keterangan' => 'description',
                ],
                'sample_rows' => [
                    ['tanggal' => '2026-02-01', 'kategori_biaya' => 'sewa', 'kode_cabang' => 'BR-05', 'nominal' => 4500000.0, 'keterangan' => 'Sewa kios Bulanan'],
                    ['tanggal' => '2026-02-01', 'kategori_biaya' => 'listrik', 'kode_cabang' => 'BR-01', 'nominal' => 1875000.0, 'keterangan' => 'Tagihan PLN 01/2026'],
                    ['tanggal' => '2026-02-01', 'kategori_biaya' => 'transportasi', 'kode_cabang' => 'BR-07', 'nominal' => 920000.0, 'keterangan' => null],
                    ['tanggal' => '2026-02-01', 'kategori_biaya' => 'gaji', 'kode_cabang' => 'BR-03', 'nominal' => 18400000.0, 'keterangan' => 'Gaji karyawan Jan 2026'],
                ],
            ],

            'customers' => [
                'name' => 'Master Pelanggan & Segmen',
                'filename' => 'master_pelanggan_segmen.csv',
                'rows' => 5620,
                'bytes_per_row' => 92,
                'columns' => [
                    ['name' => 'kode_pelanggan', 'dtype' => 'string', 'missing' => 0, 'missing_pct' => 0.0, 'unique' => 5620, 'sample' => 'C-10432'],
                    ['name' => 'nama_pelanggan', 'dtype' => 'string', 'missing' => 0, 'missing_pct' => 0.0, 'unique' => 5410, 'sample' => 'Toko Berkah Jaya'],
                    ['name' => 'kota', 'dtype' => 'string', 'missing' => 0, 'missing_pct' => 0.0, 'unique' => 27, 'sample' => 'Bandung'],
                    ['name' => 'segment', 'dtype' => 'string', 'missing' => 0, 'missing_pct' => 0.0, 'unique' => 5, 'sample' => 'loyal'],
                    ['name' => 'email', 'dtype' => 'string', 'missing' => 431, 'missing_pct' => 0.0766, 'unique' => 5189, 'sample' => 'berkah.jaya@example.co.id'],
                    ['name' => 'telepon', 'dtype' => 'string', 'missing' => 0, 'missing_pct' => 0.0, 'unique' => 5502, 'sample' => '0812-3456-7890'],
                ],
                'mappings' => [
                    'kode_pelanggan' => 'customer_code',
                    'nama_pelanggan' => 'customer_name',
                    'kota' => 'city',
                    'segment' => 'segment',
                    'email' => 'email',
                    'telepon' => 'phone',
                ],
                'sample_rows' => [
                    ['kode_pelanggan' => 'C-10432', 'nama_pelanggan' => 'Toko Berkah Jaya', 'kota' => 'Bandung', 'segment' => 'loyal', 'email' => 'berkah.jaya@example.co.id', 'telepon' => '0812-3456-7890'],
                    ['kode_pelanggan' => 'C-10017', 'nama_pelanggan' => 'CV Sinar Abadi', 'kota' => 'Jakarta', 'segment' => 'champions', 'email' => 'sinar.abadi@example.co.id', 'telepon' => '0813-2211-0045'],
                    ['kode_pelanggan' => 'C-10880', 'nama_pelanggan' => 'Warung Bu Imas', 'kota' => 'Surabaya', 'segment' => 'potential', 'email' => null, 'telepon' => '0857-9012-3344'],
                    ['kode_pelanggan' => 'C-10255', 'nama_pelanggan' => 'Toko Maju Bersama', 'kota' => 'Semarang', 'segment' => 'at_risk', 'email' => 'maju.bersama@example.co.id', 'telepon' => '0821-6677-8890'],
                ],
            ],

            'generic' => [
                'name' => 'Data Umum Apotek',
                'filename' => 'data_umum_apotek.csv',
                'rows' => 1200,
                'bytes_per_row' => 64,
                'columns' => [
                    ['name' => 'id', 'dtype' => 'integer', 'missing' => 0, 'missing_pct' => 0.0, 'unique' => 1200, 'sample' => 1],
                    ['name' => 'label', 'dtype' => 'string', 'missing' => 0, 'missing_pct' => 0.0, 'unique' => 1187, 'sample' => 'Kanal Apotek'],
                    ['name' => 'nilai', 'dtype' => 'float', 'missing' => 24, 'missing_pct' => 0.02, 'unique' => 943, 'sample' => 128450.0],
                    ['name' => 'dibuat_pada', 'dtype' => 'datetime', 'missing' => 0, 'missing_pct' => 0.0, 'unique' => 1200, 'sample' => '2026-01-15 08:30:00'],
                ],
                'mappings' => [
                    'id' => 'id',
                    'label' => 'label',
                    'nilai' => 'value',
                    'dibuat_pada' => 'created_at_source',
                ],
                'sample_rows' => [
                    ['id' => 1, 'label' => 'Kanal Apotek', 'nilai' => 128450.0, 'dibuat_pada' => '2026-01-15 08:30:00'],
                    ['id' => 2, 'label' => 'Kanal Grosir', 'nilai' => 964200.0, 'dibuat_pada' => '2026-01-15 08:31:12'],
                    ['id' => 3, 'label' => 'Kanal Online', 'nilai' => null, 'dibuat_pada' => '2026-01-15 08:32:47'],
                    ['id' => 4, 'label' => 'Kanal Reseller', 'nilai' => 512800.0, 'dibuat_pada' => '2026-01-15 08:33:03'],
                ],
            ],
        ];
    }

    /**
     * Build a quality report payload whose breakdown averages back to the score.
     *
     * @param  list<array<string, mixed>>  $issues
     * @return array<string, mixed>
     */
    protected static function quality(float $score, array $issues): array
    {
        $completeness = round(min(1.0, $score + 0.04), 4);
        $uniqueness = round(min(1.0, max(0.0, $score - 0.03)), 4);
        $validity = round(min(1.0, max(0.0, $score + 0.01)), 4);
        $consistency = round(max(0.0, $score * 4 - $completeness - $uniqueness - $validity), 4);

        return [
            'score' => $score,
            'breakdown' => [
                'completeness' => $completeness,
                'uniqueness' => $uniqueness,
                'validity' => $validity,
                'consistency' => $consistency,
            ],
            'issues' => $issues,
            'passed' => $score >= 0.75,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected static function quarantineIssues(): array
    {
        return [
            [
                'rule' => 'high_null_rate',
                'column' => 'kode_pelanggan',
                'count' => 4820,
                'sample_rows' => [3, 17, 42],
                'message' => '4820 nilai kosong pada kolom kode_pelanggan (36.4%)',
            ],
            [
                'rule' => 'duplicate_rows',
                'column' => null,
                'count' => 214,
                'sample_rows' => [8, 9, 10],
                'message' => '214 baris duplik terdeteksi',
            ],
            [
                'rule' => 'invalid_value',
                'column' => 'tanggal',
                'count' => 96,
                'sample_rows' => [21, 22],
                'message' => '96 nilai tanggal tidak dapat diparsing menjadi tanggal',
            ],
            [
                'rule' => 'outlier_zscore',
                'column' => 'harga_jual',
                'count' => 31,
                'sample_rows' => [55],
                'message' => '31 nilai ekstrem pada kolom harga_jual (|z|>4)',
            ],
        ];
    }

    protected static function failureReason(): string
    {
        return 'Impor gagal: kolom "tanggal" tidak dapat dikonversi ke tipe date pada baris 4.812 dari 18.420 baris (InvalidArgumentException).';
    }

    protected static function importJobId(): int
    {
        return fake()->numberBetween(1000, 9999);
    }
}
