{{--
    Tiles — SAP Fiori "Horizon"-এর নিজের খোলস (মালিক, ১৯ নভেম্বর ২০২৬: *"একদম 100% হুবহু কপি"*)।

    ── শেল বার, আসলটার মতো ─────────────────────────────────────────────
    আসল Horizon-এর শেল বার সাদা, ৫২px: বাঁয়ে লোগো, তার পাশে মোটা ১৪px-এ চলতি অ্যাপের নাম আর ⌄; মাঝে ধূসর পিলের খোঁজা;
    ডানে খালি আইকন আর গোল আদ্যক্ষর। পুরোনো Fiori 3-এর হ্যামবার্গার আর ১০.৫px উপশিরোনাম Horizon-এ নেই, তাই উঠে গেছে।
    ⓘ লোগো ABOS-এর — SAP-এর নাম বা লোগো কোথাও নয়। বাকি সব (খোঁজা, তৈরি করুন, অনুমোদন, ঘণ্টা, প্রোফাইল) শেয়ার করা
      টপবারের নিজের — এখানে কেবল যা Horizon-এর নিজের, আর গড়ন [[resources/css/fiori-look.css]]-এ, কেবল `[data-ui='tiles']`-এ।

    ── লঞ্চপ্যাডের টালি কেন এখানে নেই ───────────────────────────────────
    ধাপের টালি একটাই কম্পোনেন্ট (`x-ui.stage-strip`); প্রতিটা রূপ কেবল তার চেহারা বদলায়।
--}}

@if ($region === 'topbar-start')
    @php
        $route = (string) (request()->route()?->getName() ?? '');
        $current = collect($menu)->first(
            fn ($m) => collect($m['groups'])->flatten(1)->contains('active', true),
        ) ?? collect($menu)->first(
            fn ($m) => collect($m['codes'])->contains(fn ($c) => str_starts_with($route, $c.'.')),
        );

        /*
         * ⭐ শেল বারের ফেরার তীর — আসল Horizon-এর মতো লোগোর বাঁয়ে, হোম ছাড়া সব পাতায় (২০ নভেম্বর ২০২৬: breadcrumb-পটি উঠে গেছে,
         *   তাই ফেরার পথ এটাই)। ⓘ গন্তব্য আগের পাতা (একই সাইটের, এই পাতা নয়); না থাকলে চলতি স্পেসের পাতা, তাও না থাকলে হোম।
         * ⓘ লিংক, স্ক্রিপ্ট নয় — Alpine-এর CSP গড়নে `history.back()` চলে না, আর লিংক কীবোর্ডেও চলে।
         */
        $backHref = null;
        if ($route !== 'dashboard') {
            $previous = url()->previous();
            $sameSite = str_starts_with($previous, rtrim(url('/'), '/').'/') || $previous === url('/');
            $backHref = ($sameSite && $previous !== url()->current())
                ? $previous
                : ($current ? \App\Core\Support\FioriLaunchpad::spaceUrl($current['code']) : route('dashboard'));
        }
    @endphp

    @if ($backHref)
        <a href="{{ $backHref }}" data-fiori-back
           class="hidden size-9 shrink-0 place-items-center rounded-[8px] text-(--color-topbar-ink-muted) sm:grid"
           title="{{ __('core.fiori.back') }}" aria-label="{{ __('core.fiori.back') }}">
            <svg viewBox="0 0 16 16" class="size-4" aria-hidden="true">
                <path d="M10 3L5 8l5 5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        </a>
    @endif

    <a href="{{ route('dashboard') }}" data-shell-bar data-fiori-shell
       class="hidden shrink-0 items-center gap-3 rounded-[8px] pe-2 text-(--color-topbar-ink) sm:flex">
        <img src="{{ asset('brand/abos-logo-lockup-transparent.png') }}" alt="abos" data-fiori-logo
             class="h-7 w-auto shrink-0">
        <span class="flex min-w-0 items-center gap-1 text-sm font-bold leading-none" data-fiori-title>
            <span class="truncate">{{ $current['label'] ?? __('core.menu.dashboard') }}</span>
            <svg viewBox="0 0 16 16" class="size-3 shrink-0" aria-hidden="true">
                <path d="M3 6l5 5 5-5" fill="none" stroke="currentColor" stroke-width="1.6"/>
            </svg>
        </span>
    </a>
@endif
