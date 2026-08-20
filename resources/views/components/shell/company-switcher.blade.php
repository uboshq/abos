@props(['company', 'branch' => null])

{{--
    কোম্পানি ও শাখা সুইচার — সেকশন ১৫.১৫।

    Phase 1-এই, পেছানো হয়নি। DMS-এ এটা পরে বসাতে গিয়ে দুইটা আলাদা ফিক্স
    লেগেছিল: প্রথমে সুইচ কাজই করত না, তারপর পাতা রিলোড করলে মুছে যেত।

    পছন্দটা ডাটাবেজে লেখা হয়, সেশনে নয় — তাই রিলোডেও টেকে, অন্য ডিভাইসেও।

    ── কেন শাখাটাও এখানে ─────────────────────────────────────────────────
    আগে এখানে কেবল কোম্পানি বদলানো যেত, আর শাখাটা নিচে ছোট করে লেখা থাকত —
    দেখা যেত, বদলানো যেত না। অথচ দিনের মধ্যে শাখা বদলায় বেশি: গুদাম থেকে
    কাউন্টার, কাউন্টার থেকে অফিস। বদলানোর জায়গা না থাকায় মানুষ ভুল শাখায়
    বসে এন্ট্রি দিয়ে ফেলতেন, আর ভুলটা ধরা পড়ত মাস শেষে যখন এক শাখার বিক্রি
    অন্য শাখায় দেখাত।

    ── কেন নিচে ওই লাইনটা লেখা ───────────────────────────────────────────
    "আপনি যা লিখছেন তা এই শাখার নামেই বসছে" — কারণ এটাই সুইচারের আসল
    পরিণতি, আর সেটা কোথাও লেখা না থাকলে কেউ জানত না। মেনুটা খোলার সময়ই
    কথাটা চোখে পড়া দরকার, বদলে ফেলার পরে নয়।
--}}
@php
    $user = auth()->user();

    $companies = $user?->companies()->orderBy('name_en')->get() ?? collect();

    // শাখাগুলো চলতি কোম্পানির — গ্লোবাল স্কোপই সেটা করে দেয়
    $branches = \App\Models\Branch::query()->active()->orderBy('name_en')->get();

    // একটাই কোম্পানি আর একটাই শাখা হলে বদলানোর কিছু নেই, তাই মেনুও নেই
    $canSwitch = $companies->count() > 1 || $branches->count() > 1;
@endphp

