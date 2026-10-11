@php
    $reason = $presenter->disabledReason();
    $size = $size === 'sm' ? 'btn-sm' : '';
@endphp
<span class="d-inline-flex flex-column align-items-start gap-1">
    @if ($livewire)
        <button type="button" class="btn btn-outline-secondary {{ $size }}" wire:click="share" @if ($confirm && ! $reason) wire:confirm="{{ $presenter->confirmText() }}" @endif
                @disabled($reason) title="{{ $presenter->title() }}" wire:loading.attr="disabled">
            <span class="d-inline-flex align-middle me-1" style="width:1em;height:1em">{!! $presenter->logo() !!}</span>{{ $presenter->text() }}
        </button>
    @else
        <form method="POST" action="{{ $presenter->signedAction() }}" class="d-inline" @if ($confirm && ! $reason) onsubmit="return confirm(@js($presenter->confirmText()))" @endif>
            @csrf
            <button type="submit" class="btn btn-outline-secondary {{ $size }}" @disabled($reason) title="{{ $presenter->title() }}">
                <span class="d-inline-flex align-middle me-1" style="width:1em;height:1em">{!! $presenter->logo() !!}</span>{{ $presenter->text() }}
            </button>
        </form>
    @endif
    @if ($reason)
        <span class="visually-hidden">{{ $reason }}</span>
    @endif
    @if ($flash)
        <small class="{{ $flash['type'] === 'error' ? 'text-danger' : 'text-success' }}">{{ $flash['message'] }}</small>
    @endif
</span>
