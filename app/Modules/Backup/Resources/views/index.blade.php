{{--
    ব্যাকআপ — রোজ যা চলছে, তার একটা জানালা।

    ── কেন সবার আগে "শেষটা কবে" ─────────────────────────────────────────
    এই পর্দায় মানুষ আসেন একটাই প্রশ্ন নিয়ে: **আজ রাতে কিছু হারালে কতটা
    ফেরত পাব?** উত্তরটা একটাই সংখ্যা — শেষ ডাম্পটা কখনকার। তাই সেটাই
    সবচেয়ে উপরে, সবচেয়ে বড় হরফে।

    ── কেন দ্বিতীয় প্রশ্নটা "কোথায় আছে" ─────────────────────────────────
    ⚠️ "ব্যাকআপ আছে" আর "ব্যাকআপ অন্য কোথাও আছে" এক কথা নয়। আজ লাইভে
    ৭৩টা ব্যাকআপ, **সবগুলো একই ডিস্কে** — অর্থাৎ ঠিক যে একটা ক্ষেত্রে
    ব্যাকআপ সবচেয়ে বেশি দরকার (মেশিনটা গেল), সেখানেই কিছু নেই।

    তাই গন্তব্যের কার্ডটা সমান গুরুত্বে, পাশেই।

    ── কেন "ফিরিয়ে আনুন" বোতাম নেই ──────────────────────────────────────
    ফিরিয়ে আনা মানে আজকের সব কাজ মুছে ফেলা। একটা ভুল ক্লিকের দাম গোটা
    দিনের বই, আর পর্দায় ভুল ক্লিক হয়। তাই এখানে থাকে **নির্দেশটা** —
    কমান্ডটা কপি করার মতো করে লেখা, যাতে দরকারের দিন কেউ মনে করার
    চেষ্টা না করে।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('core.backup.title') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('core.backup.title')"
                          :subtitle="__('core.backup.subtitle')">
            <x-slot:actions>
                @can('backup.run')
                    <form method="POST" action="{{ route('backup.store') }}">
                        @csrf
                        <x-ui.button type="submit" tone="primary">{{ __('core.backup.take_now') }}</x-ui.button>
                    </form>
                @endcan
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
            কোথায় কপি আছে — আর এটাই আজকের আসল ফাঁক।

            ⚠️ গন্তব্য শূন্য মানে ৩-২-১ নিয়মের একটা শর্তও পূরণ হয়নি।
            লাল লেখাটা তাই সাজসজ্জা নয়, একটা মাপা তথ্য।
        --}}
        <section data-boxed
                 class="rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card) p-4">
            <h2 class="text-sm text-(--color-ink-muted)">{{ __('backup::menu.destinations') }}</h2>

            @if ($destinations->isEmpty())
                <p class="mt-1 font-semibold text-(--color-badge-danger-ink)">
                    {{ __('backup::message.no_destination') }}
                </p>
                <p class="mt-2 text-sm text-(--color-ink-muted)">
                    {{ __('backup::message.no_destination_why') }}
                </p>

                @can('backup.configure')
                    <a href="{{ route('backup.destination.index') }}"
                       class="mt-3 inline-block text-sm underline decoration-dotted underline-offset-2">
                        {{ __('backup::menu.destinations') }} →
                    </a>
                @endcan
            @else
                <ul class="mt-2 space-y-1.5 text-sm">
                    @foreach ($destinations as $destination)
                        @php $days = $destination->daysSinceLastCopy(); @endphp
                        <li class="flex items-center justify-between gap-2">
                            <span class="min-w-0 truncate">{{ $destination->name }}</span>

                            {{-- ⚠️ "পাওয়া যাচ্ছে না" নিজে থেকে ভুল নয় — পেনড্রাইভ
                                 খুলে রাখাই তো উদ্দেশ্য। ভুল হলো কতদিন ধরে কপি
                                 যায়নি, তাই দিনের সংখ্যাটাই দেখানো হয়। --}}
                            <span @class([
                                'num shrink-0 text-2xs',
                                'text-(--color-badge-danger-ink)' => $days === null || $days > 7,
                                'text-(--color-ink-muted)' => $days !== null && $days <= 7,
                            ])>
                                {{ $days === null
                                    ? __('core.backup.never_mirrored')
                                    : __('core.backup.days_ago', ['days' => $days]) }}
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    </div>

    {{-- ফাইলগুলো --}}
    <section data-boxed
             class="mt-4 rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card) p-4">
        <h2 class="mb-2 font-semibold">{{ __('backup::menu.backups') }}</h2>

        @if ($files->isEmpty())
            <p class="text-sm text-(--color-ink-muted)">{{ __('backup::message.nothing_yet') }}</p>
        @else
            @can('backup.download')
                {{-- ⚠️ যিনি নামাতে পারেন, তিনি যেন জানেন কী নামাচ্ছেন --}}
                <p class="mb-2 text-2xs text-(--color-ink-muted)">
                    {{ __('backup::message.download_warning') }}
                </p>
            @endcan

            <div class="overflow-x-auto">
                <table class="ui-list w-full text-sm">
                    <thead>
                        <tr class="border-b border-(--color-border) text-start text-2xs
                                   uppercase tracking-wide text-(--color-ink-muted)">
                            <th class="text-start">{{ __('core.table.name') }}</th>
                            <th class="text-end">{{ __('core.table.size') }}</th>
                            <th class="text-end"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($files as $file)
                            <tr class="border-b border-(--color-border)/50">
                                <td class="font-mono text-2xs">{{ $file['name'] }}</td>
                                <td class="num text-end text-2xs">
                                    {{ number_format($file['bytes'] / 1024) }} KB
                                </td>
                                <td class="text-end">
                                    @can('backup.download')
                                        <a href="{{ route('backup.download', $file['name']) }}"
                                           class="text-2xs underline decoration-dotted underline-offset-2">
                                            {{ __('core.action.export') }}
                                        </a>
                                    @endcan
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

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

            দরকারের দিন কেউ যেন মনে করার চেষ্টা না করে।
        --}}
        <h3 class="mt-4 font-semibold">{{ __('core.backup.restore') }}</h3>
        <p class="mt-1 max-w-(--spacing-prose-max) text-sm text-(--color-ink-muted)">
            {{ __('core.backup.restore_note') }}
        </p>
    </section>
</x-layouts.app>
