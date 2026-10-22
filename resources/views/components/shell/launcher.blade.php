@props(['menu' => []])

{{--
    ⊞ সব মডিউল — বারের বোতাম আর পুরো পর্দার মডিউল-পাতা (Odoo-র হোমের মতো)।

    ── ⭐ কেন ফিরল, ২১ অক্টোবর ২০২৬ ─────────────────────────────────────
    মালিক: ⊞ লঞ্চার ফেরাও — Odoo, Google, M365, Fiori সবারই আছে। ওয়েবের মান ধাপ ২-এ (২০ অক্টোবর) বারটা Fiori-র মাপে নামানোর
    সময় এটা উঠে গিয়েছিল ("রেলেই সব মডিউল আছে")। ⓘ রেল আছে, কিন্তু রেল কেবল আইকন — নাম পড়তে হলে প্রতিটার উপর ইঁদুর রাখতে হয়,
    আর ফোনে রেলই নেই। এক নজরে সব মডিউল, নামসহ, খোঁজা দিয়ে — সেটাই এই পাতা।

    ── ⭐ পুরনোটাই, নতুন করে লেখা নয় ───────────────────────────────────
    বোতামটা এই ফাইলের আগের চার-বর্গের ⊞; পাতাটা Odoo রূপের শিটের ছাঁচে ([[shell.chrome.apps]]-এর `overlay`), একই
    `launcher` Alpine-উপাদান (shell.js) — কেবল খোঁজা এখন বাংলা আর ইংরেজি দুই নামেই, আর তীর-চাবি, Alt+M, সইয়ের সংখ্যা যোগ হলো।
    ⛔ আগের খসড়ার ঝুলন্ত প্যানেলটা (তিন কলাম, বোতামের নিচে) নয় — মালিক এবার পুরো পর্দা চেয়েছেন, পেছনটা হালকা ঝাপসা।

    ── ⭐ টাইল কোথা থেকে ─────────────────────────────────────────────────
    `$menu` — রেল যেটা আঁকে, ঠিক সেটাই ([[MenuBuilder::forUser()]]): অনুমতি নেই এমন সারি আর বন্ধ মডিউল ([[MenuSwitches]])
    সেখানেই বাদ। তাই রেলে যা আছে লঞ্চারেও তাই, একই ক্রমে। ⓘ যে মডিউলের একটাও খোলা পাতা নেই (সব সারি "শীঘ্রই") তার টাইল নেই —
    মরা টাইল রাখা হয় না; রেলে সে থাকে কেবল ঝুলন্ত তালিকা দেখাতে। টাইল চাপলে রেলের একই ঠিকানা: মডিউলের প্রথম পাতা, অর্থাৎ
    তার ড্যাশবোর্ড।

    ── ⭐ সইয়ের অপেক্ষা ──────────────────────────────────────────────────
    টাইলের কোণে ঐ মডিউলের কতগুলো কাগজ এই মানুষের সইয়ের অপেক্ষায় — অনুমোদনের ইনবক্সের হুবহু হিসাব
    ([[ApprovalEngine::pendingQueryFor()]]), মডিউল ধরে ভাগ করা। শূন্যে কিছুই নেই।

    ── ⓘ প্রিয় মডিউল টেনে সাজানো — নেই, ইচ্ছাকৃত ──────────────────────────
    মালিক চেয়েছিলেন "কেবল যদি ব্যবহারকারীর পছন্দের টেবিল আগে থেকেই থাকে"। নেই (users-এর `ui`/`theme`/`accent` ঘর আছে, ক্রমের
    কোনো ঘর নেই), আর নতুন মাইগ্রেশন নিষেধ — তাই এই ধাপে নয়।
--}}
@php
    $user = auth()->user();
    $registry = app(\App\Core\Module\ModuleRegistry::class);

    /* ⓘ সইয়ের অপেক্ষা, মডিউল ধরে — এক কোয়েরি (ইনবক্সের শর্তই, কেবল গোনা) */
    $waiting = [];
    if ($user !== null) {
        $waiting = app(\App\Core\Engines\Approval\ApprovalEngine::class)->pendingQueryFor($user)
            ->setEagerLoads([])
            ->reorder()
            ->toBase()
            ->select('approvals.module')
            ->selectRaw('count(*) as waiting')
            ->groupBy('approvals.module')
            ->pluck('waiting', 'module')
            ->map(fn ($n): int => (int) $n)
            ->all();
    }

    $tiles = [];
    foreach ($menu as $module) {
        $first = collect($module['groups'])->flatten(1)->firstWhere('url', '!==', null);

        if ($first === null) {
            continue;
        }

        /* ⓘ খোঁজার চাবি — বাংলা আর ইংরেজি দুই নামই, কোডসহ; ছোট হাতে (JS-এ একই রূপে মেলানো হয়) */
        $keys = [$module['code'], $module['label']];
        foreach ($module['codes'] as $code) {
            $definition = $registry->get($code);
            $keys[] = $code;
            $keys[] = $definition?->label('bn');
            $keys[] = $definition?->label('en');
        }

        $tiles[] = [
            'code' => $module['code'],
            'label' => $module['label'],
            'icon' => $module['icon'],
            'url' => $first['url'],
            'keys' => mb_strtolower(implode(' ', array_unique(array_filter($keys)))),
            'waiting' => array_sum(array_map(fn (string $code): int => $waiting[$code] ?? 0, $module['codes'])),
        ];
    }
