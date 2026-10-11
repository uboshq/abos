{{--
    "এই লাইন" — কাউন্টারের মাঝের ডান ঘর: বাছা পণ্য, লট, পরিমাণ, দাম আর বার্তা।
    ⓘ direct/index.blade.php থেকে হুবহু সরানো (পাতা সাজানো, ১০ অক্টোবর ২০২৬; NoScreenGrowsPastAThousandLinesTest — পাতাটা
    ১৩৬৪ লাইনে পৌঁছেছিল)। partial মূল পাতার সব চলক পায়, Alpine-এর স্কোপও একই — আচরণ বদলায়নি।
--}}
<div class="ds-rcpt space-y-2 lg:col-start-2 lg:col-end-[-1] lg:row-start-1" data-this-line>
{{-- ⭐ মাথার সারি: "এই লাইন" বাঁয়ে, টাকা ডানে সবুজ ঘরে — মালিক, ৪ অক্টোবর ২০২৬:
     "এই লাইন bame capiye nit mulo dane daw, background sobuj takuk, নিট মূল্য likhar dorkar nai"।
     ⓘ টাকাটা `entryNet` — বাঁয়ের হিসাবের হুবহু একই সংখ্যা। --}}
<div class="ds-rt flex items-center justify-between gap-2">
    <span>{{ __('sales::field.this_line') }}</span>
    <b class="ds-amt num rounded-(--radius-card) px-3 py-1 text-2xl"
       style="letter-spacing: 0" x-text="'৳' + money(entryNet)"></b>
</div>

{{-- ⓘ বিন্দু-দাগের সারি: পরিমাণ × দর · ফ্রি · ছাড়; সবুজ ঘরে নিট মূল্য; নিচে কার্টে কয়টা আর কত --}}
<dl class="text-xs">
    <div class="ds-rkv">
        <dt class="num text-(--color-ink-muted)"
            x-text="qty($num(entry.qty || 0)) + ' ' + ((picked && picked.unit) || '') + ' × ' + money(entry.rate || 0)"></dt>
        <dd class="num" x-text="money(entryBase)"></dd>
    </div>

    @if ($show['free_qty'])
        <div class="ds-rkv">
            <dt class="text-(--color-ink-muted)">{{ __('sales::field.free_short') }}</dt>
            <dd class="num" x-text="qty($num(entry.freeQty || 0)) + ' ' + ((picked && picked.unit) || '')"></dd>
        </div>
    @endif

    {{-- ছাড় — এখানেই লেখা যায়, টাকায় বা শতাংশে --}}
    @if ($show['line_discount'])
        <div class="ds-rkv items-center">
            <dt class="text-(--color-ink-muted)">{{ __('sales::field.line_discount') }}</dt>
            <dd class="flex flex-1 items-center gap-1">
                <input type="text" inputmode="decimal" x-model="entry.discountInput"
                       placeholder="{{ __('sales::field.amount_or_pct') }}"
                       class="num h-(--spacing-inline) w-20 rounded-(--radius-field) border
                              border-(--color-border) bg-(--color-surface-card) px-1 text-end">
                <span class="num ms-auto w-16 text-end" x-text="money(entryDiscount)"></span>
            </dd>
        </div>
    @endif
</dl>

<div class="text-xs">
    <div class="flex justify-between gap-2" data-row="in-cart">
        <span class="text-(--color-ink-muted)">{{ __('sales::field.running_total') }}</span>
        <span class="num font-semibold"
              x-text="lines.length + ' ' + @js(__('sales::field.items')) + ' · ৳' + money(subTotal)"></span>
    </div>

    <template x-if="hasCustomer && hasCreditLimit">
            <div class="flex justify-between">
                <span class="text-(--color-ink-muted)">{{ __('sales::field.credit_left') }}</span>
                <span class="num font-semibold"
                      :class="! termUsesCredit
                        ? 'text-(--color-ink-muted)'
                        : (creditLeft > 0 ? 'text-(--color-success)' : 'text-(--color-danger)')"
                      x-text="'৳' + money(creditLeft > 0 ? creditLeft : 0)"></span>
            </div>
        </template>

    <template x-if="hasCustomer && hasCreditLimit && creditOver > 0">
            <div class="mt-0.5 flex justify-between rounded-(--radius-field) bg-(--color-danger)
                        px-1.5 font-bold text-white" data-row="credit-over">
                <span>{{ __('sales::message.credit_over') }}</span>
                <span class="num" x-text="'৳' + money(creditOver)"></span>
            </div>
        </template>
</div>

