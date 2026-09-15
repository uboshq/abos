{{--
    ছবি তোলার পর্দা — চার কোণ টেনে কাগজ সোজা করা।

    ── কেন এটা শেলে, প্রতিটা ফর্মে নয় ──────────────────────────────────
    ছবির ঘর চারটা: সংযুক্তি, প্রোফাইল, পণ্যের ছবি, কোম্পানির লোগো।
    ⓘ প্রতিটাতে এই markup বসালে চারটা কপি থাকত, আর একদিন চারটা চার রকম
    আচরণ করত — আজ সারাদিন ঠিক ঐ শ্রেণির ভুলই সারানো হয়েছে।

    ⭐ ফর্মের দিকে তাই কেবল দুইটা অ্যাট্রিবিউট লাগে:
        x-on:change="$store.scanner.begin($el, 'paper')"   ← কাগজ
        x-on:change="$store.scanner.begin($el, 'face')"    ← মুখ

    ── ⚠️ JS না চললে কী হয় ─────────────────────────────────────────────
    কিছুই ভাঙে না। পর্দাটা খোলেই না, ফাইলটা যেমন ছিল তেমনই ফর্মের সাথে
    যায়, আর [[App\Core\Engines\Image\ImageEngine]] সার্ভারে ঘুরিয়ে,
    ছোট করে, চেপে নেয়। ⭐ সোজা করাটা বাড়তি সুবিধা, শর্ত নয়।
--}}

<div
    {{-- ⭐ নিজের স্কোপ, ১৫ সেপ্টেম্বর ২০২৬ ───────────────────────────────
         নিচের সব কটা অ্যাট্রিবিউট কেবল `$store.scanner` পড়ে — যেটা
         বিশ্বজনীন। তাই স্কোপটা খালি, কিন্তু **থাকতেই হবে**।

         ⛔ এতদিন এটা ছিল না। এই কম্পোনেন্টটা বসে `layouts/app`-এর
         `<body>`-র সরাসরি সন্তান হিসেবে, আর `<html>`/`<body>` কারও
         `x-data` নেই। ফলে Alpine এই ডালটা কখনো বুট করত না।

         ⚠️ আর `[x-cloak] { display: none }` আছে বলে বুট না হওয়ার মানে
         `x-cloak` কোনোদিন মুছত না — অর্থাৎ ছবি তোলার পর্দাটা **কখনো
         খুলত না**, কোথাও নয়।

         ⓘ আর এর নিজের নকশাই এটা ঢেকে রেখেছিল: উপরের মন্তব্য বলে
         "JS না চললে কিছুই ভাঙে না" — তাই ফাইলটা দিব্যি আপলোড হত, আর
         কেউ খেয়ালই করত না সুবিধাটা মৃত। --}}
    x-data
    x-cloak
    x-show="$store.scanner.open"
    x-on:keydown.escape.window="$store.scanner.skip()"
    class="fixed inset-0 z-50 flex flex-col bg-[--surface-sunken]/95 backdrop-blur-sm"
    role="dialog"
    aria-modal="true"
    aria-labelledby="scanner-title"
