<?php

namespace App\Http\Controllers;

use App\Enums\DatasetStatus;
use App\Models\AuditLog;
use App\Models\Dataset;
use App\Services\DatasetIngestionService;
use App\Support\ApiResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class DatasetController extends Controller
{
    public function __construct(private readonly DatasetIngestionService $ingestion) {}

    public function index(Request $request): View
    {
        $datasets = Dataset::query()
            ->ofType($request->query('dataset_type'))
            ->withStatus($request->query('status'))
            ->when($request->filled('q'), function ($query) use ($request): void {
                $term = '%'.$request->string('q')->trim().'%';
                $query->where(function ($inner) use ($term): void {
                    $inner->whereLike('name', $term, caseSensitive: false)
                        ->orWhereLike('source_filename', $term, caseSensitive: false);
                });
            })
            ->orderByDesc('created_at')
            ->paginate(ApiResponse::perPage())
            ->withQueryString();

        return view('datasets.index', [
            'datasets' => $datasets,
            'datasetTypes' => config('ai_engine.dataset_types'),
            'statuses' => DatasetStatus::cases(),
            'filters' => $request->only(['q', 'dataset_type', 'status']),
        ]);
    }

    public function create(): View
    {
        return view('datasets.create', [
            'datasetTypes' => config('ai_engine.dataset_types'),
            'maxUploadMb' => config('ai_engine.max_upload_mb'),
            'allowedExtensions' => config('ai_engine.allowed_extensions'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        // `max:` on a file rule is KILOBYTES, while `ai_engine.max_upload_mb`
        // is megabytes (and nginx/PHP enforce the same MB budget). Passing the
        // raw MB value capped every upload at ~500 KB instead of 500 MB.
        $maxMb = max(1, (int) config('ai_engine.max_upload_mb'));
        $maxKb = $maxMb * 1024;

        $validated = $request->validate([
            'file' => [
                'required',
                'file',
                'max:'.$maxKb,
                'extensions:'.implode(',', config('ai_engine.allowed_extensions')),
                // Fail-closed filename screen: the stored path is always
                // server-generated, but the client filename is persisted as
                // `source_filename`, echoed in the UI/audit log and forwarded
                // to the engine as the multipart filename. Reject path
                // components and double extensions with an executable payload
                // (`sales.csv.php`, `report..csv`) before anything is stored.
                function (string $attribute, mixed $value, callable $fail): void {
                    if (! $value instanceof UploadedFile) {
                        return;
                    }

                    $original = (string) $value->getClientOriginalName();
                    $reason = self::unsafeFilenameReason($original);

                    if ($reason !== null) {
                        $fail($reason);
                    }
                },
            ],
            'name' => ['nullable', 'string', 'max:150'],
            'dataset_type' => ['required', 'string', 'in:'.implode(',', config('ai_engine.dataset_types'))],
        ], [], [
            'file' => 'berkas',
            'name' => 'nama dataset',
            'dataset_type' => 'tipe dataset',
        ]);

        $file = $request->file('file');

        // Defence in depth: the `max:` rule above already enforces the budget,
        // but re-check in bytes here so an oversized file can never reach the
        // synchronous engine round trip even if validation is refactored.
        $maxBytes = $maxMb * 1024 * 1024;

        if ($file->getSize() !== false && (int) $file->getSize() > $maxBytes) {
            throw ValidationException::withMessages([
                'file' => "Berkas melebihi batas {$maxMb} MB.",
            ]);
        }

        $name = $validated['name'] ?? null;
        $name = $name !== null && trim($name) !== ''
            ? mb_substr(trim($name), 0, 150)
            : self::safeDatasetName($file->getClientOriginalName());

        $dataset = $this->ingestion->createFromUpload(
            $file,
            $name,
            $validated['dataset_type'],
            $request->user(),
        );

        // `DatasetIngestionService` (not A8-owned) persists the raw client
        // filename as `source_filename`. Re-sanitize the row here when it
        // differs, so traversal/decoration never survives in the database.
        // No-op for normal names (`sales.csv` round-trips unchanged), and only
        // then — so the common path issues no extra query.
        $safeOriginal = self::sanitizeFilename($file->getClientOriginalName());

        if ($safeOriginal !== $dataset->source_filename || $name !== $dataset->name) {
            $dataset->forceFill([
                'source_filename' => $safeOriginal !== '' ? $safeOriginal : $dataset->source_filename,
                'name' => $name !== '' ? $name : $dataset->name,
            ])->save();
        }

        return redirect()
            ->route('datasets.show', $dataset)
            ->with('status', "Dataset \"{$dataset->name}\" diterima. Jalankan preview untuk memprofil datanya.");
    }

    public function show(Request $request, Dataset $dataset): View
    {
        return view('datasets.show', [
            'dataset' => $dataset,
            'sampleRows' => (array) data_get((array) $dataset->metadata, 'preview.sample_rows', []),
            'quality' => (array) data_get((array) $dataset->metadata, 'quality', []),
            'threshold' => config('ai_engine.quality_threshold'),
        ]);
    }

    public function destroy(Request $request, Dataset $dataset): RedirectResponse
    {
        Gate::authorize('delete', $dataset);

        $name = $dataset->name;
        $size = $dataset->size_bytes;
        $filename = $dataset->source_filename;

        if ($dataset->path) {
            Storage::disk($dataset->disk)->delete($dataset->path);
        }

        $dataset->delete();

        // Deleting a dataset also removes the uploaded file from disk, with no
        // undo. Without this the audit trail records nothing for the one
        // destructive action an analyst can take.
        AuditLog::record('dataset.deleted', 'dataset', $dataset->getKey(), [
            'name' => $name,
            'source_filename' => $filename,
            'size_bytes' => $size,
        ]);

        return redirect()
            ->route('datasets.index')
            ->with('status', "Dataset \"{$name}\" dihapus.");
    }

    /**
     * Reason a client-supplied filename is unsafe, or null when acceptable.
     * Used as a validation closure so the refusal is a 422/session error,
     * never an exception — success behaviour for normal names is unchanged.
     */
    public static function unsafeFilenameReason(string $original): ?string
    {
        if ($original === '' || str_contains($original, "\0")) {
            return 'Nama berkas tidak valid.';
        }

        // Any directory separator means a path was sent, not a name. Browsers
        // only ever send a bare filename, so this rejects nothing legitimate.
        if (str_contains(str_replace('\\', '/', $original), '/')) {
            return 'Nama berkas tidak boleh mengandung path.';
        }

        // Control characters and leading dots (hidden/parent-relative names).
        if ((bool) preg_match('/[\x00-\x1F\x7F]/', $original)) {
            return 'Nama berkas tidak valid.';
        }

        if (str_starts_with($original, '.')) {
            return 'Nama berkas tidak valid.';
        }

        // Double extension with an executable payload: `sales.csv.php`,
        // `data.txt.sh`, `report.csv.exe`. The final extension may be
        // allow-listed while the payload rides in the middle.
        $parts = explode('.', $original);

        if (count($parts) >= 3) {
            $risky = [
                'php', 'phtml', 'phar', 'php3', 'php4', 'php5', 'php7',
                'exe', 'msi', 'bat', 'cmd', 'com', 'scr', 'ps1',
                'sh', 'bash', 'zsh', 'py', 'pl', 'rb', 'jsp',
                'asp', 'aspx', 'dll', 'so', 'dylib',
                'js', 'html', 'htm', 'svg', 'swf',
            ];

            foreach (array_slice($parts, 1, -1) as $middle) {
                if (in_array(strtolower($middle), $risky, true)) {
                    return 'Nama berkas tidak diperbolehkan (ekstensi ganda).';
                }
            }
        }

        if (strlen($original) > 255) {
            return 'Nama berkas terlalu panjang.';
        }

        return null;
    }

    /**
     * Strip a client filename down to a safe bare name. Idempotent for normal
     * names: `sanitizeFilename('sales.csv') === 'sales.csv'`.
     */
    public static function sanitizeFilename(string $original): string
    {
        $base = basename(str_replace('\\', '/', $original));
        // Null bytes + control characters + parent segments.
        $base = str_replace("\0", '', $base);
        $base = (string) preg_replace('/[\x00-\x1F\x7F]/', '', $base);
        $base = ltrim($base, '.');
        $base = trim($base);

        if ($base === '' || $base === '.' || $base === '..') {
            return 'upload.dat';
        }

        // Preserve the extension while truncating to 255 bytes.
        if (strlen($base) > 255) {
            $ext = pathinfo($base, PATHINFO_EXTENSION);
            $stem = mb_substr(pathinfo($base, PATHINFO_FILENAME), 0, 200);
            $base = $ext !== '' ? $stem.'.'.mb_substr($ext, 0, 10) : $stem;
        }

        return $base;
    }

    /**
     * Derive a dataset display name from the client filename without letting
     * traversal or decoration leak into the `datasets.name` column.
     */
    public static function safeDatasetName(string $original): string
    {
        $safe = self::sanitizeFilename($original);
        $stem = pathinfo($safe, PATHINFO_FILENAME);

        $stem = trim($stem);

        if ($stem === '') {
            return 'Dataset tanpa nama';
        }

        return mb_substr($stem, 0, 150);
    }
}
