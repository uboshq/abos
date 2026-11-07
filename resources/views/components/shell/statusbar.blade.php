{{--
    স্ট্যাটাস বার — সেকশন ১৫.১।

    বাঁয়ে পণ্যের পরিচয় │ মাঝে চলন্ত নোটিশ │ ডানে কে বসে আছেন

    কোম্পানি ও শাখা এখানে ছিল, এখন নেই — দুটোই টপবারের বাঁ কোণে বড় করে
    দেখা যায়। একই তথ্য দুই জায়গায় থাকা মানে পর্দার নিচের পুরো একটা সারি
    নষ্ট, আর ওই সারিটা আরও কাজের জিনিসে লাগে।

    ওই জায়গায় এখন নোটিশ: এই মুহূর্তে কী নজর দেওয়া দরকার। কিছু না থাকলে
    বারটা চুপ থাকে — "সব ঠিক আছে" লিখে জায়গা ভরাট করার মানে নেই।

    মোবাইলে লুকানো: ৩২০px-এ এতগুলো তথ্য ধরে না, আর কেটে দেখানোর চেয়ে না
    দেখানো ভালো।
--}}
@php
    $user = auth()->user();

    /*
     * ⭐ নিচের চলন্ত বারে কেবল মানুষের লেখা ঘোষণা — মালিক, ২১ অক্টোবর ২০২৬ (fe-র মাধ্যমে): *"জরুরি সতর্কতা উপরে স্থির,
     * ঘোষণা নিচে চলন্ত"* ([[StatusNotices::forTicker()]])। ⛔ যন্ত্রের সতর্কতা কখনো এখানে নয় — সেগুলো উপরের স্থির ফিতায়
     * ([[shell.notice-banner]]), আর "জরুরি" টিক দেওয়া ঘোষণাও সেখানে।
     */
    $announcements = $user ? app(\App\Core\Services\StatusNotices::class)->forTicker() : [];
@endphp

{{-- ⓘ ঘোষণা থাকলে ফুটার মোবাইলেও দেখা যায় — কেবল চলন্ত সারিটা, নিচের মেনুর ঠিক উপরে (`data-has-ticker`, app.css);
     পরিচয় আর নাম-পদবি তখনও কেবল বড় পর্দায়। ঘোষণা না থাকলে মোবাইলে আগের মতোই লুকানো। --}}
