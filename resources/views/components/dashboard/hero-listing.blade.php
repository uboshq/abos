{{--
    ⭐ গাঢ় বাক্সের তালিকা — হোমের "হাতে ও ব্যাংকে মোট" ঘরের হুবহু রূপ (workspace/home.blade.php, `data-money-position`):
    একই ঢাল (brand-700 → brand-900), একই লেখার রং (ink-inverse, white/70 শিরোনাম, white/60 ছোট লেখা, white/15 রেখা)।
    মালিক, ৬ অক্টোবর ২০২৬: প্রিন্সিপালের কমিশনের জন্য *"main dashboard-এর মতো একটা same box"*।

    ⓘ নতুন কোনো রং নয় — সব টোকেন। অঙ্কের ঘর ডানে, `tabular`; সরু পর্দায় টেবিলটা নিজের ভেতরে সরে, পাতা নয়।
--}}
@props(['listing'])

<section data-boxed data-hero-listing
         class="rounded-(--radius-card) px-5 py-3 text-(--color-ink-inverse) shadow-lg"
         style="background: linear-gradient(135deg, var(--color-brand-700), var(--color-brand-900))">
    <div class="flex flex-wrap items-baseline gap-x-3 gap-y-0.5">
        <h2 class="flex items-center gap-1.5 text-sm text-white/70">
            <x-ui.icon name="wallet" :size="15" />
            {{ $listing->label }}
        </h2>
        @if ($listing->note)
            <p data-hero-note class="text-2xs text-white/60">{{ $listing->note }}</p>
        @endif
        @if ($listing->href)
            <a href="{{ $listing->href }}" class="ms-auto text-xs text-white/70 underline hover:text-white">{{ __('core.action.see_all') }} →</a>
        @endif
    </div>

    @if ($listing->rows->isEmpty())
        <p class="mt-1.5 border-t border-white/15 pt-2 text-sm text-white/70">{{ $listing->empty }}</p>
    @else
        <div class="mt-1.5 overflow-x-auto border-t border-white/15 pt-2">
            <table class="w-full text-sm">
                <thead>
                    <tr>
                        @foreach ($listing->columns as $i => $column)
                            <th scope="col" @class([
                                'whitespace-nowrap pb-1 text-2xs font-normal text-white/60',
                                'pe-4 text-start' => $i === 0,
                                'px-3 text-end' => $i > 0,
                            ])>{{ $column['label'] }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($listing->rows as $row)
                        <tr class="border-t border-white/15">
                            @foreach ($listing->columns as $i => $column)
                                <td @class([
                                    'py-1.5 font-semibold',
                                    'pe-4 text-start' => $i === 0,
                                    'tabular whitespace-nowrap px-3 text-end' => $i > 0,
                                ])>{{ ($column['render'])($row) }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>
