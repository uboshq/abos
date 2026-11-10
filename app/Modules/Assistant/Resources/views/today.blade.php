{{--
    ⭐ কোপাইলটের নিজের পাতা — সকালের সারাংশ আর নজর (পূর্ণ পরিকল্পনা 9a6nHmbFk5NF7YB5NbzCC2 অংশ ১; ধাপ ০ ও ১)।
    ⓘ প্রস্তাব, খসড়া, জিজ্ঞাসা — পরের ধাপে; ট্যাবগুলো এখনই আছে, "আসছে" লেখা, যাতে মানুষ জানেন কোথায় আসবে।
    ⛔ এখানে কোনো সংখ্যা গোনা হয় না — বোর্ডের অঙ্ক, একই চাবি ([[Watch]], [[Brief]])।
--}}
@php
    $tone = [
        'urgent' => 'bg-(--color-badge-danger-bg) text-(--color-badge-danger-ink)',
        'today' => 'bg-(--color-badge-warning-bg) text-(--color-badge-warning-ink)',
        'week' => 'bg-(--color-surface-sunken) text-(--color-ink-muted)',
    ];
@endphp
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ $name }}</x-slot:title>

    <div class="space-y-4" data-assistant-page>
        <div class="flex flex-wrap items-center gap-3">
            <span class="inline-flex size-10 items-center justify-center rounded-(--radius-card) bg-(--color-brand-500) text-white" aria-hidden="true"><x-ui.icon name="brain" :size="24" /></span>
            <div class="min-w-0">
                <h1 class="text-lg font-semibold">{{ $name }}</h1>
                <p class="text-sm text-(--color-ink-muted)">{{ __('assistant::page.lede') }}</p>
            </div>
        </div>

        <nav class="flex gap-1 overflow-x-auto border-b border-(--color-border) text-sm" aria-label="{{ $name }}">
            <span class="border-b-2 border-(--color-brand-500) px-3 py-2 font-semibold text-(--color-brand-500)" aria-current="page">{{ __('assistant::page.tab_today') }}</span>
            @foreach (['ask'] as $soon)
                <span class="px-3 py-2 text-(--color-ink-muted)" data-assistant-soon="{{ $soon }}">{{ __('assistant::page.tab_'.$soon) }} <span class="text-2xs">· {{ __('assistant::page.soon') }}</span></span>
            @endforeach
            @can('assistant.audit')
                <a href="{{ route('assistant.audit') }}" class="px-3 py-2 text-(--color-link) hover:underline">{{ __('assistant::page.tab_audit') }}</a>
            @endcan
        </nav>

        @include('notification::partials.flash')

        {{-- ── সকালের সারাংশ ── --}}
        @if ($brief !== null)
            <section class="space-y-3" aria-labelledby="brief-h" data-assistant-brief>
                <h2 id="brief-h" class="text-base font-semibold">{{ __('assistant::page.brief_title') }}</h2>
                @if ($brief === [])
                    <p class="text-sm text-(--color-ink-muted)">{{ __('assistant::page.brief_empty') }}</p>
                @endif
                @foreach ($brief as $part)
                    <div class="space-y-2" data-brief-part="{{ $part['module'] }}">
                        <h3 class="text-sm font-medium text-(--color-ink-muted)">{{ $part['title'] }}</h3>
                        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                            @foreach ($part['rows'] as $row)
                                <a href="{{ $row['href'] }}" class="block rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-3 hover:border-(--color-brand-500)">
                                    <span class="block text-2xs text-(--color-ink-muted)">{{ $row['label'] }}</span>
                                    <span class="block text-lg font-semibold tabular-nums">{{ $row['value'] }}</span>
                                    @if ($row['sub'] !== '')<span class="block text-2xs text-(--color-ink-muted)">{{ $row['sub'] }}</span>@endif
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </section>
        @endif

        {{-- ── নজর ── --}}
        @if ($findings !== null)
            <section class="space-y-3" aria-labelledby="watch-h" data-assistant-watch>
                <h2 id="watch-h" class="text-base font-semibold">{{ __('assistant::page.watch_title') }} <span class="text-sm text-(--color-ink-muted)">({{ count($findings) }})</span></h2>
                @if ($findings === [])
                    <p class="text-sm text-(--color-ink-muted)" data-assistant-calm>{{ __('assistant::page.watch_empty') }}</p>
                @else
                    <ul class="grid gap-2">
                        @foreach ($findings as $f)
                            <li class="grid gap-2 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-3 md:grid-cols-[minmax(0,1fr)_auto]" data-finding="{{ $f['id'] }}" data-severity="{{ $f['severity'] }}">
                                <div class="min-w-0">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="rounded-(--radius-badge) px-2 py-0.5 text-2xs {{ $tone[$f['severity']] ?? '' }}">{{ __('assistant::page.sev.'.$f['severity']) }}</span>
                                        <span class="text-2xs text-(--color-ink-muted)">{{ __('assistant::page.module.'.$f['module']) }}</span>
                                    </div>
                                    <a href="{{ $f['href'] }}" class="mt-1 block font-medium hover:underline">{{ $f['title'] }} <span class="tabular-nums">· {{ $f['count'] }}</span></a>
                                    @if ($f['detail'] !== '')<p class="text-sm text-(--color-ink-muted)">{{ $f['detail'] }}</p>@endif
                                    <p class="text-2xs text-(--color-ink-muted)">{{ __('assistant::page.source', ['board' => __('assistant::page.module.'.$f['module'])]) }}</p>
                                </div>
                                <details class="text-sm">
                                    <summary class="cursor-pointer text-(--color-link)">{{ __('assistant::page.dismiss') }}</summary>
                                    <form method="POST" action="{{ route('assistant.dismiss') }}" class="mt-2 grid gap-2">
                                        @csrf
                                        <input type="hidden" name="finding" value="{{ $f['id'] }}">
                                        <label class="grid gap-1 text-2xs text-(--color-ink-muted)">{{ __('assistant::page.dismiss_reason') }}
                                            <input type="text" name="reason" required minlength="3" maxlength="255" class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-sm">
                                        </label>
                                        <button type="submit" class="h-(--spacing-field) rounded-(--radius-field) bg-(--color-brand-500) px-3 text-sm text-white">{{ __('assistant::page.dismiss_go') }}</button>
                                    </form>
                                </details>
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if ($dismissed->isNotEmpty())
                    <details class="text-sm" data-assistant-dismissed>
                        <summary class="cursor-pointer text-(--color-ink-muted)">{{ __('assistant::page.dismissed_list', ['n' => $dismissed->count()]) }}</summary>
                        <ul class="mt-2 grid gap-1">
                            @foreach ($dismissed as $d)
                                <li class="text-2xs text-(--color-ink-muted)">{{ $d->finding }} · {{ $d->reason }} · {{ $d->user?->name ?? '—' }} · {{ __('assistant::page.until', ['date' => $d->until->format('d/m/Y')]) }}</li>
                            @endforeach
                        </ul>
                    </details>
                @endif
            </section>
        @endif

        {{-- ── প্রস্তাব (ধাপ ২) ── --}}
        @if ($suggestions !== null)
            <section class="space-y-3" aria-labelledby="suggest-h" data-assistant-suggest>
                <h2 id="suggest-h" class="text-base font-semibold">{{ __('assistant::suggest.title') }} <span class="text-sm text-(--color-ink-muted)">({{ count($suggestions) }})</span></h2>
                @if ($suggestions === [])
                    <p class="text-sm text-(--color-ink-muted)">{{ __('assistant::suggest.empty') }}</p>
                @endif
                <div class="grid gap-3 lg:grid-cols-2">
                    @foreach ($suggestions as $s)
                        <article class="grid gap-2 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-3" data-suggestion="{{ $s['id'] }}">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="rounded-(--radius-badge) bg-(--color-surface-selected) px-2 py-0.5 text-2xs text-(--color-link)">{{ __('assistant::page.module.'.$s['module']) }}</span>
                            </div>
                            <a href="{{ $s['href'] }}" class="font-medium hover:underline">{{ $s['title'] }}</a>
                            <p class="text-sm text-(--color-ink-muted)">{{ $s['detail'] }}</p>
                            <ul class="grid gap-1 text-sm">
                                @foreach ($s['rows'] as $row)
                                    <li class="flex flex-wrap justify-between gap-2 border-t border-(--color-border) pt-1">
                                        @if ($row['href'] !== '')<a href="{{ $row['href'] }}" class="min-w-0 hover:underline">{{ $row['label'] }}</a>@else<span class="min-w-0">{{ $row['label'] }}</span>@endif
                                        <span class="text-2xs text-(--color-ink-muted) tabular-nums">{{ $row['value'] }}</span>
                                    </li>
                                @endforeach
                            </ul>
                            <p class="text-2xs text-(--color-ink-muted)">{{ $s['note'] }}</p>
                            {{-- ⭐ খসড়া (ধাপ ৩) — কোপাইলট বানায়, পাঠান মানুষ --}}
                            @if ($s['id'] === 'suggest.reorder' && auth()->user()->can('purchase.requisition.create'))
                                <form method="POST" action="{{ route('assistant.draft.reorder') }}">
                                    @csrf
                                    <button type="submit" class="h-(--spacing-field) rounded-(--radius-field) bg-(--color-brand-500) px-3 text-sm text-white" data-draft-reorder>{{ __('assistant::draft.reorder_button') }}</button>
                                </form>
                            @elseif ($s['id'] === 'suggest.call')
                                <a href="{{ route('assistant.draft.dunning') }}" class="inline-flex h-(--spacing-field) items-center rounded-(--radius-field) border border-(--color-brand-500) px-3 text-sm text-(--color-brand-500)" data-draft-dunning>{{ __('assistant::draft.dunning_button') }}</a>
                            @endif
                        </article>
                    @endforeach
                </div>
            </section>
        @endif

        <p class="text-2xs text-(--color-ink-muted)">{{ __('assistant::page.rules') }}</p>
    </div>
</x-layouts.app>
