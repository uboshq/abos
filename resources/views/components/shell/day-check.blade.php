{{--
    ⭐ খোলার সময়ের মাঝখানের পপআপ — জরুরি খবর আর বন্ধ-না-হওয়া দিন/মাস।

    মালিক, ১৮ নভেম্বর ২০২৬: *"পরের দিন সকালবেলায় যখন সফটওয়্যারে লগইন করবে … পপআপ আকারে সামনে চলে আসবে … কেটে দিতে পারবে …
    এক ঘন্টা পর পর আবার আসবে"*, আর *"কাজ যাতে কোনোভাবে আটকে না থাকে"*।
    মালিক, ১৮ নভেম্বর রাত ও ১৯ নভেম্বর ২০২৬: *"ব্যাকআপের ফিতা আর কোপাইলটের ফিতা … এখানে না দেখিয়ে … পপ আপে নোটিফিকেশনটা দেখানো
    আরও গুরুত্বপূর্ণ … সারাদিন এইখানে বসে থাকাটা জায়গাটাও আটকে রাখে"*, *"পপআপে যা দেখাবে ঠিক না হওয়ার আগ পর্যন্ত পপআপ কাটার পরে
    নিচের নোটিশ বারে অল টাইম চলতেই থাকবে"*।

    ⓘ দুই ভাগ:
      ১ · জরুরি খবর — আগে পাতার উপরের ফিতায় যা থাকত ([[shell.notice-banner]], [[StatusNotices::forStrip()]]): ব্যাকআপ, খসড়া, অনুমোদন,
          লেখকের "জরুরি" নোটিশ। সবার জন্য, যার যা দেখার অধিকার। প্রতিটার ✕ আগের মতোই (এই লগইনে সেটা আর আসে না)।
          ⓘ নিচের চলন্ত বারে এগুলো আগে থেকেই চলে, ঠিক না হওয়া পর্যন্ত ([[StatusNotices::forTicker()]], মালিক ৭ নভেম্বর)।
      ২ · দিন আর মাস বন্ধ হয়নি — যাঁর দিন বন্ধের চাবি আছে (`accounts.day_close.close`): গত সাত দিনের বন্ধ-না-হওয়া কর্মদিবস
          ([[DayChecklist::openDays()]]), গত মাস ([[DayChecklist::openMonth()]]), আর সবচেয়ে নতুন খোলা দিনের চেকলিস্ট।
          নিয়ন্ত্রণ প্যানেলে "দেখাবে না" বাছলে কেবল এই ভাগটা চুপ।
    ⓘ "পরে মনে করাবেন" — এই লগইনে নিয়ন্ত্রণ প্যানেলের সময় পর্যন্ত (ডিফল্ট ১ ঘণ্টা) পুরো পপআপ লুকায়; তারপর যা তখনো বাকি তা নিয়ে আবার আসে
      ([[NotificationController::closeBanner()]] `later`, সবার জন্য খোলা — দিন বন্ধের চাবি ছাড়াও)।
    ⛔ পর্দা ঢাকে না: পেছনের পাতা চলতেই থাকে, দিন/মাস শেষের হিসাবের পাতায় নিজে আসে না, ছাপায় আসে না। জাভাস্ক্রিপ্ট ছাড়া।
--}}
@php
    $show = false;
    $user = auth()->user();
    $days = [];
    $month = null;
    $latest = null;
    $items = [];
    $hasNews = false;

    if ($user !== null
        && \Illuminate\Support\Facades\Route::has('notifications.banner.close')
        && ! request()->routeIs('executive.dayclose', 'executive.dayclose.*', 'executive.month_close')
        && (int) session('daycheck.snooze_until', 0) <= now()->getTimestamp()) {
        // ── ১ · জরুরি খবর (আগের উপরের ফিতা) — এই লগইনে ✕ দেওয়াগুলো বাদ, ফিতার একই নিয়ম ──
        $closed = (array) session('shell.banner.closed', []);
        $hasNews = array_filter(
            app(\App\Core\Services\StatusNotices::class)->forStrip(),
            fn (array $n): bool => ! in_array(sha1($n['text']), $closed, true),
        ) !== [];

        // ── ২ · দিন আর মাস বন্ধ হয়নি — দিন বন্ধের চাবি যাঁর ──
        // ⓘ "দেখাবে না" = `off` (আগের জমা `0`-ও) — [[DayChecklist::popupEvery()]]
        if ($user->can('accounts.day_close.close') && \App\Modules\Accounts\Services\DayChecklist::popupEvery() !== null) {
            $check = app(\App\Modules\Accounts\Services\DayChecklist::class);
            $today = now()->startOfDay();
            $days = $check->openDays($today);
            $month = $check->openMonth($today);
            $latest = $days !== [] ? end($days) : null;
            $items = $latest !== null ? $check->for($latest) : [];
        }

        $show = $hasNews || $days !== [] || $month !== null;
    }