>
    <header class="flex items-center justify-between gap-3 border-b border-[--line] px-4 py-3">
        <div class="min-w-0">
            <h2 id="scanner-title" class="truncate text-sm font-semibold text-[--ink]">
                <span x-show="$store.scanner.mode === 'paper'">{{ __('core.scan.title_paper') }}</span>
                <span x-show="$store.scanner.mode === 'face'">{{ __('core.scan.title_face') }}</span>
            </h2>

            {{--
                ⚠️ এই লাইনটা সৎ থাকার জন্য: কোণ খোঁজা **প্রস্তাব**, গ্যারান্টি
                নয়। ⓘ না পেলে সেটা বলা হয়, যাতে ব্যবহারকারী বুঝতে পারেন
                কোণগুলো নিজে টানতে হবে — নীরবে ভুল কোণ বসিয়ে রাখা হয় না।
            --}}
            <p class="truncate text-xs text-[--ink-soft]" x-show="$store.scanner.mode === 'paper'">
                <span x-show="$store.scanner.detected">{{ __('core.scan.found') }}</span>
                <span x-show="! $store.scanner.detected">{{ __('core.scan.not_found') }}</span>
            </p>
        </div>

        <button
            type="button"
            x-on:click="$store.scanner.skip()"
            class="shrink-0 rounded-md px-3 py-1.5 text-xs text-[--ink-soft] hover:bg-[--surface-hover]"
        >
            {{ __('core.scan.skip') }}
        </button>
    </header>

    <div class="flex flex-1 items-center justify-center overflow-auto p-4">
        <div
            class="relative select-none touch-none"
            x-bind:style="`width:${$store.scanner.viewWidth}px;height:${$store.scanner.viewHeight}px`"
            x-on:pointermove="$store.scanner.move($event)"
            x-on:pointerup="$store.scanner.drop()"
            x-on:pointerleave="$store.scanner.drop()"
        >
            <img
                x-bind:src="$store.scanner.preview"
                alt=""
                class="pointer-events-none absolute inset-0 h-full w-full"
            >

            {{-- কাগজ: চার কোণ, টেনে সরানো যায় --}}
            <template x-if="$store.scanner.mode === 'paper'">
                <div class="absolute inset-0">
                    <svg class="pointer-events-none absolute inset-0 h-full w-full" aria-hidden="true">
                        <polygon
                            x-bind:points="$store.scanner.outline"
                            fill="rgba(37,99,235,0.14)"
                            stroke="rgb(37,99,235)"
                            stroke-width="2"
                        />
                    </svg>

                    <template x-for="(corner, index) in $store.scanner.corners" x-bind:key="index">
                        {{--
                            ⚠️ ছোঁয়ার ঘরটা চোখে দেখা বিন্দুর চেয়ে বড় (৪৪px) —
                            ⓘ আঙুলের ডগা ~৪০px জায়গা ঢাকে, আর ছোট রাখলে ফোনে
                            কোণ ধরাই যেত না। দেখতে ছোট, ধরতে বড়।
                        --}}
                        <button
                            type="button"
                            x-on:pointerdown.prevent="$store.scanner.grab(index)"
                            x-bind:style="`left:${corner.x}px;top:${corner.y}px`"
                            class="absolute -ml-5.5 -mt-5.5 flex h-11 w-11 items-center justify-center rounded-full"
                            x-bind:aria-label="`{{ __('core.scan.corner') }} ${index + 1}`"
                        >
                            <span class="block h-4 w-4 rounded-full border-2 border-white bg-[rgb(37,99,235)] shadow"></span>
                        </button>
                    </template>
                </div>
            </template>

            {{-- মুখ: একটা বর্গ, টেনে সরানো ও মাপ বদলানো যায় --}}
            <template x-if="$store.scanner.mode === 'face'">
                <div class="absolute inset-0">
                    {{--
                        ⓘ গোল করে দেখানো হয়, যদিও কাটা হয় বর্গ — কারণ
                        প্রোফাইল ছবি পর্দায় সবখানে গোল হয়েই বসে
                        ([[components/ui/avatar]])। ⚠️ বর্গ দেখালে
                        ব্যবহারকারী কোণে গুরুত্বপূর্ণ কিছু রাখতেন, আর
                        সেটা পরে কাটা পড়ত।
                    --}}
                    <div
                        x-on:pointerdown.prevent="$store.scanner.grabSquare()"
                        class="absolute cursor-move rounded-full border-2 border-white shadow-[0_0_0_9999px_rgba(0,0,0,0.45)]"
                        x-bind:style="`left:${$store.scanner.square.x}px;top:${$store.scanner.square.y}px;width:${$store.scanner.square.size}px;height:${$store.scanner.square.size}px`"
                    ></div>
                </div>
            </template>
        </div>
    </div>

    <footer class="flex items-center justify-between gap-3 border-t border-[--line] px-4 py-3">
        {{-- মুখের মাপ: একটাই হাতল, কারণ বর্গের একটাই সংখ্যা --}}
        <label class="flex flex-1 items-center gap-2 text-xs text-[--ink-soft]" x-show="$store.scanner.mode === 'face'">
            {{ __('core.scan.size') }}
            <input
                type="range"
                min="20"
                max="100"
                x-bind:value="Math.round($store.scanner.square.size * 100 / Math.min($store.scanner.viewWidth, $store.scanner.viewHeight))"
                x-on:input="$store.scanner.resize($event.target.value)"
                class="w-full max-w-xs"
            >
        </label>

        <p class="flex-1 text-xs text-[--ink-soft]" x-show="$store.scanner.mode === 'paper'">
            {{ __('core.scan.hint') }}
        </p>

        <button
            type="button"
            x-on:click="$store.scanner.accept()"
            x-bind:disabled="$store.scanner.busy"
            class="shrink-0 rounded-md bg-[--accent] px-4 py-2 text-sm font-medium text-white disabled:opacity-50"
        >
            <span x-show="! $store.scanner.busy">{{ __('core.scan.apply') }}</span>
            <span x-show="$store.scanner.busy">{{ __('core.scan.working') }}</span>
        </button>
    </footer>
</div>
