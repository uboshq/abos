{{-- হেফাজতকারী নেই মানে ঘাটতি হলে কেউ দায়ী নয়, আর কেউ দায়ী না হলে
     ঘাটতি নিয়ে কেউ প্রশ্নও করে না। খালি ঘর রেখে দিলে ওটা চোখেই পড়ত
     না, তাই লেখা হয়। --}}
@if ($row['holder'])
    {{ $row['holder'] }}
@elseif ($row['kind'] === __('accounts::custody.kind_bank'))
    <span class="text-(--color-ink-disabled)">—</span>
@else
    <span class="inline-flex items-center gap-1 rounded-full bg-(--color-badge-warning-bg)
                 px-2 py-0.5 text-2xs font-semibold text-(--color-badge-warning-ink)">
        <x-ui.icon name="help" :size="12" />
        {{ __('accounts::custody.nobody') }}
    </span>
@endif
