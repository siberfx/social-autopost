@if ($flash)
    <div role="{{ $flash['type'] === 'error' ? 'alert' : 'status' }}"
         class="rounded-lg border px-4 py-3 text-sm {{ $flash['type'] === 'error' ? 'border-red-200 bg-red-50 text-red-800 dark:border-red-900 dark:bg-red-950 dark:text-red-200' : 'border-green-200 bg-green-50 text-green-800 dark:border-green-900 dark:bg-green-950 dark:text-green-200' }}">
        {{ $flash['message'] }}
    </div>
@endif
