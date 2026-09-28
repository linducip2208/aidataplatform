<?php

namespace Database\Seeders;

use App\Enums\DatasetStatus;
use App\Enums\QualityVerdict;
use App\Models\Dataset;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Creates six demo datasets for the Indonesian retail demo in the root README, so the dataset
 * list, dataset detail, quality and preview pages all have content on a fresh install:
 *   - 3 committed (sales, inventory, purchases) with quality reports above the 0.75 threshold
 *   - 1 quarantined (expenses) with a quality score of ~0.42 and blocking issues
 *   - 1 importing (customers) still being loaded by a Celery worker
 *   - 1 failed (generic) with the import error stored in `metadata.error`
 *
 * Ownership: the three committed datasets belong to `analyst@example.com`, the rest to
 * `admin@example.com`. No credentials are created here; `UserSeeder` runs first and both
 * seeders are idempotent (datasets are keyed on a fixed UUID, so repeated `db:seed --force`
 * runs update the same six rows instead of duplicating them).
 */
class DatasetSeeder extends Seeder
{
    public function run(): void
    {
        $analyst = $this->demoUser('analyst@example.com');
        $admin = $this->demoUser('admin@example.com');

        foreach ($this->definitions() as $definition) {
            $owner = $definition['owner'] === 'admin' ? $admin : $analyst;
            unset($definition['owner']);

            Dataset::updateOrCreate(
                ['uuid' => $definition['uuid']],
                [...$definition, 'user_id' => $owner->getKey()],
            );
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
     * @return list<array<string, mixed>>
     */
    protected function definitions(): array
    {
        return [
            $this->committed('1f0c2b74-3d5a-4c9e-9b21-8a1f6d0c0001', 'sales', 'Penjualan Retail Harian 2026', 'penjualan_retail_harian_2026.csv', [
                ['name' => 'tanggal', 'dtype' => 'date', 'missing' => 0, 'missing_pct' => 0.0, 'unique' => 273, 'sample' => '2026-01-05'],
                ['name' => 'kode_pelanggan', 'dtype' => 'string', 'missing' => 312, 'missing_pct' => 0.0169, 'unique' => 2148, 'sample' => 'C-10432'],
                ['name' => 'kode_produk', 'dtype' => 'string', 'missing' => 0, 'missing_pct' => 0.0, 'unique' => 486, 'sample' => 'P-000231'],
                ['name' => 'kode_cabang', 'dtype' => 'string', 'missing' => 0, 'missing_pct' => 0.0, 'unique' => 12, 'sample' => 'BR-03'],
                ['name' => 'qty', 'dtype' => 'integer', 'missing' => 0, 'missing_pct' => 0.0, 'unique' => 41, 'sample' => 3],
                ['name' => 'harga_jual', 'dtype' => 'float', 'missing' => 0, 'missing_pct' => 0.0, 'unique' => 612, 'sample' => 27500.0],
                ['name' => 'diskon', 'dtype' => 'float', 'missing' => 0, 'missing_pct' => 0.0, 'unique' => 9, 'sample' => 0.0],
            ], [
                'tanggal' => 'transaction_date',
                'kode_pelanggan' => 'customer_code',
                'kode_produk' => 'product_code',
                'kode_cabang' => 'branch_code',
                'qty' => 'quantity',
                'harga_jual' => 'selling_price',
                'diskon' => 'discount',
            ], [
                ['tanggal' => '2026-01-05', 'kode_pelanggan' => 'C-10432', 'kode_produk' => 'P-000231', 'kode_cabang' => 'BR-03', 'qty' => 3, 'harga_jual' => 27500.0, 'diskon' => 0.0],
                ['tanggal' => '2026-01-05', 'kode_pelanggan' => 'C-10017', 'kode_produk' => 'P-000874', 'kode_cabang' => 'BR-01', 'qty' => 12, 'harga_jual' => 8450.0, 'diskon' => 500.0],
                ['tanggal' => '2026-01-05', 'kode_pelanggan' => null, 'kode_produk' => 'P-000231', 'kode_cabang' => 'BR-07', 'qty' => 1, 'harga_jual' => 27500.0, 'diskon' => 0.0],
                ['tanggal' => '2026-01-05', 'kode_pelanggan' => 'C-10880', 'kode_produk' => 'P-001455', 'kode_cabang' => 'BR-03', 'qty' => 6, 'harga_jual' => 15200.0, 'diskon' => 0.0],
                ['tanggal' => '2026-01-05', 'kode_pelanggan' => 'C-10255', 'kode_produk' => 'P-000903', 'kode_cabang' => 'BR-11', 'qty' => 2, 'harga_jual' => 63900.0, 'diskon' => 2500.0],
            ], 18420, 78, 1001, 7, 0.9412),

            $this->committed('1f0c2b74-3d5a-4c9e-9b21-8a1f6d0c0002', 'inventory', 'Stok Akhir Gudang & Toko', 'stok_akhir_gudang_toko.csv', [
                ['name' => 'kode_produk', 'dtype' => 'string', 'missing' => 0, 'missing_pct' => 0.0, 'unique' => 1860, 'sample' => 'P-000231'],
                ['name' => 'nama_barang', 'dtype' => 'string', 'missing' => 0, 'missing_pct' => 0.0, 'unique' => 1742, 'sample' => 'Minyak Goreng 1L'],
                ['name' => 'kategori', 'dtype' => 'string', 'missing' => 0, 'missing_pct' => 0.0, 'unique' => 18, 'sample' => 'Sembako'],
                ['name' => 'stok_akhir', 'dtype' => 'integer', 'missing' => 0, 'missing_pct' => 0.0, 'unique' => 214, 'sample' => 148],
                ['name' => 'stok_minimum', 'dtype' => 'integer', 'missing' => 0, 'missing_pct' => 0.0, 'unique' => 32, 'sample' => 40],
                ['name' => 'harga_beli', 'dtype' => 'float', 'missing' => 0, 'missing_pct' => 0.0, 'unique' => 908, 'sample' => 24300.0],
            ], [
                'kode_produk' => 'product_code',
                'nama_barang' => 'product_name',
                'kategori' => 'category',
                'stok_akhir' => 'stock_qty',
                'stok_minimum' => 'min_stock_qty',
                'harga_beli' => 'cost_price',
            ], [
                ['kode_produk' => 'P-000231', 'nama_barang' => 'Minyak Goreng 1L', 'kategori' => 'Sembako', 'stok_akhir' => 148, 'stok_minimum' => 40, 'harga_beli' => 24300.0],
                ['kode_produk' => 'P-000874', 'nama_barang' => 'Beras Premium 5kg', 'kategori' => 'Sembako', 'stok_akhir' => 62, 'stok_minimum' => 35, 'harga_beli' => 68200.0],
                ['kode_produk' => 'P-001455', 'nama_barang' => 'Gula Pasir 1kg', 'kategori' => 'Sembako', 'stok_akhir' => 21, 'stok_minimum' => 50, 'harga_beli' => 14900.0],
                ['kode_produk' => 'P-000903', 'nama_barang' => 'Teh Celup 25s', 'kategori' => 'Minuman', 'stok_akhir' => 305, 'stok_minimum' => 60, 'harga_beli' => 5900.0],
            ], 1860, 96, 1002, 11, 0.9685),

            $this->committed('1f0c2b74-3d5a-4c9e-9b21-8a1f6d0c0003', 'purchases', 'Pesanan Pembelian ke Supplier', 'pesanan_pembelian_supplier.csv', [
                ['name' => 'tanggal_po', 'dtype' => 'date', 'missing' => 0, 'missing_pct' => 0.0, 'unique' => 298, 'sample' => '2026-01-08'],
                ['name' => 'kode_supplier', 'dtype' => 'string', 'missing' => 0, 'missing_pct' => 0.0, 'unique' => 47, 'sample' => 'S-0031'],
                ['name' => 'kode_produk', 'dtype' => 'string', 'missing' => 0, 'missing_pct' => 0.0, 'unique' => 903, 'sample' => 'P-000231'],
                ['name' => 'qty_po', 'dtype' => 'integer', 'missing' => 0, 'missing_pct' => 0.0, 'unique' => 87, 'sample' => 240],
                ['name' => 'harga_satuan', 'dtype' => 'float', 'missing' => 0, 'missing_pct' => 0.0, 'unique' => 1104, 'sample' => 24150.0],
                ['name' => 'status_po', 'dtype' => 'string', 'missing' => 18, 'missing_pct' => 0.0056, 'unique' => 4, 'sample' => 'diterima'],
            ], [
                'tanggal_po' => 'order_date',
                'kode_supplier' => 'supplier_code',
                'kode_produk' => 'product_code',
                'qty_po' => 'order_qty',
                'harga_satuan' => 'unit_cost',
                'status_po' => 'status',
            ], [
                ['tanggal_po' => '2026-01-08', 'kode_supplier' => 'S-0031', 'kode_produk' => 'P-000231', 'qty_po' => 240, 'harga_satuan' => 24150.0, 'status_po' => 'diterima'],
                ['tanggal_po' => '2026-01-08', 'kode_supplier' => 'S-0012', 'kode_produk' => 'P-000874', 'qty_po' => 120, 'harga_satuan' => 67800.0, 'status_po' => 'diproses'],
                ['tanggal_po' => '2026-01-08', 'kode_supplier' => 'S-0031', 'kode_produk' => 'P-001455', 'qty_po' => 300, 'harga_satuan' => 14750.0, 'status_po' => 'diterima'],
                ['tanggal_po' => '2026-01-08', 'kode_supplier' => 'S-0044', 'kode_produk' => 'P-000903', 'qty_po' => 500, 'harga_satuan' => 5850.0, 'status_po' => 'dikirim'],
            ], 3240, 82, 1003, 4, 0.9127),

            $this->quarantined('1f0c2b74-3d5a-4c9e-9b21-8a1f6d0c0004', 'expenses', 'Biaya Operasional Toko', 'biaya_operasional_toko.csv', [
                ['name' => 'tanggal', 'dtype' => 'date', 'missing' => 96, 'missing_pct' => 0.0398, 'unique' => 177, 'sample' => '2026-02-01'],
                ['name' => 'kategori_biaya', 'dtype' => 'string', 'missing' => 0, 'missing_pct' => 0.0, 'unique' => 11, 'sample' => 'sewa'],
                ['name' => 'kode_cabang', 'dtype' => 'string', 'missing' => 0, 'missing_pct' => 0.0, 'unique' => 12, 'sample' => 'BR-05'],
                ['name' => 'nominal', 'dtype' => 'float', 'missing' => 0, 'missing_pct' => 0.0, 'unique' => 1841, 'sample' => 4500000.0],
                ['name' => 'keterangan', 'dtype' => 'string', 'missing' => 96, 'missing_pct' => 0.0398, 'unique' => 1602, 'sample' => 'Sewa kios Bulanan'],
            ], [
                'tanggal' => 'expense_date',
                'kategori_biaya' => 'category',
                'kode_cabang' => 'branch_code',
                'nominal' => 'amount',
                'keterangan' => 'description',
            ], [
                ['tanggal' => '2026-02-01', 'kategori_biaya' => 'sewa', 'kode_cabang' => 'BR-05', 'nominal' => 4500000.0, 'keterangan' => 'Sewa kios Bulanan'],
                ['tanggal' => '2026-02-01', 'kategori_biaya' => 'listrik', 'kode_cabang' => 'BR-01', 'nominal' => 1875000.0, 'keterangan' => 'Tagihan PLN 01/2026'],
                ['tanggal' => '2026-02-01', 'kategori_biaya' => 'transportasi', 'kode_cabang' => 'BR-07', 'nominal' => 920000.0, 'keterangan' => null],
                ['tanggal' => '2026-02-01', 'kategori_biaya' => 'gaji', 'kode_cabang' => 'BR-03', 'nominal' => 18400000.0, 'keterangan' => 'Gaji karyawan Jan 2026'],
            ], 2410, 88, 1004, 0.4216),

            $this->importing('1f0c2b74-3d5a-4c9e-9b21-8a1f6d0c0005', 'customers', 'Master Pelanggan & Segmen', 'master_pelanggan_segmen.csv', [
                ['name' => 'kode_pelanggan', 'dtype' => 'string', 'missing' => 0, 'missing_pct' => 0.0, 'unique' => 5620, 'sample' => 'C-10432'],
                ['name' => 'nama_pelanggan', 'dtype' => 'string', 'missing' => 0, 'missing_pct' => 0.0, 'unique' => 5410, 'sample' => 'Toko Berkah Jaya'],
                ['name' => 'kota', 'dtype' => 'string', 'missing' => 0, 'missing_pct' => 0.0, 'unique' => 27, 'sample' => 'Bandung'],
                ['name' => 'segment', 'dtype' => 'string', 'missing' => 0, 'missing_pct' => 0.0, 'unique' => 5, 'sample' => 'loyal'],
                ['name' => 'email', 'dtype' => 'string', 'missing' => 431, 'missing_pct' => 0.0766, 'unique' => 5189, 'sample' => 'berkah.jaya@example.co.id'],
                ['name' => 'telepon', 'dtype' => 'string', 'missing' => 0, 'missing_pct' => 0.0, 'unique' => 5502, 'sample' => '0812-3456-7890'],
            ], [
                'kode_pelanggan' => 'customer_code',
                'nama_pelanggan' => 'customer_name',
                'kota' => 'city',
                'segment' => 'segment',
                'email' => 'email',
                'telepon' => 'phone',
            ], [
                ['kode_pelanggan' => 'C-10432', 'nama_pelanggan' => 'Toko Berkah Jaya', 'kota' => 'Bandung', 'segment' => 'loyal', 'email' => 'berkah.jaya@example.co.id', 'telepon' => '0812-3456-7890'],
                ['kode_pelanggan' => 'C-10017', 'nama_pelanggan' => 'CV Sinar Abadi', 'kota' => 'Jakarta', 'segment' => 'champions', 'email' => 'sinar.abadi@example.co.id', 'telepon' => '0813-2211-0045'],
                ['kode_pelanggan' => 'C-10880', 'nama_pelanggan' => 'Warung Bu Imas', 'kota' => 'Surabaya', 'segment' => 'potential', 'email' => null, 'telepon' => '0857-9012-3344'],
                ['kode_pelanggan' => 'C-10255', 'nama_pelanggan' => 'Toko Maju Bersama', 'kota' => 'Semarang', 'segment' => 'at_risk', 'email' => 'maju.bersama@example.co.id', 'telepon' => '0821-6677-8890'],
            ], 5620, 92, 1005),

            $this->failed('1f0c2b74-3d5a-4c9e-9b21-8a1f6d0c0006', 'generic', 'Data Umum Apotek', 'data_umum_apotek.csv', [
                ['name' => 'id', 'dtype' => 'integer', 'missing' => 0, 'missing_pct' => 0.0, 'unique' => 1200, 'sample' => 1],
                ['name' => 'label', 'dtype' => 'string', 'missing' => 0, 'missing_pct' => 0.0, 'unique' => 1187, 'sample' => 'Kanal Apotek'],
                ['name' => 'nilai', 'dtype' => 'float', 'missing' => 24, 'missing_pct' => 0.02, 'unique' => 943, 'sample' => 128450.0],
                ['name' => 'dibuat_pada', 'dtype' => 'datetime', 'missing' => 0, 'missing_pct' => 0.0, 'unique' => 1200, 'sample' => '2026-01-15 08:30:00'],
            ], [
                'id' => 'id',
                'label' => 'label',
                'nilai' => 'value',
                'dibuat_pada' => 'created_at_source',
            ], [
                ['id' => 1, 'label' => 'Kanal Apotek', 'nilai' => 128450.0, 'dibuat_pada' => '2026-01-15 08:30:00'],
                ['id' => 2, 'label' => 'Kanal Grosir', 'nilai' => 964200.0, 'dibuat_pada' => '2026-01-15 08:31:12'],
                ['id' => 3, 'label' => 'Kanal Online', 'nilai' => null, 'dibuat_pada' => '2026-01-15 08:32:47'],
                ['id' => 4, 'label' => 'Kanal Reseller', 'nilai' => 512800.0, 'dibuat_pada' => '2026-01-15 08:33:03'],
            ], 1200, 64, 1006),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $columns
     * @param  array<string, string>  $mappings
     * @param  list<array<string, mixed>>  $sampleRows
     * @return array<string, mixed>
     */
    protected function committed(string $uuid, string $type, string $name, string $filename, array $columns, array $mappings, array $sampleRows, int $rows, int $bytesPerRow, int $importJobId, int $committedDaysAgo, float $score): array
    {
        return [
            'uuid' => $uuid,
            'owner' => 'analyst',
            'name' => $name,
            'dataset_type' => $type,
            'source_filename' => $filename,
            'disk' => 'local',
            'path' => 'datasets/'.$filename,
            'size_bytes' => $rows * $bytesPerRow,
            'mime' => 'text/csv',
            'checksum_sha256' => hash('sha256', $filename),
            'status' => DatasetStatus::Committed,
            'import_job_id' => $importJobId,
            'row_count' => $rows,
            'column_count' => count($columns),
            'columns' => $columns,
            'mappings' => $mappings,
            'metadata' => [
                'preview' => $this->preview($filename, $sampleRows),
                'quality' => $this->quality($score, []),
            ],
            'quality_score' => $score,
            'quality_verdict' => QualityVerdict::Pass->value,
            'quality_checked_at' => Carbon::now()->subDays($committedDaysAgo)->subMinutes(12),
            'committed_at' => Carbon::now()->subDays($committedDaysAgo),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $columns
     * @param  array<string, string>  $mappings
     * @param  list<array<string, mixed>>  $sampleRows
     * @return array<string, mixed>
     */
    protected function quarantined(string $uuid, string $type, string $name, string $filename, array $columns, array $mappings, array $sampleRows, int $rows, int $bytesPerRow, int $importJobId, float $score): array
    {
        return [
            'uuid' => $uuid,
            'owner' => 'analyst',
            'name' => $name,
            'dataset_type' => $type,
            'source_filename' => $filename,
            'disk' => 'local',
            'path' => 'datasets/'.$filename,
            'size_bytes' => $rows * $bytesPerRow,
            'mime' => 'text/csv',
            'checksum_sha256' => hash('sha256', $filename),
            'status' => DatasetStatus::Quarantined,
            'import_job_id' => $importJobId,
            'row_count' => $rows,
            'column_count' => count($columns),
            'columns' => $columns,
            'mappings' => $mappings,
            'metadata' => [
                'preview' => $this->preview($filename, $sampleRows),
                'quality' => $this->quality($score, [
                    [
                        'rule' => 'high_null_rate',
                        'column' => 'keterangan',
                        'count' => 96,
                        'sample_rows' => [3],
                        'message' => '96 nilai kosong pada kolom keterangan (4.0%)',
                    ],
                    [
                        'rule' => 'invalid_value',
                        'column' => 'tanggal',
                        'count' => 96,
                        'sample_rows' => [2],
                        'message' => '96 nilai tanggal tidak dapat diparsing menjadi tanggal (contoh: "31/02/2026")',
                    ],
                    [
                        'rule' => 'duplicate_rows',
                        'column' => null,
                        'count' => 214,
                        'sample_rows' => [8, 9, 10],
                        'message' => '214 baris duplik terdeteksi pada 12 hari yang sama',
                    ],
                    [
                        'rule' => 'outlier_zscore',
                        'column' => 'nominal',
                        'count' => 31,
                        'sample_rows' => [55],
                        'message' => '31 nilai ekstrem pada kolom nominal (|z|>4), kemungkinan input 1000x',
                    ],
                ]),
            ],
            'quality_score' => $score,
            'quality_verdict' => QualityVerdict::Quarantine->value,
            'quality_checked_at' => Carbon::now()->subDays(2)->subMinutes(41),
            'committed_at' => null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $columns
     * @param  array<string, string>  $mappings
     * @param  list<array<string, mixed>>  $sampleRows
     * @return array<string, mixed>
     */
    protected function importing(string $uuid, string $type, string $name, string $filename, array $columns, array $mappings, array $sampleRows, int $rows, int $bytesPerRow, int $importJobId): array
    {
        return [
            'uuid' => $uuid,
            'owner' => 'admin',
            'name' => $name,
            'dataset_type' => $type,
            'source_filename' => $filename,
            'disk' => 'local',
            'path' => 'datasets/'.$filename,
            'size_bytes' => $rows * $bytesPerRow,
            'mime' => 'text/csv',
            'checksum_sha256' => hash('sha256', $filename),
            'status' => DatasetStatus::Importing,
            'import_job_id' => $importJobId,
            'row_count' => $rows,
            'column_count' => count($columns),
            'columns' => $columns,
            'mappings' => $mappings,
            'metadata' => [
                'preview' => $this->preview($filename, $sampleRows),
                'import' => [
                    'stage' => 'staging',
                    'rows_processed' => 3184,
                    'rows_total' => $rows,
                    'progress_pct' => round(3184 / $rows * 100, 1),
                    'started_at' => Carbon::now()->subMinutes(3)->toIso8601String(),
                ],
            ],
            'quality_score' => null,
            'quality_verdict' => null,
            'quality_checked_at' => null,
            'committed_at' => null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $columns
     * @param  array<string, string>  $mappings
     * @param  list<array<string, mixed>>  $sampleRows
     * @return array<string, mixed>
     */
    protected function failed(string $uuid, string $type, string $name, string $filename, array $columns, array $mappings, array $sampleRows, int $rows, int $bytesPerRow, int $importJobId): array
    {
        return [
            'uuid' => $uuid,
            'owner' => 'admin',
            'name' => $name,
            'dataset_type' => $type,
            'source_filename' => $filename,
            'disk' => 'local',
            'path' => 'datasets/'.$filename,
            'size_bytes' => $rows * $bytesPerRow,
            'mime' => 'text/csv',
            'checksum_sha256' => hash('sha256', $filename),
            'status' => DatasetStatus::Failed,
            'import_job_id' => $importJobId,
            'row_count' => $rows,
            'column_count' => count($columns),
            'columns' => $columns,
            'mappings' => $mappings,
            'metadata' => [
                'preview' => $this->preview($filename, $sampleRows),
                'error' => 'Impor gagal pada tahap raw -> staging: kolom "dibuat_pada" berisi 412 nilai yang tidak dapat diparsing menjadi datetime (contoh: "15-01-2026 08.30").',
                'failed_at' => Carbon::now()->subHours(9)->toIso8601String(),
            ],
            'quality_score' => null,
            'quality_verdict' => null,
            'quality_checked_at' => null,
            'committed_at' => null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $sampleRows
     * @return array<string, mixed>
     */
    protected function preview(string $filename, array $sampleRows): array
    {
        return [
            'source' => $filename,
            'encoding' => 'utf-8',
            'delimiter' => ',',
            'has_header' => true,
            'sample_rows' => $sampleRows,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $issues
     * @return array<string, mixed>
     */
    protected function quality(float $score, array $issues): array
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
}
