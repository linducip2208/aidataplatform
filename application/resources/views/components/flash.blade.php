@if (session('status'))
    <div
        role="status"
        class="mb-4 rounded-lg border border-emerald-600/30 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-900"
    >
        {{ session('status') }}
    </div>
@endif

@if (session('error'))
    <div
        role="alert"
        class="mb-4 rounded-lg border border-rose-600/30 bg-rose-50 px-4 py-3 text-sm font-medium text-rose-900"
    >
        {{ session('error') }}
    </div>
@endif