<div x-data="{ open: false }" class="relative">
    <button type="button"
            @click="open = !open"
            @keydown.escape.window="open = false"
            :aria-expanded="open"
            aria-haspopup="menu"
            aria-label="{{ __('core.company.switch') }}"
            @class([
                'flex min-h-(--spacing-touch) items-center gap-2 rounded-(--radius-field) px-2 transition-colors',
                'hover:bg-(--color-surface-hover)' => $canSwitch,
                'cursor-default' => ! $canSwitch,
            ])
            @if (! $canSwitch) disabled @endif>

        {{-- গ্রাহকের নিজের লোগো — ABOS-এর নয়। প্রোডাক্টের মার্ক সাইডবারের
             মাথায়; এখানে ব্যবহারকারী দেখে সে কোন প্রতিষ্ঠানের হয়ে কাজ
             করছে, আর নামের পাশে লোগোটা সেটা এক নজরে বলে।

             ঘরটা ৪০px — টপবার ৬৪px, পাশের নাম+শাখা ব্লকও প্রায় ততটাই।
             max-w ১২৮px, ৬৪px নয়: চওড়া লকআপ (৬:১ বা তার বেশি) সরু ঘরে
             উচ্চতা পায় না, তখন লেখাটা দাগ হয়ে যায়। --}}
        @if ($logo = $company->logoUrl())
            <img src="{{ $logo }}" alt=""
                 class="h-10 w-auto max-w-32 shrink-0 object-contain" aria-hidden="true">
        @endif

        <span class="min-w-0 text-start">
            <span class="block max-w-40 truncate text-sm font-semibold text-(--color-ink)">
                {{ $company->name() }}
            </span>

            {{-- শাখাটা পিল-ব্যাজে, সাধারণ লেখায় নয়।

                 নামের নিচে ধূসর এক লাইন হলে ওটা ঠিকানার মতো দেখাত — পড়ার
                 জিনিস, বদলানোর নয়। ব্যাজটা বলে এটা একটা অবস্থা, আর অবস্থা
                 বদলানো যায়। --}}
            @if ($branch)
                <span class="mt-0.5 inline-block max-w-40 truncate rounded-full
                             bg-(--color-brand-50) px-2 py-0.5 text-2xs font-medium
                             text-(--color-brand-700)">
                    {{ $branch->name() }}
                </span>
            @endif
        </span>

        @if ($canSwitch)
            {{-- দুই দিকের তীর, নিচের ক্যারেট নয়: ক্যারেট বলে "আরও আছে",
                 এটা বলে "বদলানো যায়" — আর কাজটা বদলানোই। --}}
            <svg viewBox="0 0 24 24" class="size-4 shrink-0 fill-(--color-ink-muted)" aria-hidden="true">
                <path d="M7 7h10.2l-2.6-2.6L16 3l5 5-5 5-1.4-1.4 2.6-2.6H7V7Zm10 10H6.8l2.6 2.6L8 21l-5-5 5-5 1.4 1.4L6.8 15H17v2Z"/>
            </svg>
        @endif
    </button>

    @if ($canSwitch)
        <div x-show="open"
             x-cloak
             @click.outside="open = false"
             role="menu"
             class="pops-onto-page absolute start-0 top-full z-40 mt-1 w-72 overflow-hidden rounded-(--radius-card) border
                    border-(--color-border) bg-(--color-surface-card) py-1 shadow-(--shadow-overlay)">

            {{-- ── কোম্পানি ── --}}
            @if ($companies->count() > 1)
                <p class="px-3 pb-1 pt-2 text-2xs font-semibold uppercase tracking-wide text-(--color-ink-muted)">
                    {{ __('core.company.company') }}
                </p>

                @foreach ($companies as $option)
                    <form method="POST" action="{{ route('company.switch') }}">
                        @csrf
                        <input type="hidden" name="company_id" value="{{ $option->id }}">

                        {{-- চলতি কোম্পানিটাও তালিকায় থাকে, টিক সহ।

                             বাদ দিলে মানুষ দেখত কেবল "অন্যগুলো", আর কোনটায়
                             আছে তা মেলাতে হত উপরের নামের সাথে। তালিকায়
                             নিজেকে দেখতে পাওয়াটাই উত্তরটা দিয়ে দেয়। --}}
                        <button type="submit" role="menuitem"
                                @class([
                                    'flex min-h-(--spacing-touch) w-full items-center gap-2 px-3 text-start text-sm',
                                    'transition-colors hover:bg-(--color-surface-hover)',
                                    'font-semibold' => $option->id === $company->id,
                                ])>
                            @if ($optionLogo = $option->logoUrl())
                                <img src="{{ $optionLogo }}" alt=""
                                     class="h-6 w-auto max-w-16 shrink-0 object-contain" aria-hidden="true">
                            @endif

                            <span class="min-w-0 flex-1 truncate">{{ $option->name() }}</span>

                            @if ($option->id === $company->id)
                                <svg viewBox="0 0 24 24" class="size-4 shrink-0 fill-(--color-brand-500)"
                                     aria-hidden="true">
                                    <path d="m9 16.2-3.5-3.5-1.4 1.4L9 19 20 8l-1.4-1.4L9 16.2Z"/>
                                </svg>
                            @endif
                        </button>
                    </form>
                @endforeach
            @endif

            {{-- ── শাখা ── --}}
            @if ($branches->count() > 1)
                <p class="border-t border-(--color-border) px-3 pb-1 pt-2 text-2xs font-semibold
                          uppercase tracking-wide text-(--color-ink-muted)">
                    {{ __('core.company.branch_of', ['company' => $company->name()]) }}
                </p>

                @foreach ($branches as $option)
                    <form method="POST" action="{{ route('branch.switch') }}">
                        @csrf
                        <input type="hidden" name="branch_id" value="{{ $option->id }}">

                        <button type="submit" role="menuitem"
                                @class([
                                    'flex min-h-(--spacing-touch) w-full items-center gap-2 px-3 text-start text-sm',
                                    'transition-colors hover:bg-(--color-surface-hover)',
                                    'font-semibold' => $option->id === $branch?->id,
                                ])>
                            {{-- ছোট দাগটা কেবল সাজ নয়: লোগোর জায়গাটা ধরে
                                 রাখে, তাই কোম্পানি আর শাখার সারিগুলো এক
                                 রেখায় বসে। --}}
                            <span @class([
                                'h-4 w-1 shrink-0 rounded-full',
                                'bg-(--color-brand-500)' => $option->id === $branch?->id,
                                'bg-(--color-border)' => $option->id !== $branch?->id,
                            ])></span>

                            <span class="min-w-0 flex-1 truncate">{{ $option->name() }}</span>

                            @if ($option->id === $branch?->id)
                                <svg viewBox="0 0 24 24" class="size-4 shrink-0 fill-(--color-brand-500)"
                                     aria-hidden="true">
                                    <path d="m9 16.2-3.5-3.5-1.4 1.4L9 19 20 8l-1.4-1.4L9 16.2Z"/>
                                </svg>
                            @endif
                        </button>
                    </form>
                @endforeach
            @endif

            <p class="border-t border-(--color-border) px-3 py-2 text-2xs leading-relaxed text-(--color-ink-muted)">
                {{ __('core.company.stamped_with_branch') }}
            </p>
        </div>
    @endif
</div>
