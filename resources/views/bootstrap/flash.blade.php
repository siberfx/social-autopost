@if ($flash)
    <div class="alert {{ $flash['type'] === 'error' ? 'alert-danger' : 'alert-success' }}" role="{{ $flash['type'] === 'error' ? 'alert' : 'status' }}">
        {{ $flash['message'] }}
    </div>
@endif
