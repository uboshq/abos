{{-- অবস্থা — শোধ, চলছে, নাকি তারিখ পেরিয়েছে।

     ⓘ তারিখটা পরের কিস্তির (`next_due_on`), না থাকলে চুক্তির শেষ দিন —
     "কার কাছে এখন টাকা চাইতে হবে" প্রশ্নের উত্তর ঐটাই। --}}
@php
    $account = $row['account'];
    $due = $account->next_due_on ?? $account->due_on;
    $clear = bccomp((string) $row['balance'], '0', 4) === 0;
    $late = ! $clear && $due !== null && $due->lt(now()->startOfDay());
@endphp

<span @class([
    'inline-flex rounded-full px-2 py-0.5 text-2xs font-medium',
    'bg-(--color-badge-success-bg) text-(--color-badge-success-ink)' => $clear,
    'bg-(--color-badge-danger-bg) text-(--color-badge-danger-ink)' => $late,
    'bg-(--color-surface-sunken) text-(--color-ink-muted)' => ! $clear && ! $late,
])>
    {{ __('finance::field.hl_'.($clear ? 'clear' : ($late ? 'overdue' : 'running'))) }}
</span>
