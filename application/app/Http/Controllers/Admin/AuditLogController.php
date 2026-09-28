<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AuditLogController extends Controller
{
    public function index(Request $request): View
    {
        $logs = AuditLog::query()
            ->when($request->filled('action'), function ($query) use ($request): void {
                $query->where('action', (string) $request->query('action'));
            })
            ->when($request->filled('actor'), function ($query) use ($request): void {
                // `whereLike` compiles to ILIKE on pgsql and LIKE elsewhere, so
                // the case-insensitive filter also works on the sqlite test DB.
                $query->whereLike('actor', '%'.$request->string('actor')->trim().'%', caseSensitive: false);
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(ApiResponse::perPage())
            ->withQueryString();

        $actions = AuditLog::query()
            ->whereNotNull('action')
            ->distinct()
            ->orderBy('action')
            ->pluck('action');

        return view('audit.index', [
            'logs' => $logs,
            'actions' => $actions,
            'filters' => $request->only(['action', 'actor']),
        ]);
    }
}
