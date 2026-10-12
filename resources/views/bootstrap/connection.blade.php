@php
    $badge = $presenter->badge();
    $tone = ['success' => 'success', 'warning' => 'warning', 'danger' => 'danger', 'neutral' => 'secondary'][$badge['tone']];
@endphp
<div {{ ($attributes ?? new \Illuminate\View\ComponentAttributeBag)->class('card shadow-sm') }}>
    <div class="card-body">
        <h5 class="card-title d-flex align-items-center gap-2">
            <span class="d-inline-flex flex-shrink-0" style="width:1.25rem;height:1.25rem;color:{{ $brand['color'] }}">{!! $brand['logo'] !!}</span>
            {{ $label }}
        </h5>

        @if ($hint ?? null)
            <p class="small text-muted mt-n1">{{ $hint }}</p>
        @endif

        @include('social-autopost::bootstrap.flash', ['flash' => $flash])

        @if (! $connection->connected)
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
                <div>
                    <p class="mb-0 fw-semibold">Not connected</p>
                    <p class="mb-0 text-muted">Connect a {{ $label }} account to share your content.</p>
                </div>
                @if ($canManage)
                    <a href="{{ $connectUrl }}" class="btn text-white" style="background-color:{{ $brand['color'] }}">Connect {{ $label }}</a>
                @endif
            </div>
        @else
            <div class="d-flex flex-wrap align-items-center gap-3">
                @if ($connection->picture)
                    <img src="{{ $connection->picture }}" alt="" width="48" height="48" class="rounded-circle" referrerpolicy="no-referrer">
                @else
                    <span aria-hidden="true" class="rounded-circle d-inline-flex align-items-center justify-content-center text-white fw-semibold" style="width:48px;height:48px;background-color:{{ $brand['color'] }}">{{ $presenter->initials() }}</span>
                @endif

                <div class="flex-grow-1 text-truncate">
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <span class="fw-semibold">{{ $presenter->displayName() }}</span>
                        <span class="badge text-bg-{{ $tone }}">{{ $badge['label'] }}</span>
                    </div>
                    @if ($connection->email)
                        <div class="text-muted text-truncate">{{ $connection->email }}</div>
                    @endif
                    <small class="text-muted">
                        @if ($presenter->connectedOn()) Connected {{ $presenter->connectedOn() }} @endif
                        @if ($presenter->connectedOn() && $presenter->validUntil()) · @endif
                        @if ($presenter->validUntil()) Valid until {{ $presenter->validUntil() }} @endif
                    </small>
                </div>

                @if ($canManage)
                    <div class="d-flex gap-2">
                        <a href="{{ $connectUrl }}" class="btn {{ $presenter->needsReconnect() ? 'text-white' : 'btn-outline-secondary' }}" @if ($presenter->needsReconnect()) style="background-color:{{ $brand['color'] }}" @endif>Reconnect</a>

                        @if ($livewire)
                            <button type="button" class="btn btn-outline-danger" wire:click="disconnect" wire:confirm="{{ $confirmText }}">Disconnect</button>
                        @else
                            <form method="POST" action="{{ $disconnectUrl }}" onsubmit="return confirm(@js($confirmText))">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn-outline-danger">Disconnect</button>
                            </form>
                        @endif
                    </div>
                @endif
            </div>

            @if ($connection->status === 'unknown')
                <p class="small text-muted mt-2 mb-0">{{ $label }} could not be asked about this connection just now; it may still work.</p>
            @endif
        @endif
    </div>
</div>
