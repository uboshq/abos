{{--
    ডুপ্লিকেট খোঁজা — ফিন্যান্স মানচিত্রের §৩৩ ([[DuplicateParties]])।

    ⓘ প্রতিটা দল একটা বাক্স: কী মিলেছে (মোবাইল বা নাম), আর সারিগুলো, প্রতিটা
    নিজের পাতায় খোলে। ⛔ জোড়া লাগানোর বোতাম নেই, ইচ্ছে করে।
--}}
@php
    $open = fn (string $kind, object $row) => match ($kind) {
        'customer' => route('customer.show', $row->id),
        'supplier' => route('supplier.show', $row->id),
        default => route('master_data.person.edit', ['id' => $row->id]),
    };
@endphp

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('accounts::control.duplicates_title') }}</x-slot:title>

    <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
        <x-ui.toolbar :title="__('accounts::control.duplicates_title')"
                      :subtitle="__('accounts::control.duplicates_note')"
                      :search="false" :filter="false" :density="false"
                      :export="false" :share="false" />

        <nav class="flex flex-wrap gap-1 border-b border-(--color-border) px-2 text-sm"
             aria-label="{{ __('accounts::control.duplicates_title') }}">
            @foreach ($counts as $key => $count)
                <a href="{{ route('accounts.control.duplicates', ['tab' => $key]) }}"
                   @if ($tab === $key) aria-current="page" @endif
                   class="-mb-px flex min-h-(--spacing-touch) items-center gap-2 border-b-2 px-3
                          {{ $tab === $key
                              ? 'border-(--color-brand-500) font-semibold text-(--color-ink)'
                              : 'border-transparent text-(--color-ink-muted) hover:text-(--color-ink)' }}">
                    {{ __('accounts::control.dup_kind.'.$key) }}
                    <span @class([
                        'rounded-full px-2 text-2xs',
                        'bg-(--color-badge-danger-bg) text-(--color-badge-danger-ink)' => $count > 0,
                        'bg-(--color-surface-sunken) text-(--color-ink-muted)' => $count === 0,
                    ])>{{ $count }}</span>
                </a>
            @endforeach
        </nav>

        @if ($groups === [])
            <x-ui.empty-state :message="__('accounts::control.no_duplicates')" />
        @else
            <div class="space-y-3 p-3">
                @foreach ($groups as $group)
                    <section class="overflow-hidden rounded-(--radius-field) border border-(--color-border)" data-duplicate-group="{{ $group['by'] }}">
                        <h2 class="border-b border-(--color-border) bg-(--color-section-head) px-3 py-2 text-sm">
                            <span class="text-(--color-ink-muted)">{{ __('accounts::control.same_'.$group['by']) }}:</span>
                            <span class="font-semibold">{{ $group['key'] }}</span>
                        </h2>
                        <ul class="divide-y divide-(--color-border)">
                            @foreach ($group['rows'] as $row)
                                <li class="flex flex-wrap items-baseline gap-3 px-3 py-2 text-sm">
                                    <span class="num w-24 shrink-0 text-(--color-ink-muted)">{{ $row->code }}</span>
                                    <a href="{{ $open($tab, $row) }}" class="min-w-0 flex-1 text-(--color-link) hover:underline">
                                        {{ $row->name_en ?: $row->name_bn }}
                                    </a>
                                    <span class="num shrink-0">{{ $row->phone ?: '—' }}</span>
                                    <span class="shrink-0 text-2xs text-(--color-ink-muted)">
                                        {{ $row->is_active ? __('core.state.active') : __('core.state.inactive') }}
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endforeach
            </div>
        @endif
    </div>
</x-layouts.app>
