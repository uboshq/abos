{{-- চালু না বন্ধ — নিষ্ক্রিয় ধরন নতুন কাগজে আর আসে না, পুরনোগুলো অটুট। --}}
<span @class([
    'inline-flex rounded-full px-2 py-0.5 text-2xs font-medium',
    'bg-(--color-badge-success-bg) text-(--color-badge-success-ink)' => $kind->is_active,
    'bg-(--color-surface-sunken) text-(--color-ink-muted)' => ! $kind->is_active,
])>
    {{ __('finance::state.'.($kind->is_active ? 'active' : 'closed')) }}
</span>