@endphp

@if ($show)
    <div class="pointer-events-none fixed inset-x-0 top-[14vh] z-40 flex justify-center px-4 print-hide" data-day-popup>
        <section role="dialog" aria-labelledby="day-popup-title"
                 class="pointer-events-auto w-full max-w-xl overflow-hidden rounded-(--radius-card) border border-(--color-warning) bg-(--color-surface-card) shadow-xl">
            <header class="flex items-center gap-2 border-b border-(--color-border) bg-(--color-badge-warning-bg) px-4 py-2">
                <x-ui.icon name="alert-triangle" :size="18" class="shrink-0 text-(--color-warning-hover)" />
                <h2 id="day-popup-title" class="min-w-0 flex-1 font-semibold">{{ __($hasNews ? 'accounts::dayclose.popup.title_news' : 'accounts::dayclose.popup.title') }}</h2>
                <form method="POST" action="{{ route('notifications.banner.close') }}">
                    @csrf
                    <input type="hidden" name="later" value="1">
                    <button type="submit" data-day-popup-x aria-label="{{ __('accounts::dayclose.popup.later') }}"
                            class="rounded p-1 text-(--color-ink-muted) hover:bg-(--color-surface-muted) hover:text-(--color-ink)">
                        <x-ui.icon name="x" :size="18" />
                    </button>
                </form>
            </header>

            {{-- ১ · জরুরি খবর — আগের উপরের ফিতার সারিগুলো, প্রতিটার নিজের ✕ আর "বিস্তারিত" সহ --}}
            @if ($hasNews)
                <div data-popup-news>
                    <x-shell.notice-banner />
                </div>
            @endif

            <div class="grid gap-3 px-4 py-3 text-sm">
                {{-- ⓘ ভাগের শিরোনাম কেবল জরুরি খবরও থাকলে — নইলে মাথার শিরোনামই "দিন বন্ধ হয়নি", দুবার নয় (লাইভে দেখা, ১৯ নভেম্বর ২০২৬) --}}
                @if ($hasNews && ($days !== [] || $month !== null))
                    <p class="font-semibold" data-popup-day-head>{{ __('accounts::dayclose.popup.title') }}</p>
                @endif

                @if ($days !== [])
                    <div>
                        <p class="mb-1">{{ __('accounts::dayclose.popup.days') }}</p>
                        <p class="flex flex-wrap gap-2" data-open-days>
                            @foreach ($days as $d)
                                <a href="{{ route('executive.dayclose', ['date' => $d->toDateString()]) }}"
                                   class="rounded-(--radius-pill) border border-(--color-border) px-2 py-0.5 font-medium text-(--color-link) hover:bg-(--color-surface-muted)">{{ $d->format('d/m/Y') }}</a>
                            @endforeach
                        </p>
                    </div>
                @endif

                @if ($month !== null)
                    <p class="flex flex-wrap items-center gap-2" data-open-month>
                        <x-ui.icon name="calendar" :size="16" class="text-(--color-warning-hover)" />
                        <span class="flex-1">{{ __('accounts::dayclose.popup.month', ['month' => $month->copy()->locale(app()->getLocale())->translatedFormat('F Y')]) }}</span>
                        @if (\Illuminate\Support\Facades\Route::has('executive.month_close') && $user->can('accounts.report.month_close'))
                            <a href="{{ route('executive.month_close', ['month' => $month->format('Y-m')]) }}" class="font-medium text-(--color-link) hover:underline">{{ __('accounts::dayclose.popup.month_go') }} →</a>
                        @endif
                    </p>
                @endif

                @if ($latest !== null)
                    <div class="rounded border border-(--color-border) px-3 py-2">
                        <p class="mb-1 font-semibold">{{ __('accounts::dayclose.popup.latest', ['date' => $latest->format('d/m/Y')]) }}</p>
                        @include('accounts::partials.day-checklist', ['items' => $items])
                    </div>
                @endif

                <p class="text-xs text-(--color-ink-muted)">{{ __('accounts::dayclose.popup.never_blocks') }}</p>
            </div>

            <footer class="flex flex-wrap items-center justify-end gap-2 border-t border-(--color-border) px-4 py-2">
                <form method="POST" action="{{ route('notifications.banner.close') }}">
                    @csrf
                    <input type="hidden" name="later" value="1">
                    <x-ui.button type="submit" tone="secondary">{{ __('accounts::dayclose.popup.later') }}</x-ui.button>
                </form>
                @if ($latest !== null)
                    <x-ui.button :href="route('executive.dayclose', ['date' => $latest->toDateString()])" tone="primary">{{ __('accounts::dayclose.popup.close_day') }}</x-ui.button>
                @endif
            </footer>
        </section>
    </div>
@endif