<footer data-footer @if ($announcements !== []) data-has-ticker @endif
        class="fixed inset-x-0 bottom-0 z-20 {{ $announcements !== [] ? 'flex' : 'hidden' }} h-(--spacing-status-bar) items-center gap-4
               border-t border-(--color-footer-border) bg-(--color-footer) px-3
               text-2xs text-(--color-footer-ink) md:flex">

    {{-- ⭐ বাঁ কোণ — পণ্যের পরিচয়। মালিকের নির্দেশ, ১৪ সেপ্টেম্বর ২০২৬:
         *"Footer er bame ABOS v0.1.0 © 2026 All Rights Reserved, Dane
         User Name Desig"*।

         ── ⓘ দুই দিক অদলবদল হলো ────────────────────────────────────
         একদিন আগে ঠিক উল্টোটা ছিল: বাঁয়ে নাম-পদবি, ডানে পণ্যের দল।
         ⓘ দুইটা দলই আগের মতোই আছে, কেবল পাশ বদলেছে — তাই এটা নতুন
         তথ্য নয়, সাজানোর সিদ্ধান্ত।

         ── ⭐ "সর্বস্বত্ব সংরক্ষিত" ফিরে এসেছে ──────────────────────
         ⚠️ ১৩ সেপ্টেম্বর মালিক বলেছিলেন *"All Rights Reserved. eta
         bad"*, আর আজ নিজেই ফিরিয়ে আনতে বলেছেন। ⓘ চাবিটা
         (`core.brand.rights`) সেদিন **মোছা হয়নি**, আর সেটাই আজ কাজে
         লাগল — দুই ভাষার অনুবাদ দুইটাই জায়গায় ছিল, নতুন করে কিছু
         লিখতে হয়নি।

         ── ⓘ ক্রমটা যা বলে ─────────────────────────────────────────
         পণ্য → কোন সংস্করণ → কোন বছর → স্বত্ব। মালিক যে ক্রমে লিখে
         দিয়েছেন, হুবহু সেই ক্রম।

         ⚠️ সালটা `now()->year` থেকে, চাবিতে লেখা নেই — নাহলে প্রতি
         জানুয়ারিতে পুরনো হয়ে যেত আর কেউ বদলাতে মনে রাখত না।

         ⓘ চিহ্নটা সব মাপে থাকে, কেবল লেখা সরু পর্দায় লুকায়: চিহ্নের
         জায়গা লাগে ২০px, আর ওটাই লাইনটার একমাত্র অংশ যেটা দূর থেকে
         চেনা যায়। --}}
    {{-- ⭐ বাঁয়ে আইকনসহ জীবন্ত লোগো, তারপর "Powered by (চিহ্ন) UNIVER BANGLADESH" — মালিক, ৬ নভেম্বর ২০২৬: "footer eo icon soho live logo
         bosabe, sathe Powered by (icon) UNIVER BANGLADESH dibe"। ⓘ সংস্করণ আর তারিখ লগইন পাতায় সরল ("Developed by"-এর নিচে)। --}}
    <span class="hidden shrink-0 items-center gap-2 md:flex" data-footer-brand>
        <x-brand.live-logo class="h-[18px] w-auto" />
        <span class="flex items-center gap-1">
            {{ __('core.brand.powered_by') }}
            <img src="{{ asset('brand/univer-mark.png') }}" alt="" width="18" height="18" class="size-[18px] object-contain">
            <b class="font-semibold tracking-wide" style="color: var(--color-univer)">UNIVER BANGLADESH</b>
        </span>
    </span>

    {{-- ⭐ মাঝখানে চলন্ত ঘোষণা — মালিক, ২১ অক্টোবর ২০২৬ (fe-র মাধ্যমে): *"জরুরি সতর্কতা উপরে স্থির, ঘোষণা নিচে চলন্ত"*।

         ── ⓘ ইতিহাস ─────────────────────────────────────────────────────────────────────
         ১১ অক্টোবর সব নোটিশ এখানে ঘুরত; ২০ অক্টোবর (ওয়েবের মান ধাপ ২) সব উপরের স্থির ফিতায় উঠল, ফুটারে কেবল
         সংস্করণ · © · নাম। ২১ অক্টোবর মালিকের ভাগ: কাজের সতর্কতা উপরে স্থির, মানুষের লেখা ঘোষণা এখানে চলন্ত।
         ⛔ যন্ত্রের সতর্কতা এখানে কখনো নয় ([[StatusNotices::forTicker()]])। ঘোষণা না থাকলে চলন্ত বারের কোনো চিহ্নই আঁকা হয়
         না — ফুটার তখন ২০ অক্টোবরের মতো সংস্করণ · © · নাম।

         ── ⭐ চলার নিয়ম (WCAG 2.2.2), ১১ অক্টোবরের ccc065138 থেকে ফেরানো, আর যা ছিল না তা যোগ ────────────────
           • ধীরে — সেকেন্ডে ~৪০px, লেখার দৈর্ঘ্য ধরে ([[noticeTicker]], `--ticker-duration`)
           • মাউস রাখলে আর কী-বোর্ডের ফোকাস এলে থামে (`group-hover`, `group-focus-within`) — সরে যাওয়া লিংক চাপা যায় না
           • পাশে ⏸/▶ বোতাম, বাছাইটা এই ব্রাউজারে মনে থাকে (`group-data-[state=paused]`) — ⛔ ১১ অক্টোবরে এটা ছিল না,
             আর ঠিক এই অভাবেই ২০ অক্টোবরে চলন্ত বার উঠে গিয়েছিল
           • `motion-safe:` — যিনি চলন্ত জিনিস বন্ধ রেখেছেন তাঁর পর্দায় নড়ে না; বোতামটাও তখন লুকায়, থামানোর কিছু নেই
           • দুইটা কপি, এক কপির সমান সরে (-৫০%) — লুপে ফাঁক নেই; প্রতিটা কপি অন্তত বারের সমান চওড়া, তাই একই বাক্য কখনো
             পাশাপাশি দুইবার চোখে পড়ে না (HP-র অভিযোগ, ২৫ আগস্ট ২০২৬: *"ফুটারে ব্যাকআপ সতর্কবার্তা দুইবার ওভারল্যাপ"*)।
             ⛔ ১১ অক্টোবরের `min-w-full` এটা করত না, আর ২১ অক্টোবর ছবিতে ধরা পড়ল: কপির ১০০% মাপা হয় চলন্ত সারির নিজের
             চওড়া ধরে, আর সারিটা লেখার মাপে — তাই ছোট ঘোষণায় দুইটা কপিই একসাথে পর্দায়। ⭐ এখন সারিটা অন্তত বারের দ্বিগুণ
             (`min-w-[200%]`, বারের চওড়া জানা), আর প্রতিটা কপি সারির অর্ধেক (`min-w-1/2`) — অর্থাৎ অন্তত একটা গোটা বার।
           • দ্বিতীয় কপি `aria-hidden`, তার লিংক Tab-এ ধরা পড়ে না — পর্দা-পাঠক একবারই পড়ে
           • প্রতিটা ঘোষণা ক্লিক করলে পুরো নোটিশ খোলে (system_admin.notice.show); মেয়াদ পেরোলে নিজে থেকেই সরে
             ([[NoticeBoard::forTicker()]]); ক্রম অগ্রাধিকার ধরে, তারপর নতুনটা; চলন্ত সারিতে কোনো ✕ নেই
           • ছাপায় আসে না (`print-hide`); এক সারি, ৩৭৫px-এও (`whitespace-nowrap`, বাইরেটা `overflow-hidden`) --}}
    @if ($announcements !== [])
        <div data-ticker x-data="noticeTicker" :data-state="state" data-state="running"
             data-pause-label="{{ __('core.notice.ticker_pause') }}"
             data-play-label="{{ __('core.notice.ticker_play') }}"
             role="region" aria-label="{{ __('core.notice.ticker_label') }}"
             class="group print-hide flex min-w-0 flex-1 items-center gap-2 md:border-s md:border-(--color-border) md:ps-3">
            <div class="relative min-w-0 flex-1 overflow-hidden">
                <div data-ticker-track
                     class="inline-flex min-w-[200%] whitespace-nowrap will-change-transform
                            motion-safe:animate-[abos-ticker_var(--ticker-duration,40s)_linear_infinite]
                            group-hover:[animation-play-state:paused]
                            group-focus-within:[animation-play-state:paused]
                            group-data-[state=paused]:[animation-play-state:paused]">
                    @for ($copy = 0; $copy < 2; $copy++)
                        <span class="inline-flex min-w-1/2 shrink-0 items-center gap-6 pe-12"
                              @if ($copy === 0) data-ticker-copy @else aria-hidden="true" @endif>
                            @foreach ($announcements as $announcement)
                                @php
                                    // ⭐ যন্ত্রের জরুরি কথা নিজের রঙে — লাল এখনই, হলুদ অপেক্ষা; মানুষের ঘোষণা আগের মতো (মালিক, ৭ নভেম্বর ২০২৬, পথ ২)
                                    $tickerTone = ($announcement['kind'] ?? null) === 'own' ? 'own' : ($announcement['tone'] ?? 'info');
                                    $tickerClass = match ($tickerTone) {
                                        'danger' => 'rounded-(--radius-pill) bg-(--color-badge-danger-bg) px-2 font-semibold text-(--color-badge-danger-ink)',
                                        'warning' => 'rounded-(--radius-pill) bg-(--color-badge-warning-bg) px-2 font-semibold text-(--color-badge-warning-ink)',
                                        'pending' => 'rounded-(--radius-pill) bg-(--color-badge-pending-bg) px-2 font-semibold text-(--color-badge-pending-ink)',
                                        default => 'text-(--color-badge-info-ink)',
                                    };
                                @endphp
                                <span class="inline-flex items-center gap-1" data-ticker-tone="{{ $tickerTone }}">
                                    @if ($announcement['url'])
                                        <a href="{{ $announcement['url'] }}" data-ticker-item
                                           class="inline-flex items-center gap-1.5 text-xs {{ $tickerClass }} underline-offset-2 hover:underline"
                                           @if ($copy === 1) tabindex="-1" @endif>
                                            <span class="size-1.5 shrink-0 rounded-full bg-current" aria-hidden="true"></span>
                                            {{ $announcement['text'] }}
                                        </a>
                                    @else
                                        <span data-ticker-item class="inline-flex items-center gap-1.5 text-xs {{ $tickerClass }}">
                                            <span class="size-1.5 shrink-0 rounded-full bg-current" aria-hidden="true"></span>
                                            {{ $announcement['text'] }}
                                        </span>
                                    @endif

                                    {{-- ⛔ সরানোর ✕ নেই (fe, ২১ অক্টোবর ২০২৬) — চলন্ত বোতাম চাপা যায় না, আর চাপলে কী হয় তাও
                                         অস্পষ্ট ছিল। ⓘ থামাতে ⏸, পুরোটা পড়তে বা সরাতে নোটিশের পাতা; উপরের ফিতার নিজের ✕ যেমন ছিল। --}}
                                </span>
                            @endforeach
                        </span>
                    @endfor
                </div>
            </div>

            {{-- ⏸/▶ — এখন চাপলে কী হবে সেটাই নাম; JS ছাড়া নামটা "থামান" আর চলাটা কেবল মাউস/ফোকাসে থামে --}}
            <button type="button" data-ticker-toggle @click="toggle"
                    :aria-label="label" :title="label"
                    aria-label="{{ __('core.notice.ticker_pause') }}" title="{{ __('core.notice.ticker_pause') }}"
                    class="grid size-6 shrink-0 place-items-center rounded-(--radius-field) text-(--color-ink-muted)
                           hover:bg-(--color-surface-hover) hover:text-(--color-ink) motion-reduce:hidden">
                {{-- ⭐ আঁকা চিহ্ন, লেখা নয় (fe, ২১ অক্টোবর ২০২৬) — ⛔ ⏸ লেখাটা বাংলা ফন্টে "॥" দেখাত --}}
                {{-- ⓘ ভরাট Material চিহ্ন, ১৮px, বোতাম ২৪px (size-6) — রেখার ⏸ ১৪px-এ "॥" পড়াত --}}
                <x-ui.icon name="pause" :size="18" data-ticker-icon="pause" x-show="running" />
                <x-ui.icon name="play" :size="18" data-ticker-icon="play" x-show="paused" x-cloak />
            </button>
        </div>
    @else
        {{-- ⓘ ঘোষণা নেই — ফাঁকা অংশটা নাম-পদবিকে ডানে ঠেলে রাখে --}}
        <span class="min-w-0 flex-1" aria-hidden="true"></span>
    @endif

    {{-- ⭐ ডান কোণ — কে বসে আছেন। মালিকের নির্দেশ, ১৪ সেপ্টেম্বর ২০২৬:
         *"Dane User Name Desig"*।

         ⓘ যুক্তিটা ডিপোর: একটা কম্পিউটার কয়েকজন ভাগ করে ব্যবহার করেন,
         তাই "আমি কে হয়ে বসে আছি" প্রশ্নটা ঘন ঘন আসে।

         ── ⛔ এই মন্তব্যে নির্দেশিকার নাম আক্ষরিকভাবে লেখা যাবে না ────
         ⚠️ এই ফাইলেই একবার এখানে উপরের PHP ব্লকটাকে তার নিজের নামে ডাকা
         হয়েছিল (অ্যাট-চিহ্ন সহ)। ⓘ Blade নির্দেশিকা খোঁজে **মন্তব্য
         ফেলে দেওয়ার আগে**, তাই সে ঐ শব্দটাকে সত্যিকারের নির্দেশ ভেবে
         নিল আর পরের `endphp` পর্যন্ত **সব গিলে ফেলল** — প্রায় একশো
         লাইন, অর্থাৎ নাম-পদবির ব্লকটাও।

         ⛔ লক্ষণটা নিষ্ঠুর: কোনো ত্রুটি নেই, কম্পাইল সফল, শুধু পর্দায়
         অর্ধেক ফুটার নেই। ⓘ ধরা পড়েছে উৎস ও কম্পাইল করা ফলের **বাইট
         মিলিয়ে** (২১,৭৪২ → ৫,৪৫০), তারপর লাইন ধরে দ্বিখণ্ডন করে।

         ⭐ নিয়মটা সাধারণ: ব্লেড মন্তব্যে কোনো নির্দেশিকার নাম
         অ্যাট-চিহ্নসহ লিখো না — নামটা লিখতে হলে চিহ্ন ছাড়া।

         ⚠️ `truncate` আর `max-w-` — লম্বা নাম বা পদবিতে **লেখাটাই
         কাটবে, বারটা নয়**। ⓘ flex-এ ন্যূনতম প্রস্থ ডিফল্টে `auto`,
         তাই `min-w-0` ছাড়া `truncate` কখনো কাজ করত না — এই ফাইলেই ঐ
         ভুলটা আগে একবার ধরা পড়েছে (টপবারের প্রোফাইল বোতামে)।

         ⓘ মাঝের নোটিশ বারটা `flex-1`, সে-ই এই দলটাকে ডান কিনারায় ঠেলে
         রাখে — কোনো `ms-auto` লাগে না। --}}
    {{-- ⭐ নামটা একটা পিলে — মালিকের নির্দেশ, ২৮ সেপ্টেম্বর ২০২৬: *"user er namta ro zate
         sundor lage … onno ekta color braground, font gulo ro sposto"*। ⓘ ব্র্যান্ডের হালকা
         রঙ, ছোট আদ্যক্ষর-বৃত্ত, নাম মোটা, পদবি হালকা; বাঁয়ে পাতলা দাগ, যাতে লাল সতর্কবার্তা
         আর নাম মিশে না যায়। ⚠️ রংগুলো টোকেন থেকে, তাই দুই মোডেই নিজে থেকে মানায়। --}}
    {{-- ⭐ ডানে কেবল স্বত্ব — মালিক, ৬ নভেম্বর ২০২৬: "© 2026 সর্বস্বত্ব সংরক্ষিত … foter er dane"; নাম-পদবি টপবারে ছবির বাঁয়ে।
         ⓘ সালটা `now()->year` থেকে, নইলে প্রতি জানুয়ারিতে পুরনো হত। --}}
    <span class="hidden shrink-0 md:inline" data-footer-rights>© {{ now()->year }} {{ __('core.brand.rights') }}</span>
</footer>
