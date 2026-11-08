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
                              'tone' => null|'danger'|'success',
                              'icon' => x-ui.icon-এর নাম, 'hint' => ডানে ছোট লেখা,
                              'off' => কেন এখন করা যায় না (থাকলে ধূসর, চাপা যায় না),
                              'blank' => true (নতুন ট্যাবে — ছাপা, PDF),
                              'confirm' => "নিশ্চিত?" প্রশ্ন (মোছার আগে),
                              'key' => চাবির ঘর (F10), 'peek' => false (পুরো পাতায় খোলে),
                              'fields' => [নাম => মান] (POST-এর সাথে লুকানো ঘর)]
                   অথবা ['head' => কাগজের নম্বর, 'state' => অবস্থা] — মেনুর মাথা,
                   ['group' => 'শিরোনাম'] কিংবা ['line' => true] — ভাগ।
    @param $label  বোতামের লেখা; না দিলে কেবল তিনটা বিন্দু

    ⭐ মালিক, ৮ নভেম্বর ২০২৬: "প্রতিটি লিস্টে শেষের দিকে একটা অ্যাকশন বাটন
    থাকবে … ড্রপ ডাউন … প্রিন্ট, এডিট, ডিলিট, প্রিভিউ" — নমুনায় "ok"।
    কাগজের তালিকা `:label` দেয় ("কাজ ▾"), মেনু কাগজের অবস্থা দেখে বদলায়:
    যা এখন করা যায় না তা ধূসর, পাশে কারণ — লুকানো নয়, যাতে মানুষ জানে
    কাজটা আছে, কেবল এখন নয়।
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

    /* ⓘ শুরুতে, শেষে বা পরপর দুইটা ভাগ-রেখা আঁকা হয় না */
    $items = array_values(array_filter($items, function ($item, $i) use ($items) {
        if (! ($item['line'] ?? false)) {
            return true;
        }
        $next = $items[$i + 1] ?? null;

        return $i > 0 && $next !== null && ! ($next['line'] ?? false) && ! ($items[$i - 1]['line'] ?? false);
    }, ARRAY_FILTER_USE_BOTH));

    /* ⓘ শুধু মাথা, শিরোনাম আর রেখা থাকলে বোতামই নয় */
    $acts = array_filter($items, fn ($item) => isset($item['url']) || isset($item['off']));
@endphp