@endphp

{{-- ⓘ `max-sm:contents` — ফোনে বোতাম লুকানো, আর বাক্সটাও কোনো ঘর নেয় না (নইলে বারে ১২px ফাঁকা থেকে যেত); পাতাটা `fixed`, তাই দেখায় --}}
<div x-data="launcher" class="relative shrink-0 max-sm:contents" data-module-launcher-box
     @open-module-launcher.window="show()"
     @keydown.window="hotkey($event)">
    {{-- ⓘ ফোনে (sm-এর নিচে) বোতামটা বারে নয়, নিচের নেভিগেশনের "আরও"-তে ([[shell.bottom-nav]]) — পাতাটা একই, এখান থেকেই খোলে।
         ⛔ ৩৭৫px-এ বারে জায়গা ~১০৭px: ⊞ ৪০ + লোগো ৪১ + খোঁজার আইকন ৪৪ ধরে না, আর খোঁজার আইকনটা লোগোর উপর চেপে বসত
         (ছবিতে মাপা, ২১ অক্টোবর ২০২৬)। ফোনের "রেল" নিচের বারটাই, তাই ⊞-ও সেখানে। --}}
    <button type="button" x-ref="button"
            data-module-launcher
            @click="show()"
            :aria-expanded="openFlag"
            aria-haspopup="dialog"
            aria-controls="module-launcher"
            aria-keyshortcuts="Alt+M"
            class="grid size-[40px] shrink-0 place-items-center rounded-full text-(--color-topbar-ink-muted) max-sm:hidden
                   transition-colors hover:bg-(--color-topbar-hover) hover:text-(--color-topbar-ink)
                   focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--color-brand-500)"
            style="--topbar-control-h: 40px"
            aria-label="{{ __('core.ui.all_modules') }}"
            title="{{ __('core.ui.all_modules') }} (Alt+M)">
        {{-- চারটা বর্গ — ⊞। ⓘ ৯ ফোঁটা Odoo-র চিহ্ন; এটা ABOS-এর নিজের, তাই আলাদা দেখতে হওয়াই ঠিক। --}}
        <span class="grid grid-cols-2 gap-[3px]" aria-hidden="true">
            @for ($i = 0; $i < 4; $i++)
                <span class="block size-[7px] rounded-[2px] border-2 border-current"></span>
            @endfor
        </span>
    </button>

    {{--
        পুরো পর্দার পাতা — বারের উপরেও (Odoo-র হোম), পেছনে পাতাটা হালকা ঝাপসা।

        ⓘ `pops-onto-page` — বারের ৩৬px বোতাম-উচ্চতা আর বারের কালি এখানে নয় (app.css-এর `.topbar` নিয়ম)।
        ⓘ কলাম: ৩৭৫px-এ ৩, ট্যাবলেটে ৪, ১৩৬৬-এ ৫, ১৯২০-এ ৬ (মালিকের মাপ) — এই রিপোর ধাপে: md ৭৬৮ · lg ১০২৪ · xl ১৪৪০
        (tokens.css-এর `--breakpoint-*`; ⛔ প্রথম খসড়ায় Tailwind-এর ডিফল্ট ধরে xl লিখেছিলাম, আর ১৩৬৬-এ ৪ কলাম এল — ছবিতে মাপা)।
        ⓘ ARIA: dialog, ভেতরে grid — এক সারির গ্রিড, ঘরগুলো চোখে কয়েক সারিতে ভাঙে; তীর-চাবি চোখের সারি ধরেই চলে (`move()`)।
    --}}
    <div id="module-launcher" x-show="open" x-cloak
         data-module-launcher-sheet
         role="dialog" aria-modal="true" aria-label="{{ __('core.ui.all_modules') }}"
         @click.self="close()"
         class="pops-onto-page fixed inset-0 z-50 overflow-y-auto bg-(--color-launcher-scrim) backdrop-blur-sm">
        <div class="mx-auto w-full max-w-6xl px-4 pt-6 pb-[calc(var(--spacing-bottom-nav)+2rem)] md:px-8 md:pt-10 md:pb-10"
             @click.self="close()">
            <div class="mx-auto flex max-w-xl items-center gap-2">
                <label class="flex min-h-[44px] min-w-0 flex-1 items-center gap-2 rounded-full border border-(--color-border)
                              bg-(--color-surface-card) px-4 text-(--color-ink-muted) shadow-sm
                              focus-within:border-(--color-brand-500)">
                    <svg viewBox="0 0 24 24" class="size-[20px] shrink-0 fill-current" aria-hidden="true">
                        <path d="M10 2a8 8 0 1 0 4.9 14.3l5.4 5.4 1.4-1.4-5.4-5.4A8 8 0 0 0 10 2Zm0 2a6 6 0 1 1 0 12 6 6 0 0 1 0-12Z"/>
                    </svg>
                    <input type="search" x-ref="q" x-model="q" data-launcher-search
                           autocomplete="off" enterkeyhint="go"
                           @keydown.down.prevent="first()"
                           @keydown.enter.prevent="openFirst()"
                           aria-label="{{ __('core.ui.all_modules_search') }}"
                           aria-controls="module-launcher-grid"
                           placeholder="{{ __('core.ui.all_modules_search') }}"
                           class="min-w-0 flex-1 border-0 bg-transparent text-sm text-(--color-ink) outline-none
                                  placeholder:text-(--color-ink-placeholder)">
                </label>

                <button type="button" @click="close()"
                        class="grid size-[44px] shrink-0 place-items-center rounded-full text-(--color-ink-muted)
                               hover:bg-(--color-surface-hover)
                               focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--color-brand-500)"
                        aria-label="{{ __('core.action.close') }} (Esc)">
                    <svg viewBox="0 0 24 24" class="size-[20px] fill-current" aria-hidden="true">
                        <path d="m12 10.6 5.3-5.3 1.4 1.4-5.3 5.3 5.3 5.3-1.4 1.4-5.3-5.3-5.3 5.3-1.4-1.4 5.3-5.3-5.3-5.3 1.4-1.4z"/>
                    </svg>
                </button>
            </div>

            <div id="module-launcher-grid" role="grid" aria-label="{{ __('core.ui.all_modules') }}" class="mt-8"
                 @keydown="move($event)">
                <div role="row" data-launcher-row
                     class="grid grid-cols-3 gap-x-2 gap-y-5 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6">
                    @foreach ($tiles as $tile)
                        <div role="gridcell" data-launcher-cell data-launcher-keys="{{ $tile['keys'] }}">
                            <a href="{{ $tile['url'] }}" data-launcher-tile="{{ $tile['code'] }}"
                               class="group/tile flex flex-col items-center gap-2 rounded-(--radius-card) px-1 py-3 text-center
                                      transition-colors hover:bg-(--color-surface-hover)
                                      focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--color-brand-500)"
                               @if ($tile['waiting'] > 0)
                                   aria-label="{{ $tile['label'] }} — {{ __('core.ui.waiting_signature', ['count' => $tile['waiting']]) }}"
                               @endif>
                                {{-- ⭐ টাইলের রং লঞ্চারের নিজের তালিকা থেকে (`--color-launcher-<কোড>`, tokens.css; মালিক, ২১ অক্টোবর ২০২৬: "Odoo-র মতো")
                                     — রেলের টিল নয়; রেল ABOS রূপে এক রঙেই থাকে (৪ সেপ্টেম্বরের সিদ্ধান্ত) --}}
                                <span class="relative grid size-[64px] shrink-0 place-items-center rounded-[16px] text-white shadow-sm
                                             transition-transform group-hover/tile:-translate-y-px"
                                      style="background: var(--color-launcher-{{ $tile['code'] }})"
                                      aria-hidden="true">
                                    <x-ui.icon :name="$tile['icon']" :size="34" drawn />
                                    @if ($tile['waiting'] > 0)
                                        <span data-launcher-waiting="{{ $tile['waiting'] }}"
                                              title="{{ __('core.ui.waiting_signature', ['count' => $tile['waiting']]) }}"
                                              class="absolute -end-[6px] -top-[6px] flex min-w-[20px] items-center justify-center rounded-full
                                                     border-2 border-(--color-surface-card) bg-(--color-danger) px-[5px] text-[11px]
                                                     font-semibold leading-[16px] text-white">{{ $tile['waiting'] > 99 ? '99+' : $tile['waiting'] }}</span>
                                    @endif
                                </span>
                                <span class="w-full text-[13px] leading-snug font-semibold [overflow-wrap:anywhere]
                                             text-(--color-ink)">{{ $tile['label'] }}</span>
                            </a>
                        </div>
                    @endforeach
                </div>
            </div>

            <p x-show="none" x-cloak data-launcher-none class="mt-8 text-center text-sm text-(--color-ink-muted)">
                {{ __('core.ui.all_modules_none') }}
            </p>
        </div>
    </div>
</div>
