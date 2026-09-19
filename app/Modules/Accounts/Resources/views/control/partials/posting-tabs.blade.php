{{--
    পোস্টিং মনিটরের তিন ট্যাব — খাতায় উঠেছে · আটকে আছে · ব্যর্থ।

    ⓘ "ব্যর্থ" নিজের রুটে (accounts.control.failed), কারণ মানচিত্রের §৩১
    "ব্যর্থ পোস্টিংয়ের সারি" একটা আলাদা লাইন, আর মানচিত্র লাইন ধরে রুট চায়।
    দেখতে তবু একই পর্দার ট্যাব।
--}}
@php
    $stuckCount ??= null;
    $failedCount ??= null;

    $tabs = [
        'posted' => [__('accounts::control.tab_posted'), route('accounts.control.posting'), null],
        'stuck' => [__('accounts::control.tab_stuck'), route('accounts.control.posting', ['tab' => 'stuck']), $stuckCount],
        'failed' => [__('accounts::control.tab_failed'), route('accounts.control.failed'), $failedCount],
    ];
@endphp

<nav class="flex flex-wrap gap-1 border-b border-(--color-border) px-2 text-sm"
     aria-label="{{ __('accounts::control.posting_title') }}">
    @foreach ($tabs as $key => [$label, $url, $count])
        <a href="{{ $url }}"
           @if ($active === $key) aria-current="page" @endif
           class="-mb-px flex min-h-(--spacing-touch) items-center gap-2 border-b-2 px-3
                  {{ $active === $key
                      ? 'border-(--color-brand-500) font-semibold text-(--color-ink)'
                      : 'border-transparent text-(--color-ink-muted) hover:text-(--color-ink)' }}">
            {{ $label }}
            @if ($count !== null)
                <span @class([
                    'rounded-full px-2 text-2xs',
                    'bg-(--color-badge-danger-bg) text-(--color-badge-danger-ink)' => $count > 0,
                    'bg-(--color-surface-sunken) text-(--color-ink-muted)' => $count === 0,
                ])>{{ $count }}</span>
            @endif
        </a>
    @endforeach
</nav>
