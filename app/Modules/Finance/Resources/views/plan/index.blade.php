{{--
    ফিন্যান্স মানচিত্র — তেত্রিশ বিভাগ, প্রতিটা লাইন।

    ── কেন মেনুতে দুইশো মৃত সারি নয় ───────────────────────────────────
    প্রতিটা লাইনের জন্য একটা করে মেনু সারি বসালে দুইশোটা বোতাম হত যার
    একটাও কিছু করে না — আর ঠিক ওই জিনিসটাই আজ সকালে সরাসরি বিক্রয়ের
    পর্দায় সারানো হয়েছে (চারটা বোতাম কেবল "আসছে" বলত)।

    একটা মানচিত্র ওই দুইটার কোনোটাই নয়: এটা একটা সৎ তালিকা। যেটা হয়েছে
    তার লিংক আসল পর্দা খোলে; যেটা হয়নি তার পাশে লেখা "বাকি"। কোনো
    বোতাম মিথ্যা বলে না।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('finance::menu.plan') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('finance::menu.plan')"
                          :subtitle="__('finance::message.plan_note')" />
    </x-slot:header>

    {{-- কত দূর এল — লাইন গুনে, বিভাগ গুনে নয়।

         বিভাগ গুনলে "৩৩-এর ১৬" শোনায় ভালো, কিন্তু একটা বিভাগে দশটা
         লাইন আর অন্যটায় একটা। লাইন গুনলে সংখ্যাটা সত্যিকারের কাজের
         অনুপাত বলে। --}}
    @php
        $pct = $tally['total'] > 0 ? (int) round($tally['done'] / $tally['total'] * 100) : 0;
    @endphp

    <section data-boxed class="mb-4 rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card) p-4">
        <div class="flex flex-wrap items-baseline gap-3">
            <span class="num text-2xl font-bold">{{ $tally['done'] }}</span>
            <span class="text-(--color-ink-muted)">/</span>
            <span class="num text-lg">{{ $tally['total'] }}</span>
            <span class="text-sm text-(--color-ink-muted)">{{ __('finance::message.lines_done') }}</span>

            <span class="flex-1"></span>

            <span class="num text-lg font-semibold">{{ $pct }}%</span>
        </div>

        <div class="mt-2 h-2 overflow-hidden rounded-full bg-(--color-surface-sunken)">
            <div class="h-full bg-(--color-brand-600)" style="width: {{ $pct }}%"></div>
        </div>
    </section>

    <div class="grid gap-3 lg:grid-cols-2 2xl:grid-cols-3">
        @foreach ($sections as $section)
            @php
                $done = collect($section['items'])
                    ->filter(fn ($i) => $i[1] !== null && \App\Modules\Finance\Support\FinancePlan::urlFor($i[1]))
                    ->count();
            @endphp

            <section data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                            bg-(--color-surface-card)">
                <h2 class="flex items-baseline gap-2 border-b border-(--color-border)
                           bg-(--color-section-head) px-3 py-2">
                    <span class="num text-2xs text-(--color-ink-muted)">§{{ $section['no'] }}</span>
                    <span class="font-semibold">{{ $section['title'] }}</span>

                    <span class="flex-1"></span>

                    <span @class([
                        'num rounded-(--radius-field) px-2 py-0.5 text-2xs',
                        'bg-(--color-badge-success-bg) text-(--color-badge-success-ink)'
                            => $done === count($section['items']),
                        'bg-(--color-surface-sunken) text-(--color-ink-muted)'
                            => $done !== count($section['items']),
                    ])>{{ $done }}/{{ count($section['items']) }}</span>
                </h2>

                <ul class="divide-y divide-(--color-border)">
                    @foreach ($section['items'] as [$label, $route, $note])
                        @php
                            $url = \App\Modules\Finance\Support\FinancePlan::urlFor($route);
                        @endphp

                        <li class="flex items-baseline gap-2 px-3 py-1.5 text-sm">
                            {{-- হয়েছে হলে লিংক, বাকি হলে সাদামাটা লেখা।

                                 নিয়ম ১: যা "হয়েছে" বলা হচ্ছে সেটা ক্লিক
                                 করলে সত্যিই খুলতে হবে — নাহলে মানচিত্রটাই
                                 মিথ্যা। --}}
                            @if ($url)
                                <span class="text-(--color-success)" aria-hidden="true">✓</span>
                                <a href="{{ $url }}" class="min-w-0 flex-1 truncate text-(--color-link) hover:underline">
                                    {{ $label }}
                                </a>
                            @else
                                <span class="text-(--color-ink-disabled)" aria-hidden="true">○</span>
                                <span class="min-w-0 flex-1 truncate text-(--color-ink-muted)">{{ $label }}</span>
                            @endif

                            @if ($note)
                                <span class="shrink-0 text-2xs text-(--color-ink-muted)">{{ $note }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </section>
        @endforeach
    </div>
</x-layouts.app>
