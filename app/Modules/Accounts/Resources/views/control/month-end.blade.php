{{--
    মাস-শেষের চেকলিস্ট — [[MonthEndChecklist]]।

    ⓘ ফিন্যান্স মানচিত্রের §২৪। প্রতিটা সারি তিন অবস্থার একটা: ঠিক আছে,
    বাকি (কতগুলো, আর কোথায় সারানো যায়), বা এই মাসে প্রযোজ্য নয়। রঙ একা
    কিছু বলে না: আইকন আর লেখাও বলে।
--}}
@php
    use App\Modules\Accounts\Services\MonthEndChecklist;

    $pending = collect($checks)->where('state', MonthEndChecklist::PENDING)->count();
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('accounts::control.month_end_title') }}</x-slot:title>

    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <form method="GET" class="contents">
            <x-ui.toolbar :title="__('accounts::control.month_end_title')"
                          :subtitle="__('accounts::control.month_end_note')"
                          :search="false" :filter="false" :density="false"
                          :export="false" :share="false" :quiet="['month']">
                <x-slot:actions>
                    <label class="flex items-center gap-2 text-sm">
                        <span class="text-(--color-ink-muted)">{{ __('accounts::control.month') }}</span>
                        <input type="month" name="month" value="{{ $month->format('Y-m') }}"
                               class="min-h-(--spacing-touch) rounded-(--radius-field) border border-(--color-border)
                                      bg-(--color-surface-card) px-2">
                    </label>
                    <x-ui.button type="submit">{{ __('accounts::control.show') }}</x-ui.button>
                </x-slot:actions>
            </x-ui.toolbar>
        </form>

        <ul class="divide-y divide-(--color-border)" data-month-end="{{ $month->format('Y-m') }}" data-pending="{{ $pending }}">
            @foreach ($checks as $check)
                @php
                    [$label, $hint] = __('accounts::control.checks.'.$check['key']);
                    $ok = $check['state'] === MonthEndChecklist::OK;
                    $na = $check['state'] === MonthEndChecklist::NOT_APPLICABLE;
                @endphp

                <li class="flex items-start gap-3 px-4 py-3" data-check="{{ $check['key'] }}" data-state="{{ $check['state'] }}">
                    <span @class([
                        'mt-0.5',
                        'text-(--color-success)' => $ok,
                        'text-(--color-ink-disabled)' => $na,
                        'text-(--color-danger)' => ! $ok && ! $na,
                    ])>
                        <x-ui.icon :name="$ok ? 'check-circle' : ($na ? 'clock' : 'alert-triangle')" />
                    </span>

                    <div class="min-w-0 flex-1">
                        <p class="font-medium">{{ $label }}</p>
                        <p class="mt-0.5 text-2xs text-(--color-ink-muted)">{{ $hint }}</p>
                    </div>

                    <span @class([
                        'shrink-0 rounded-(--radius-pill) px-2.5 py-1 text-2xs font-medium',
                        'bg-(--color-badge-success-bg) text-(--color-badge-success-ink)' => $ok,
                        'bg-(--color-surface-sunken) text-(--color-ink-muted)' => $na,
                        'bg-(--color-badge-danger-bg) text-(--color-badge-danger-ink)' => ! $ok && ! $na,
                    ])>
                        {{ $ok
                            ? __('accounts::control.state_ok')
                            : ($na ? __('accounts::control.state_na') : __('accounts::control.state_pending', ['count' => $check['count']])) }}
                    </span>

                    @if (! $ok && ! $na && Route::has($check['route']))
                        <a href="{{ route($check['route'], $check['params']) }}"
                           class="shrink-0 text-sm text-(--color-link) hover:underline">{{ __('accounts::control.go_fix') }}</a>
                    @endif
                </li>
            @endforeach
        </ul>
    </div>
</x-layouts.app>
