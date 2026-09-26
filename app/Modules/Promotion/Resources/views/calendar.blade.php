{{--
    অফারের ক্যালেন্ডার — স্পেক §১৬।

    ⭐ এই পাতায় কোনো হিসাব নেই। ⓘ কোন অফার কোন রঙে, কোন ঘরে, কোন সারিতে
    — সবটা [[PromotionCalendar]] ঠিক করে দেয়; পাতা কেবল আঁকে। ⛔ রঙের
    নিয়ম এখানে আবার লিখলে পরীক্ষা এক নিয়ম মাপত, পাতা আরেকটা দেখাত।

    ⚠️ কোনো `@php` নেই, কোনো Alpine নেই — সাধারণ সার্ভার-আঁকা HTML।
    ⓘ গ্রিডের কাঠামোটা `style`-এ (কলাম আর সারির নম্বর প্রতিটা দাগে আলাদা,
    Tailwind-এর তৈরি-করা শ্রেণিতে ধরা যায় না); রং ব্যাজের টোকেন থেকে।

    ⓘ রং কখনো একা অর্থ বহন করে না — প্রতিটা দাগে কোড লেখা, আর নিচের
    তালিকায় অবস্থার নাম লেখায়।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('promotion::calendar.title') }} · {{ $title }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('promotion::calendar.title')" :subtitle="$title">
            <x-slot:actions>
                <x-ui.button :href="route('promotion.calendar', ['month' => $sheet['prev']])">
                    {{ __('promotion::calendar.prev') }}
                </x-ui.button>
                <x-ui.button :href="route('promotion.calendar')">
                    {{ __('promotion::calendar.this_month') }}
                </x-ui.button>
                <x-ui.button :href="route('promotion.calendar', ['month' => $sheet['next']])">
                    {{ __('promotion::calendar.next') }}
                </x-ui.button>
            </x-slot:actions>
        </x-ui.page-header>
    </x-slot:header>

    <div class="space-y-4">
        <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="text-sm font-semibold">{{ __('promotion::calendar.legend') }}</h2>
            <div class="mt-2 flex flex-wrap gap-2">
                @foreach ($legend as $key)
                    <x-ui.badge :tone="$key['tone']">{{ $key['label'] }}</x-ui.badge>
                @endforeach
            </div>
            <p class="mt-2 text-xs text-(--color-ink-muted)">{{ __('promotion::calendar.soon_note', ['days' => $soonDays]) }}</p>
            <p class="text-xs text-(--color-ink-muted)">{{ __('promotion::calendar.hidden_note') }}</p>
        </section>

        @if ($sheet['truncated'])
            <p class="text-sm text-(--color-ink-muted)">{{ __('promotion::calendar.truncated', ['shown' => count($sheet['offers'])]) }}</p>
        @endif

        <section data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card)">
            <div class="border-b border-(--color-border)" style="display: grid; grid-template-columns: repeat(7, minmax(0, 1fr))">
                @foreach ($weekdays as $weekday)
                    <div class="px-2 py-1 text-xs font-medium text-(--color-ink-muted)">{{ $weekday }}</div>
                @endforeach
            </div>

            @foreach ($sheet['weeks'] as $week)
                <div class="border-b border-(--color-border)"
                     style="display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); grid-auto-rows: minmax(1.5rem, auto); row-gap: 2px">
                    @foreach ($week['days'] as $day)
                        <div class="px-2 py-1 text-xs {{ $day['in_month'] ? '' : 'bg-(--color-surface-app) text-(--color-ink-muted)' }}"
                             style="grid-column: {{ $day['col'] }}; grid-row: 1 / span {{ $week['rows'] }}; border-right: 1px solid var(--color-border)"
                             data-date="{{ $day['date'] }}">
                            @if ($day['in_month'])
                                <span class="{{ $day['is_today'] ? 'font-semibold underline' : '' }}">{{ $day['day'] }}</span>
                            @endif
                        </div>
                    @endforeach

                    @foreach ($week['bars'] as $bar)
                        <a href="{{ $bar['url'] }}"
                           class="mx-1 truncate rounded-(--radius-badge) px-2 text-xs font-medium"
                           style="grid-column: {{ $bar['col'] }} / span {{ $bar['span'] }}; grid-row: {{ $bar['row'] }}; background: var(--color-badge-{{ $bar['tone'] }}-bg); color: var(--color-badge-{{ $bar['tone'] }}-ink)"
                           title="{{ $bar['code'] }} · {{ $bar['name'] }} · {{ $bar['label'] }} · {{ $bar['period'] }}"
                           data-state="{{ $bar['state'] }}">{{ $bar['cut_before'] ? '‹ ' : '' }}{{ $bar['code'] }} · {{ $bar['name'] }}{{ $bar['cut_after'] ? ' ›' : '' }}</a>
                    @endforeach
                </div>
            @endforeach
        </section>

        <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="text-sm font-semibold">{{ __('promotion::calendar.list') }}</h2>

            @if ($sheet['offers'] === [])
                <p class="mt-2 text-sm text-(--color-ink-muted)">{{ __('promotion::calendar.empty') }}</p>
            @else
                <ul class="mt-2 space-y-2 text-sm">
                    @foreach ($sheet['offers'] as $row)
                        <li class="flex flex-wrap items-center gap-2">
                            <x-ui.badge :tone="$row['tone']">{{ $row['label'] }}</x-ui.badge>
                            <a class="underline" href="{{ $row['url'] }}">{{ $row['code'] }}</a>
                            <span>{{ $row['name'] }}</span>
                            <span class="text-(--color-ink-muted)">{{ $row['period'] }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>
</x-layouts.app>
