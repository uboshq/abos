{{--
    ⭐ বন্ধ-না-হওয়া দিন আর মাসের মাঝখানের সতর্কতা — মালিক, ১৮ নভেম্বর ২০২৬:
    *"পরের দিন সকালবেলায় যখন সফটওয়্যারে লগইন করবে … এই চেকলিস্টে সামনে চলে আসবে … পপআপ আকারে সামনে চলে আসবে …
    কেটে দিতে পারবে … এক ঘন্টা পর পর আবার আসবে"*, আর *"কাজ যাতে কোনোভাবে আটকে না থাকে"*।

    ⓘ কে দেখেন: যাঁর দিন বন্ধের চাবি আছে (`accounts.day_close.close`; মালিক সব চাবি পান)।
    ⓘ কী দেখায়: গত সাত দিনের যেসব কর্মদিবসে খাতায় লেখা হয়েছে অথচ দিন বন্ধ হয়নি ([[DayChecklist::openDays()]]),
      গত মাস বন্ধ না হলে সেটা ([[DayChecklist::openMonth()]]), আর সবচেয়ে নতুন খোলা দিনের চেকলিস্ট — প্রতিটা সারি লিংক।
    ⓘ ✕ বা "পরে মনে করাবেন" — এই লগইনে নিয়ন্ত্রণ প্যানেলের সময় পর্যন্ত লুকায় ([[DayClosingController::snooze()]]); তারপর আবার আসে।
    ⛔ পর্দা ঢাকে না: পেছনের পাতা চলতেই থাকে (কোনো আটকানো পর্দা নেই), দিন শেষের হিসাবের পাতায় নিজে আসে না, ছাপায় আসে না।
    ⓘ জাভাস্ক্রিপ্ট ছাড়া — নোটিশের ফিতার মতো ছোট ফর্ম।
--}}
@php
    $show = false;
    $user = auth()->user();
    $settings = app(\App\Core\Services\SettingsService::class);

    if ($user !== null
        && \Illuminate\Support\Facades\Route::has('executive.dayclose.snooze')
        && (string) ($settings->get('accounts.day_close.popup_every') ?? '60') !== '0'
        && ! request()->routeIs('executive.dayclose', 'executive.dayclose.*', 'executive.month_close')
        && (int) session('daycheck.snooze_until', 0) <= now()->getTimestamp()
        && $user->can('accounts.day_close.close')) {
        $check = app(\App\Modules\Accounts\Services\DayChecklist::class);
        $today = now()->startOfDay();
        $days = $check->openDays($today);
        $month = $check->openMonth($today);
        $show = $days !== [] || $month !== null;
        $latest = $days !== [] ? end($days) : null;
        $items = $latest !== null ? $check->for($latest) : [];
    }
@endphp

@if ($show)
    <div class="pointer-events-none fixed inset-x-0 top-[18vh] z-40 flex justify-center px-4 print-hide" data-day-popup>
        <section role="dialog" aria-labelledby="day-popup-title"
                 class="pointer-events-auto w-full max-w-lg rounded-(--radius-card) border border-(--color-warning) bg-(--color-surface-card) shadow-xl">
            <header class="flex items-center gap-2 border-b border-(--color-border) bg-(--color-badge-warning-bg) px-4 py-2">
                <x-ui.icon name="alert-triangle" :size="18" class="shrink-0 text-(--color-warning-hover)" />
                <h2 id="day-popup-title" class="min-w-0 flex-1 font-semibold">{{ __('accounts::dayclose.popup.title') }}</h2>
                <form method="POST" action="{{ route('executive.dayclose.snooze') }}">
                    @csrf
                    <button type="submit" data-day-popup-x aria-label="{{ __('accounts::dayclose.popup.later') }}"
                            class="rounded p-1 text-(--color-ink-muted) hover:bg-(--color-surface-muted) hover:text-(--color-ink)">
                        <x-ui.icon name="x" :size="18" />
                    </button>
                </form>
            </header>

            <div class="grid gap-3 px-4 py-3 text-sm">
                @if ($days !== [])
                    <div>
                        <p class="mb-1">{{ __('accounts::dayclose.popup.days') }}</p>
                        <p class="flex flex-wrap gap-2" data-open-days>
                            @foreach ($days as $d)
                                <a href="{{ route('executive.dayclose', ['date' => $d->toDateString()]) }}"
                                   class="rounded-(--radius-pill) border border-(--color-border) px-2 py-0.5 font-medium text-(--color-primary) hover:bg-(--color-surface-muted)">{{ $d->format('d/m/Y') }}</a>
                            @endforeach
                        </p>
                    </div>
                @endif

                @if ($month !== null)
                    <p class="flex flex-wrap items-center gap-2" data-open-month>
                        <x-ui.icon name="calendar" :size="16" class="text-(--color-warning-hover)" />
                        <span class="flex-1">{{ __('accounts::dayclose.popup.month', ['month' => $month->copy()->locale(app()->getLocale())->translatedFormat('F Y')]) }}</span>
                        @if (\Illuminate\Support\Facades\Route::has('executive.month_close') && $user->can('accounts.report.month_close'))
                            <a href="{{ route('executive.month_close', ['month' => $month->format('Y-m')]) }}" class="font-medium text-(--color-primary) hover:underline">{{ __('accounts::dayclose.popup.month_go') }} →</a>
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
                <form method="POST" action="{{ route('executive.dayclose.snooze') }}">
                    @csrf
                    <x-ui.button type="submit" tone="secondary">{{ __('accounts::dayclose.popup.later') }}</x-ui.button>
                </form>
                @if ($latest !== null)
                    <x-ui.button :href="route('executive.dayclose', ['date' => $latest->toDateString()])" tone="primary">{{ __('accounts::dayclose.popup.close_day') }}</x-ui.button>
                @endif
            </footer>
        </section>
    </div>
@endif