@if ($acts !== [])
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
                @class([
                    'flex min-h-(--spacing-touch) items-center gap-1 rounded-(--radius-field) text-sm transition-colors',
                    'px-2 text-(--color-ink-muted) hover:bg-(--color-surface-hover) hover:text-(--color-ink)' => ! $label,
                    /* ⭐ লেখাসহ বোতাম — নমুনার "কাজ ▾": ফ্রেমে বাঁধা, খুললে নীল */
                    'border border-(--color-border) bg-(--color-surface-card) px-3 font-semibold text-(--color-ink) hover:border-(--color-brand-500) hover:text-(--color-brand-600) aria-expanded:border-(--color-brand-500) aria-expanded:text-(--color-brand-600)' => (bool) $label,
                ])
                @if ($label) data-row-actions-button @endif>
            @if ($label)
                <span>{{ $label }}</span>
                <svg viewBox="0 0 24 24" aria-hidden="true" class="size-4 shrink-0 fill-current"><path d="M7 10l5 5 5-5z"/></svg>
            @else
                {{-- তিনটা বিন্দু — যেখানেই দেখা যাক, "এখানে আরও কিছু আছে" --}}
                <svg viewBox="0 0 24 24" aria-hidden="true" class="size-4 shrink-0 fill-current">
                    <path d="M12 8a2 2 0 1 1 0-4 2 2 0 0 1 0 4Zm0 6a2 2 0 1 1 0-4 2 2 0 0 1 0 4Zm0 6a2 2 0 1 1 0-4 2 2 0 0 1 0 4Z"/>
                </svg>
            @endif
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
             @class([
                 'fixed z-50 min-w-44 max-w-[calc(100vw-2rem)] overflow-y-auto max-h-[75vh]
                  rounded-(--radius-card) border border-(--color-border)
                  bg-(--color-surface-card) py-1 text-start shadow-lg',
                 'w-72 px-1.5' => (bool) $label,
             ])>

            @foreach ($items as $item)
                @if ($item['line'] ?? false)
                    <div class="mx-1 my-1 h-px bg-(--color-border)" role="separator"></div>
                    @continue
                @endif
                @if (isset($item['head']))
                    {{-- ⓘ মেনুর মাথা: কোন কাগজ, কোন অবস্থায় — নমুনার মতো --}}
                    <div class="mb-1 flex items-center justify-between gap-2 border-b border-(--color-border) px-2.5 pt-1.5 pb-2 text-[11px] text-(--color-ink-muted)" data-row-actions-head>
                        <b class="text-xs text-(--color-ink)">{{ $item['head'] }}</b>
                        @isset($item['state'])<span>{{ $item['state'] }}</span>@endisset
                    </div>
                    @continue
                @endif
                @if (isset($item['group']))
                    <div class="px-2.5 pt-2 pb-0.5 text-[11px] font-semibold text-(--color-ink-muted)" data-row-actions-group>{{ $item['group'] }}</div>
                    @continue
                @endif
                @php
                    $tone = match ($item['tone'] ?? null) {
                        'danger' => 'text-(--color-badge-danger-ink)',
                        'success' => 'text-(--color-badge-success-ink)',
                        default => 'text-(--color-ink)',
                    };

                    $row = 'flex w-full items-center gap-2 rounded-(--radius-field) px-2.5 py-1.5 text-start text-xs transition-colors '
                        . 'hover:bg-(--color-surface-hover) ' . $tone;
                    $off = $item['off'] ?? null;
                @endphp

                @if ($off !== null)
                    {{-- ⓘ ধূসর: কাজটা আছে, এখন নয় — কারণটা নামের নিচে (নমুনা: "লুকানো থাকে না — ধূসর থাকে, নিচে কারণ লেখা") --}}
                    <div role="menuitem" aria-disabled="true" data-row-action-off
                         class="flex w-full cursor-not-allowed items-center gap-2 px-2.5 py-1.5 text-xs text-(--color-ink-muted)">
                        @isset($item['icon'])<x-ui.icon :name="$item['icon']" :size="15" />@endisset
                        <span class="flex min-w-0 flex-col">
                            <span>{{ $item['label'] }}</span>
                            <span class="text-[11px] leading-snug" data-row-action-why>{{ $off }}</span>
                        </span>
                    </div>
                @elseif (($item['method'] ?? null) === null)
                    <a href="{{ $item['url'] }}" role="menuitem" class="{{ $row }}"
                       @if ($item['blank'] ?? false) target="_blank" rel="noopener" @endif
                       @if (($item['peek'] ?? true) === false) data-no-peek @endif
                       @if ($item['opens'] ?? false) data-row-view @endif>
                        @isset($item['icon'])<x-ui.icon :name="$item['icon']" :size="15" />@endisset
                        <span class="min-w-0">{{ $item['label'] }}</span>
                        @isset($item['hint'])<span class="ms-auto ps-3 text-[11px] text-(--color-ink-muted)">{{ $item['hint'] }}</span>@endisset
                        @isset($item['key'])<kbd class="ms-auto rounded border border-(--color-border) px-1 font-sans text-[10px] text-(--color-ink-muted)">{{ $item['key'] }}</kbd>@endisset
                    </a>
                @else
                    <form method="POST" action="{{ $item['url'] }}"
                          @isset($item['confirm']) data-confirm="{{ $item['confirm'] }}" @endisset>
                        @csrf
                        @if ($item['method'] !== 'post')
                            @method($item['method'])
                        @endif
                        @foreach ($item['fields'] ?? [] as $field => $value)
                            <input type="hidden" name="{{ $field }}" value="{{ $value }}">
                        @endforeach
                        <button type="submit" role="menuitem" class="{{ $row }}">
                            @isset($item['icon'])<x-ui.icon :name="$item['icon']" :size="15" />@endisset
                            <span class="min-w-0">{{ $item['label'] }}</span>
                            @isset($item['hint'])<span class="ms-auto ps-3 text-[11px] text-(--color-ink-muted)">{{ $item['hint'] }}</span>@endisset
                            @isset($item['key'])<kbd class="ms-auto rounded border border-(--color-border) px-1 font-sans text-[10px] text-(--color-ink-muted)">{{ $item['key'] }}</kbd>@endisset
                        </button>
                    </form>
                @endif
            @endforeach
        </div>
    </div>
@endif