{{-- ⭐ তিনটা বোতাম — ডান কলামে, "এই লাইন" বাক্সের **ঠিক নিচে**।
     মালিকের ছবি, ২৪ সেপ্টেম্বর ২০২৬: লাল বাক্স দিয়ে ঘেরা
     ফাঁকা জায়গাটা, আর তীর এঁকে দেখানো।

     ── ⛔ একই দিনে তিনবার সরেছে, আর তিনবারই আমার পাঠের ভুল ──
     ⓘ ১ম: *"ager jaygay daw"* → আমি ধরলাম "এই লাইন" বাক্সের
     **ভিতরে** (৩ সেপ্টেম্বরের জায়গা)। ভুল।
     ⓘ ২য়: *"Qty … Sales Rate … কার্টে যোগ করুন — ei gulor dane"*
     → আমি ধরলাম এন্ট্রির সারির ভিতরে, অষ্টম কলাম। আবার ভুল —
     ওটা এন্ট্রি কার্ডের ভিতরে পড়ে, আর তিনি কার্ডের **বাইরের**
     ফাঁকা জায়গাটা দেখাচ্ছিলেন।
     ⭐ ৩য়: ছবিতে লাল বাক্স — এই জায়গাটা।

     ⚠️ দুইবার শব্দ পড়ে ধরে নিয়েছি, দুইবারই ভুল। ⛔ পর্দার
     জায়গা শব্দে বোঝা যায় না; ছবিটাই একমাত্র সঠিক উৎস ছিল।

     ── ⓘ কেন এখানে বসলে ঠিক জায়গায় পড়ে ──────────────────
     মোড়ক গ্রিডটা `lg:grid-cols-[1fr_14rem]`, আর `items-start`
     বলে ডান কলামটা টানটান হয় না — তাই "এই লাইন"-এর নিচে
     জায়গাটা ফাঁকা পড়ে থাকত। ⭐ এই বাক্সটা ঐ কলামের দ্বিতীয়
     সারি, তাই ঠিক ওখানেই বসে।

     ⚠️ `lg:col-start-2` ছাড়া চলে না: `lg:` -এর নিচে কলাম
     একটাই, আর তখন বাক্সটা নিজে থেকেই পুরো প্রস্থে নামে —
     ⓘ ফোনে সেটাই ঠিক।

     ⛔ ক্রমটা মালিকের লেখা, অনুমান করে বদলাবেন না। আর
     "সব মুছুন" নিচের বারে আলাদাই থাকে — দুইটা মুছে ফেলার
     বোতাম পাশাপাশি থাকলে ভুল চাপ পড়া নিশ্চিত। --}}
{{-- ⭐ তিনটা পাশাপাশি, একটার নিচে একটা নয় — মালিকের নির্দেশ,
     ২৪ সেপ্টেম্বর ২০২৬: *"egulo pasa pasi bosbe"*।

     ⓘ ৬ সেপ্টেম্বরেও তিনি একই কথা বলেছিলেন (*"Gift, Costing,
     Clear Data এক লাইন রাখো"*) — অর্থাৎ এটা নতুন সিদ্ধান্ত নয়,
     একই পছন্দ দ্বিতীয়বার। ⚠️ আমি জায়গা বদলাতে গিয়ে ছাঁচটাও
     বদলে ফেলেছিলাম, অথচ তিনি কেবল জায়গার কথা বলেছিলেন।

     ⛔ `grid-cols-3`, `flex` নয়: তিনটা ঘর **সমান ভাগ** পায়,
     তাই "ঘর খালি করুন" লম্বা বলে সে বেশি জায়গা টেনে নেয় না।
     ⓘ কলামটা ১৪rem, তাই প্রতিটা বোতাম ≈৯০px — লম্বা লেখাটা
     দুই লাইনে ভাঁজ হয়, আর `leading-tight` তাতে উচ্চতা ধরে রাখে। --}}
<div class="grid grid-cols-3 items-start gap-1">
    @if ($show['gift'])
        {{-- ⚠️ এখানে `:disabled`, একটা কোলন — আর নিচে
             "নিশ্চিত করুন" বোতামে `::disabled`, দুইটা।
             **দুইটাই ঠিক**: Blade কেবল কম্পোনেন্ট ট্যাগে
             `::`-কে `:`-এ নামায়। সাধারণ ট্যাগে দুইটা দিলে
             অ্যাট্রিবিউটটা হুবহু `::disabled` হয়ে ব্রাউজারে
             যায়, আর **Alpine নীরবে উপেক্ষা করে** — বোতামটা
             সক্রিয় দেখাত, চাপলে কিছু হত না।

             ⭐ পর্দায় কিছুই ভাঙা দেখাত না, JS ত্রুটিও ছিল না।
             ধরেছে `AlpineBindingsReachTheBrowserTest`। --}}
        <button type="button" @click="openGift()" :disabled="! picked"
                class="w-full rounded-(--radius-field) leading-tight border border-(--color-badge-pending-ink)/30 disabled:opacity-40
                       bg-(--color-badge-pending-bg) px-1 py-1.5 text-2xs font-medium
                       text-(--color-badge-pending-ink)">
            {{ __('sales::field.gift') }}
        </button>
    @endif

    {{-- ক্রয়মূল্য — ভেতরের কথা, গ্রাহককে পড়ে শোনানোর
         জন্য নয়। তাই বোতামের পেছনে: চোখে পড়ে না,
         কিন্তু দরকার হলে এক চাপ দূরে।
         ⛔ চাবি (`sales.cost.view`) না থাকলে বোতামটাই নেই —
         সংখ্যাটাও নিয়ামক পাঠায় না। --}}
    @can('sales.cost.view')
    <button type="button" @click="showCosting = ! showCosting"
            class="w-full rounded-(--radius-field) leading-tight border border-(--color-border)
                   px-1 py-1.5 text-2xs font-medium">
        {{ __('sales::field.costing') }}
    </button>
    @endcan

    <button type="button" @click="clearEntry()"
            class="w-full rounded-(--radius-field) leading-tight bg-(--color-danger)/10 px-2 py-1.5
                   text-2xs font-medium text-(--color-danger) hover:bg-(--color-danger)/20">
        {{ __('sales::action.clear_data') }}
    </button>

    {{-- ⓘ ক্রয়মূল্যের সংখ্যাটা তিন কলাম জুড়ে, বোতামের সারির নিচে।
         ⚠️ একটা কলামে বসালে ≈৯০px-এ একটা দাম কাটা পড়ত। --}}
    @can('sales.cost.view')
    <span x-show="showCosting" x-cloak
          class="num col-span-full text-end text-xs text-(--color-ink-muted)"
          x-text="picked ? money(picked.cost) : ''"></span>
    @endcan
</div>

{{-- ⭐ বাকির সীমার সতর্কতা — তিন বোতামের নিচে। মালিকের ছবি,
     ২৬ সেপ্টেম্বর ২০২৬: তীর এঁকে এই জায়গা, আর *"jast warning
     but atkabena"*।

     ⓘ এটা কেবল জানায়, সারি তোলা আটকায় না। ⛔ দেয়াল "নিশ্চিত
     করুন"-এ (পপআপ আর শব্দ), আর তার পিছনে সেবা
     ([[CreditExposure::assertRoom()]])। --}}
{{-- ⓘ সীমার সতর্কতা এখন পপ-আপ (উপরে, `credit-warning`) — মালিকের ছবি,
     ২৭ সেপ্টেম্বর ২০২৬ (সন্ধ্যা)। এখানের লেখাটা সরানো হলো। --}}
</div>{{-- ডান কলামের মোড়ক শেষ --}}

{{-- ⭐ বার্তা তিনটা বাক্সের নিচে, পুরো প্রস্থে — মালিক, ৪ অক্টোবর ২০২৬: ⛔ পরিমাণের সারির ভিতরে থাকলে
     "এই লাইন" বাক্সটা লম্বা হত। ⓘ "আর N নিলে ফ্রি" কেবল অনুপাত না মিললে ([[fillFreeFromTheRatio()]])। --}}
<div class="ds-msgs space-y-1">
    @if ($show['free_qty'])
        <div x-show="freeWarning" x-cloak
             class="rounded-(--radius-field) bg-(--color-badge-danger-bg)
                    px-3 py-1.5 text-xs text-(--color-badge-danger-ink)"
             x-text="freeWarning" role="alert"></div>
        {{-- ⭐ ফ্রি-ভাণ্ডারের বাইরে — হলুদ, থামায় না (সুইচ `sales.free_beyond_pool`, ৪ অক্টোবর ২০২৬) --}}
        <div x-show="freeBeyondNote" x-cloak data-free-beyond-pool
             class="rounded-(--radius-field) bg-(--color-badge-pending-bg)
                    px-3 py-1.5 text-xs font-semibold text-(--color-badge-pending-ink)"
             x-text="freeBeyondNote" role="status"></div>
    @endif
    <div x-show="lotWarning" x-cloak
         class="rounded-(--radius-field) bg-(--color-badge-danger-bg)
                px-3 py-1.5 text-xs text-(--color-badge-danger-ink)"
         x-text="lotWarning" role="alert"></div>
    <div x-show="freeHint" x-cloak
         class="rounded-(--radius-field) bg-(--color-badge-pending-bg)
                px-3 py-1.5 text-xs text-(--color-badge-pending-ink)"
         x-text="freeHint" role="status"></div>
</div>
