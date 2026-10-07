@props([
    'items' => [],
    'label' => null,
])

{{--
    সারির কাজগুলো — একটা বোতাম, ভেতরে তালিকা।

    ── কেন ড্রপডাউন, পাশাপাশি লিংক নয় ─────────────────────────────────
    প্রথমে তিনটা লিংক পাশাপাশি বসানো হয়েছিল — সম্পাদনা · ডিফল্ট করুন ·
    নিষ্ক্রিয় করুন। সারিটা ভিড় হয়ে যায়, কলামটা চওড়া হয়ে বাকি তথ্য
    চেপে দেয়, আর ছোট পর্দায় লেখাগুলো একটার ঘাড়ে আরেকটা পড়ে। মালিকের
    কথা: "action button/dropdown"।

    একটা বোতাম, আর যা যা করা যায় তা ভেতরে — সারির প্রস্থ স্থির থাকে,
    আর নতুন একটা কাজ যোগ করলে তালিকাটা লম্বা হয়, টেবিলটা নয়।

    ── আলাদা আলাদা ফর্ম, একটা নয় ──────────────────────────────────────
    প্রতিটা কাজের নিজের method আর নিজের ঠিকানা (DELETE, POST), তাই
    একটা ফর্মে সব বোতাম রাখা যেত না। ফর্মগুলো তালিকার ভেতরে, প্রতিটা
    নিজের সারিতে।

    @param $items  প্রতিটা: ['label' =>, 'url' =>, 'method' => null|'post'|'delete',
                              'tone' => null|'danger'|'success']
    @param $label  বোতামের লেখা; না দিলে কেবল তিনটা বিন্দু
--}}
@php
    /*
     * ⚠️ স্লট দিয়ে ডাকা — এই কম্পোনেন্টের একমাত্র নীরব ফাঁদ।
     *
     * সে কেবল `items` আঁকে, `$slot` কখনো নয় — আর `@if ($items !== [])`
     * থাকায় স্লট দিয়ে ডাকলে বোতামটা **কোনো ত্রুটি ছাড়াই অদৃশ্য থাকত**।
     * রেসিপির তালিকায় ঠিক তাই ঘটেছিল: মালিক পণ্যের তালিকায় যে
     * অভিযোগটা করেছেন, সেখানে সেটা আজও ছিল, আর কেউ টের পায়নি।
     *
     * লাল করা হলো, স্লটটা আঁকা হলো না — দুই রকমে ডাকার পথ থাকলে
     * `tone`/`method`-এর আচরণ কেবল এক পথে খাটত, আর একদিন দুইটা আলাদা
     * দিকে সরত। নীরবে কিছু না আঁকার চেয়ে থেমে যাওয়া ভালো।
     */
    if (trim($slot->toHtml()) !== '') {
        throw new \InvalidArgumentException(
            'x-ui.row-actions renders its :items array, never slot content. '
            .'Slot children are silently dropped, so the button vanishes with no error. '
            .'Pass :items="[[label => …, url => …, method => …, tone => …], …]" instead.'
        );
    }

    $items = array_values(array_filter($items));
@endphp

@if ($items !== [])
    <div x-data="rowActions"
         class="flex justify-end print-hide">
        <button type="button"
                x-ref="button"
                @click="toggle()"
                @click.outside="open = false"
                @keydown.escape.window="open = false"
                {{--
                    স্ক্রল হলেই বন্ধ। fixed মেনু নিজে সরে না, তাই স্ক্রল করলে
                    সে শূন্যে দাঁড়িয়ে থাকত, তার সারি থেকে আলগা হয়ে।

                    `.capture` জরুরি: scroll ইভেন্ট bubble করে না, তাই টেবিলের
                    নিজস্ব স্ক্রলার (`.table-responsive`) সরালে window টেরই পেত না।
                --}}
                @scroll.window.capture="open = false"
                @resize.window="open = false"
                :aria-expanded="open ? 'true' : 'false'"
                aria-haspopup="true"
                title="{{ $label ?? __('core.table.actions') }}"
                aria-label="{{ $label ?? __('core.table.actions') }}"
                class="flex min-h-(--spacing-touch) items-center gap-1 rounded-(--radius-field)
                       px-2 text-sm text-(--color-ink-muted) transition-colors
                       hover:bg-(--color-surface-hover) hover:text-(--color-ink)">
            @if ($label)
                <span>{{ $label }}</span>
            @endif

            {{-- তিনটা বিন্দু — যেখানেই দেখা যাক, "এখানে আরও কিছু আছে" --}}
            <svg viewBox="0 0 24 24" aria-hidden="true" class="size-4 shrink-0 fill-current">
                <path d="M12 8a2 2 0 1 1 0-4 2 2 0 0 1 0 4Zm0 6a2 2 0 1 1 0-4 2 2 0 0 1 0 4Zm0 6a2 2 0 1 1 0-4 2 2 0 0 1 0 4Z"/>
            </svg>
        </button>

        <div x-show="open"
             x-cloak
             role="menu"
             x-ref="menu"
             {{--
                 `fixed` ক্লাসেই, JS-এ নয় — আর এই ক্রমটা ভুল হলে মেনুটা
                 জায়গামতো বসে না।

                 ⚠️ মেনু যদি দেখা যাওয়ার মুহূর্তে `static` থাকে, সে ঘরের
                 ভেতরে জায়গা নেয় আর **বোতামটাকেই বাঁ দিকে ঠেলে দেয়**
                 (`flex justify-end`)। তখন বোতামের মাপ নিলে সেটা সরানো
                 অবস্থার মাপ — মেপে দেখা গেছে ২০৪px ভুল, যা প্রায় মেনুরই
                 প্রস্থ (২২০px)। মেনু তারপর `fixed` হয়ে ফ্লো ছেড়ে দিত,
                 বোতাম ডানে ফিরত, আর মেনুটা ঝুলে থাকত ভুল জায়গায়।

                 ক্লাসে দিলে সে কখনো ফ্লোতে ঢোকেই না, তাই মাপটাও সঠিক —
                 আর মাঝের এক ফ্রেমের লাফটাও থাকে না।
             --}}
             class="fixed z-50 min-w-44 overflow-hidden
                    rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card) py-1 text-start shadow-lg">

            @foreach ($items as $item)
                @php
                    $tone = match ($item['tone'] ?? null) {
                        'danger' => 'text-(--color-badge-danger-ink)',
                        'success' => 'text-(--color-badge-success-ink)',
                        default => 'text-(--color-ink)',
                    };

                    $row = 'block w-full px-3 py-2 text-start text-xs transition-colors '
                        . 'hover:bg-(--color-surface-hover) ' . $tone;
                @endphp

                @if (($item['method'] ?? null) === null)
                    <a href="{{ $item['url'] }}" role="menuitem" class="{{ $row }}">
                        {{ $item['label'] }}
                    </a>
                @else
                    <form method="POST" action="{{ $item['url'] }}">
                        @csrf
                        @if ($item['method'] !== 'post')
                            @method($item['method'])
                        @endif
                        <button type="submit" role="menuitem" class="{{ $row }}">
                            {{ $item['label'] }}
                        </button>
                    </form>
                @endif
            @endforeach
        </div>
    </div>
@endif
