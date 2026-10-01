<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\PlatformHealth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

/**
 * System Health dashboard (admin only).
 *
 * Reuses {@see PlatformHealth} — the same 17 checks as `platform:doctor` —
 * cached for 60 seconds so one slow engine call cannot stall every page
 * render. A total failure still renders the page (degraded) instead of 500.
 */
class SystemHealthController extends Controller
{
    public function __construct(private readonly PlatformHealth $health) {}

    public function index(): View
    {
        try {
            $report = Cache::remember(
                'system.health.report',
                now()->addSeconds(60),
                fn (): array => $this->health->report(),
            );
            $failed = false;
        } catch (Throwable $exception) {
            Log::warning('system.health_failed', [
                'error' => substr($exception->getMessage(), 0, 160),
            ]);
            $report = [];
            $failed = true;
        }

        $grouped = [];

        foreach ($report as $name => $check) {
            $grouped[$check['group'] ?? 'other'][$name] = $check;
        }

        ksort($grouped);

        return view('admin.system-health.index', [
            'failed' => $failed,
            'status' => $failed ? 'down' : $this->health->overallStatus($report),
            'counts' => $failed
                ? ['ok' => 0, 'warn' => 0, 'down' => 0]
                : $this->health->counts($report),
            'grouped' => $grouped,
            'checkedAt' => now()->toIso8601String(),
        ]);
    }
}
