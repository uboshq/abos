{{--
    ব্যাকআপ — রোজ যা চলছে, তার একটা জানালা।

    ── কেন সবার আগে "শেষটা কবে" ─────────────────────────────────────────
    এই পর্দায় মানুষ আসেন একটাই প্রশ্ন নিয়ে: **আজ রাতে কিছু হারালে কতটা
    ফেরত পাব?** উত্তরটা একটাই সংখ্যা — শেষ ডাম্পটা কখনকার। তাই সেটাই
    সবচেয়ে উপরে, সবচেয়ে বড় হরফে; তালিকাটা তার নিচে।

    ── কেন "ফিরিয়ে আনুন" বোতাম নেই ──────────────────────────────────────
    ফিরিয়ে আনা মানে আজকের সব কাজ মুছে ফেলা। একটা ভুল ক্লিকের দাম গোটা
    দিনের বই, আর পর্দায় ভুল ক্লিক হয়। তাই এখানে থাকে **নির্দেশটা** —
    কমান্ডটা কপি করে নেওয়ার মতো করে লেখা, যাতে দরকারের দিন কেউ মনে
    করার চেষ্টা না করে।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('core.backup.title') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('core.backup.title')"
                          :subtitle="__('core.backup.subtitle')">
            <x-slot:actions>
                <form method="POST" action="{{ route('system_admin.backup.store') }}">
                    @csrf
                    <x-ui.button type="submit" tone="primary">{{ __('core.backup.take_now') }}</x-ui.button>
                </form>
            </x-slot:actions>
        </x-ui.page-header>
    </x-slot:header>

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    @if ($errors->any())
        <div role="alert"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2 text-sm
                    text-(--color-badge-danger-ink)">
            <ul class="list-inside list-disc">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @php
        $newest = $files->first();
        $stale = $newest === null || $newest['at']?->lt(now()->subDays(2));
    @endphp

    <div class="grid gap-4 lg:grid-cols-3">
        {{-- শেষ ব্যাকআপ — একটাই প্রশ্নের একটাই উত্তর --}}
        <section data-boxed
                 class="rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card) p-4 lg:col-span-2">
            <h2 class="text-sm text-(--color-ink-muted)">{{ __('core.backup.newest') }}</h2>

            @if ($newest)
                <p class="mt-1 text-2xl font-semibold">
                    {{ \App\Core\Support\DateFormat::formatWithTime($newest['at']) }}
                </p>
                <p class="mt-0.5 font-mono text-2xs text-(--color-ink-muted)">{{ $newest['name'] }}</p>
            @else
                <p class="mt-1 text-2xl font-semibold text-(--color-badge-danger-ink)">
                    {{ __('core.backup.none_yet') }}
                </p>
            @endif

            @if ($stale)
                <p role="alert"
                   class="mt-3 rounded-(--radius-field) bg-(--color-badge-danger-bg) px-3 py-2 text-sm
                          text-(--color-badge-danger-ink)">
                    {{ __('core.notice.backup_stale') }}
                </p>
            @endif
        </section>

        {{--
            দ্বিতীয় গন্তব্য — আলাদা কার্ডে, কারণ এটা আলাদা প্রশ্ন।

            "ব্যাকআপ আছে" আর "ব্যাকআপ অন্য কোথাও আছে" এক কথা নয়। একই
            ডিস্কে থাকা কপি ডিস্ক নষ্ট হলে সাথেই যায় — অর্থাৎ ঠিক যে
            একটা ক্ষেত্রে ওটা সবচেয়ে বেশি দরকার, সেখানেই নেই।
        --}}
        <section data-boxed
                 class="rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card) p-4">
            <h2 class="text-sm text-(--color-ink-muted)">{{ __('core.backup.mirror') }}</h2>

            @if ($mirrorPath === null)
                <p class="mt-1 font-semibold text-(--color-badge-danger-ink)">
                    {{ __('core.backup.no_mirror') }}
                </p>
                <p class="mt-2 text-sm text-(--color-ink-muted)">
                    {{ __('core.backup.no_mirror_how') }}
                </p>
            @else
                <p class="mt-1 font-semibold">
                    {{ $mirroredAt ? \App\Core\Support\DateFormat::formatWithTime($mirroredAt) : __('core.backup.never_mirrored') }}
                </p>
                <p class="mt-0.5 font-mono text-2xs break-all text-(--color-ink-muted)">{{ $mirrorPath }}</p>
            @endif
        </section>
    </div>

    {{-- কীভাবে চলে — সংখ্যাগুলো config থেকে, লেখা থেকে নয় --}}
    <section data-boxed
             class="mt-4 rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card) p-4">
        <h2 class="mb-2 font-semibold">{{ __('core.backup.how_it_runs') }}</h2>

        <dl class="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-3">
            <div>
                <dt class="text-2xs text-(--color-ink-muted)">{{ __('core.backup.every_night_at') }}</dt>
                <dd class="font-medium">{{ $dailyAt }}</dd>
            </div>
            <div>
                <dt class="text-2xs text-(--color-ink-muted)">{{ __('core.backup.kept_for') }}</dt>
                <dd class="font-medium">{{ trans_choice('core.backup.days', $keepDays, ['count' => $keepDays]) }}</dd>
            </div>
            <div>
                <dt class="text-2xs text-(--color-ink-muted)">{{ __('core.backup.folder') }}</dt>
                <dd class="font-mono text-2xs break-all">{{ $directory }}</dd>
            </div>
        </dl>

        {{--
            ফিরিয়ে আনার নির্দেশ — বোতাম নয়, লেখা।

            দরকারের দিন কেউ যেন মনে করার চেষ্টা না করে। কমান্ডটা হুবহু
            লেখা, নামটা সহ, যাতে কপি করে বসিয়ে দেওয়া যায়।
        --}}
        <h3 class="mt-4 font-semibold">{{ __('core.backup.restore') }}</h3>
        <p class="mt-1 max-w-(--spacing-prose-max) text-sm text-(--color-ink-muted)">
            {{ __('core.backup.restore_note') }}
        </p>
        <pre class="mt-2 overflow-x-auto rounded-(--radius-field) bg-(--color-surface-sunken)
                    px-3 py-2 font-mono text-2xs">php artisan abos:restore {{ $newest['name'] ?? 'abos-YYYY-MM-DD-HHMMSS.sql.gz' }}</pre>
    </section>

    <section data-boxed
             class="mt-4 overflow-hidden rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card)">
        <h2 class="border-b border-(--color-border) bg-(--color-section-head) px-4 py-3 font-semibold">
            {{ __('core.backup.all_of_them') }}
        </h2>

        @forelse ($files as $file)
            <div class="flex items-center justify-between gap-3 border-b border-(--color-border) px-4 py-2 last:border-0">
                <span class="min-w-0 truncate font-mono text-sm">{{ $file['name'] }}</span>

                <span class="shrink-0 text-sm tabular-nums text-(--color-ink-muted)">
                    {{ $file['bytes'] >= 1048576
                        ? round($file['bytes'] / 1048576, 1) . ' MB'
                        : max(1, (int) round($file['bytes'] / 1024)) . ' KB' }}
                </span>
            </div>
        @empty
            <x-ui.empty-state :message="__('core.backup.none_yet')" />
        @endforelse
    </section>
</x-layouts.app>
