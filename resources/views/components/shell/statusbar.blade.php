{{--
    স্ট্যাটাস বার — সেকশন ১৫.১।

    Status │ Notice │ FY │ User │ Version

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
    $year = $user?->currentCompany?->currentFinancialYear();
    $notices = $user ? app(\App\Core\Services\StatusNotices::class)->all() : [];
@endphp

<footer data-footer class="fixed inset-x-0 bottom-0 z-20 hidden h-(--spacing-status-bar) items-center gap-4
               border-t border-(--color-footer-border) bg-(--color-footer) px-3
               text-2xs text-(--color-footer-ink) md:flex">

    <span class="flex shrink-0 items-center gap-1.5">
        <span class="size-2 rounded-full bg-(--color-success)" aria-hidden="true"></span>
        {{ __('core.status_bar.operational') }}
    </span>

    {{-- কার তৈরি — সেকশন ১৭.২।

         প্রিন্টের মতো এখানেও, কারণ পর্দাটা সারাদিন খোলা থাকে। বন্ধ করার
         সুইচটা প্রিন্টের জন্য আলাদা: কাগজ বাইরে যায়, পর্দা যায় না। --}}
    {{-- চিহ্নটা সব মাপে থাকে, শুধু লেখাটা সরু পর্দায় লুকায়।

         আগে গোটা ব্লকটাই `lg:` থেকে দেখা যেত, তাই ল্যাপটপের নিচের
         মাপগুলোয় নির্মাতার কোনো চিহ্নই থাকত না — অথচ চিহ্নটার জায়গা
         লাগে ২০px, আর ওটাই লাইনটার একমাত্র অংশ যেটা দূর থেকে চেনা যায়। --}}
    <span class="flex shrink-0 items-center gap-1.5">
        <span class="hidden lg:inline">{{ __('core.brand.powered_by') }}</span>
        {{-- চিহ্নটা লেখার মাঝখানে, দুই শব্দের পরে আর নামের আগে — যেভাবে
             লকআপটা আঁকা। ছবিটা ABOS-এ ছিলই না, তাই লাইনটা কেবল লেখা হয়ে
             ছিল: যে চিহ্ন কোথাও নেই সেটা কেউ খুঁজতেও যায় না।

             aria-hidden, কারণ নামটা পাশেই লেখা আছে — পর্দা-পাঠক যেন একই
             কথা দুবার না বলে। --}}
        <img src="{{ asset('brand/univer-mark.png') }}" alt="" aria-hidden="true"
             class="size-5 shrink-0 object-contain">
        <span class="hidden font-medium text-(--color-ink-body) sm:inline">
            {{ __('core.brand.powered_by_name') }}
        </span>
    </span>

    {{-- নোটিশগুলো — ক্লিকযোগ্য, কারণ জানানোই যথেষ্ট নয়।

         "৩টা খসড়া ভাউচার পোস্ট হয়নি" পড়ে ব্যবহারকারী সমস্যাটা জানেন,
         কিন্তু কোথায় গিয়ে ঠিক করবেন তা নয়। লিংক ছাড়া বার্তাটা অভিযোগ,
         সাহায্য নয় (নিয়ম ১)।

         যেগুলোর কোনো পর্দা নেই (ব্যাকআপ — কমান্ড লাইনের কাজ; প্রতিষ্ঠানের
         নোটিশ — ওটা পড়ার জিনিস), সেখানে লিংকও নেই: যে লিংক কোথাও নিয়ে
         যায় না সেটাই মৃত লিংক।

         ── লেখাটা চলে, আর কেন ─────────────────────────────────────
         মালিকের নির্দেশ: "footer e notice cholbe dms er moto"। আগে
         এখানে স্থির ছিল, আর কারণটা এই মন্তব্যেই লেখা ছিল — সেটা ভুল
         ছিল দুইভাবে: সিদ্ধান্তটা মালিকের, আর স্থির বারে দ্বিতীয়
         নোটিশটা কেটে গিয়ে কেউ কোনোদিন দেখত না।

         চলন্ত লেখার আসল আপত্তিগুলো সারানো হয়েছে, এড়ানো হয়নি:
           • মাউস রাখলে থামে — যে সংখ্যা সরে যাচ্ছে সেটা কেউ ক্লিক
             করতে পারে না
           • `motion-safe:` — যিনি চলন্ত জিনিস বন্ধ রেখেছেন তাঁর
             পর্দায় নড়ে না, তালিকাটা স্থিরই থাকে
           • কিছু না থাকলে বারটা চুপ — "সব ঠিক আছে" ঘুরতে থাকলে দুই
             সপ্তাহে মানুষ তাকানো বন্ধ করে দেয়

         দুইটা কপি পাশাপাশি, এক কপির সমান সরে (-৫০%) — এতেই লুপটা
         নির্বিঘ্ন: প্রথমটা যে মুহূর্তে বেরিয়ে যায়, দ্বিতীয়টা ঠিক
         সেখানেই থাকে যেখানে প্রথমটা শুরু হয়েছিল।

         ── কেন প্রতিটা কপি অন্তত পর্দার সমান চওড়া, ৩০ আগস্ট ২০২৬ ──
         নোটিশ যখন একটা বা দুইটা, কপি দুইটা এত ছোট হত যে **দুইটাই
         একসাথে চোখের সামনে থাকত** — অর্থাৎ একই বাক্য পাশাপাশি দুইবার:

           ● ব্যাকআপ ও খাতা একই ডিস্কে…  ● ব্যাকআপ ও খাতা একই ডিস্কে…

         HP ২৫ আগস্ট এটাকে "ফুটারের বার্তা দুইবার" বলে রিপোর্ট করেছেন,
         আর ঠিকই করেছেন: পাঠকের কাছে ওটা লুপের কৌশল নয়, একটা ভুল।

         `min-w-full` দিলে দ্বিতীয় কপিটা শুরুতেই পর্দার বাইরে থাকে,
         আর প্রথমটা বেরিয়ে যেতে যেতে ভেতরে আসে — যেভাবে চলন্ত লেখা
         পড়ার কথা। --}}
    <div class="group relative hidden min-w-0 flex-1 overflow-hidden md:block">
        <div class="inline-flex whitespace-nowrap will-change-transform
                    motion-safe:animate-[abos-ticker_30s_linear_infinite]
                    group-hover:[animation-play-state:paused]">
            @for ($copy = 0; $copy < 2; $copy++)
                <span class="inline-flex min-w-full items-center gap-6 pe-12"
                      @if ($copy === 1) aria-hidden="true" @endif>
                    @foreach ($notices as $notice)
                        @php
                            $tone = match ($notice['tone']) {
                                'danger' => 'text-(--color-danger)',
                                'pending' => 'text-(--color-badge-pending-ink)',
                                default => 'text-(--color-badge-info-ink)',
                            };
                        @endphp

                        {{-- চলন্ত বারে `truncate` নেই — জায়গার সীমা আর
                             নেই, পুরো বাক্যটাই ঘুরে আসে। ওটাই স্থির
                             বারের সবচেয়ে বড় সমস্যা ছিল: দ্বিতীয়
                             নোটিশটা কেটে গিয়ে কেউ কোনোদিন দেখত না। --}}
                        @if ($notice['url'])
                            <a href="{{ $notice['url'] }}"
                               class="inline-flex items-center gap-1.5 {{ $tone }} hover:underline"
                               @if ($copy === 1) tabindex="-1" @endif>
                                <span class="size-1.5 rounded-full bg-current" aria-hidden="true"></span>
                                {{ $notice['text'] }}
                            </a>
                        @else
                            <span class="inline-flex items-center gap-1.5 {{ $tone }}">
                                <span class="size-1.5 rounded-full bg-current" aria-hidden="true"></span>
                                {{ $notice['text'] }}
                            </span>
                        @endif
                    @endforeach
                </span>
            @endfor
        </div>
    </div>

    {{--
        চলাটার নিয়ম — এখানেই, বিশ্বজনীন স্টাইলশিটে নয়।

        গোটা পণ্যে এটাই একমাত্র অ্যানিমেশন। একটা কম্পোনেন্টের জন্য
        বিশ্বজনীন ফাইলে কীফ্রেম রাখলে সেটা কম্পোনেন্টটার চেয়েও বেশি
        দিন বেঁচে থাকে, আর কেউ জানে না কেন আছে।
    --}}
    @once
        <style>
            @keyframes abos-ticker {
                from { transform: translateX(0); }
                to   { transform: translateX(-50%); }
            }
        </style>
    @endonce

    @if ($year)
        <span class="shrink-0">{{ __('core.company.financial_year') }}: {{ $year->name }}</span>
    @endif

    {{-- ব্যবহারকারীর নাম এখানে ছিল, এখন নেই।

         যুক্তিটা ছিল "আমি কে হয়ে বসে আছি" — ডিপোতে একটা কম্পিউটার
         কয়েকজন ভাগ করে ব্যবহার করেন, তাই প্রশ্নটা ঘন ঘন আসে। কিন্তু
         উত্তরটা টপবারের ডান কোণে আগে থেকেই আছে, নামের নিচে ভূমিকাসহ, আর
         পাশে অক্ষরের গোল ছবিটাও। একই কথা দুই জায়গায় লেখা মানে একটা
         জায়গা নষ্ট — কোম্পানি আর শাখাকে ঠিক এই কারণেই এই বার থেকে সরানো
         হয়েছিল (উপরের নোট)। --}}

    <span class="hidden shrink-0 lg:inline">
        © {{ now()->year }} {{ config('app.name', 'ABOS') }}
    </span>

    <span class="shrink-0">v{{ config('app.version', '0.1.0') }}</span>
</footer>
