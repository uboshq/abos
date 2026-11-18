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
    @endphp

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
