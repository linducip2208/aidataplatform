@if (session('status'))
    <div role="status" class="alert alert-success">
        {{ session('status') }}
    </div>
@endif

@if (session('error'))
    <div role="alert" class="alert alert-danger">
        {{ session('error') }}
    </div>
@endif
