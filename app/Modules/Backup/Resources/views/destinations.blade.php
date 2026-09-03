{{--
    গন্তব্য — কোথায় কপি যাবে, আর সেটা গ্রাহক নিজে ঠিক করেন।

    ── কেন এই পর্দাটা লাগে ──────────────────────────────────────────────
    মালিকের কথা: *"user zate nijei pendrive, others drive egulo select
    korte pare"*। আজ গন্তব্য বসে `.env`-এ — অর্থাৎ কেবল ডেভেলপার। ABOS
    বিক্রি হয় এমন মানুষের কাছে যাঁরা `.env` খোলেন না।

    ── ⚠️ কার মেশিনের ড্রাইভ — সবচেয়ে বড় ভুল বোঝাবুঝি ────────────────────
    নিচের তালিকাটা **সার্ভারের** ড্রাইভ, ব্রাউজারের মেশিনের নয়। অফিসের
    মেশিনে ABOS চললে পেনড্রাইভটা ওখানেই লাগানো থাকে আর তালিকাটা কাজে
    লাগে; সার্ভার ডেটা সেন্টারে থাকলে গ্রাহকের নিজের পেনড্রাইভ এখানে
    কোনোদিন দেখা যাবে না — তখন পথটা "ব্যাকআপ নামান" বোতাম।

    তাই পর্দায় ওই কথাটা লেখা থাকে। খালি তালিকা কোনো ভুল নয়।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('backup::menu.destinations') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('backup::menu.destinations')"
                          :subtitle="__('backup::message.no_destination_why')" />
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

    <div class="grid gap-4 lg:grid-cols-2">
        {{-- যেগুলো বসানো আছে --}}
        <section data-boxed
                 class="rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card) p-4">
            <h2 class="mb-2 font-semibold">{{ __('backup::menu.destinations') }}</h2>

            @if ($destinations->isEmpty())
                <p class="text-sm text-(--color-badge-danger-ink)">
                    {{ __('backup::message.no_destination') }}
                </p>
            @else
                <ul class="space-y-2">
                    @foreach ($destinations as $destination)
                        @php $days = $destination->daysSinceLastCopy(); @endphp

                        <li class="rounded-(--radius-field) border border-(--color-border) p-2">
                            <div class="flex items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <p class="truncate font-medium">{{ $destination->name }}</p>
                                    <p class="text-2xs text-(--color-ink-muted)">
                                        {{ $destination->driver }} · {{ $destination->kind }}
                                    </p>
                                </div>

                                <div class="flex shrink-0 items-center gap-2">
                                    {{-- ⚠️ "পরীক্ষা করুন" — সুবিধা নয়, নকশার অংশ।
                                         যে গন্তব্য কোনোদিন পরীক্ষা করা হয়নি, সেটা
                                         গন্তব্য নয় — একটা আশা। --}}
                                    <form method="POST"
                                          action="{{ route('backup.destination.test', $destination) }}">
                                        @csrf
                                        <button type="submit"
                                                class="rounded-(--radius-field) border border-(--color-border)
                                                       px-2 py-1 text-2xs hover:bg-(--color-surface-hover)">
                                            {{ __('core.action.refresh') }}
                                        </button>
                                    </form>

                                    <form method="POST"
                                          action="{{ route('backup.destination.destroy', $destination) }}">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit"
                                                class="rounded-(--radius-field) px-2 py-1 text-2xs
                                                       text-(--color-danger) hover:bg-(--color-danger)/10">
                                            {{ __('core.action.delete') }}
                                        </button>
                                    </form>
                                </div>
                            </div>

                            <p @class([
                                'mt-1 text-2xs',
                                'text-(--color-badge-danger-ink)' => $days === null || $days > 7,
                                'text-(--color-ink-muted)' => $days !== null && $days <= 7,
                            ])>
                                {{ $days === null
                                    ? __('core.backup.never_mirrored')
                                    : __('core.backup.days_ago', ['days' => $days]) }}

                                @if ($destination->last_error)
                                    — {{ $destination->last_error }}
                                @endif
                            </p>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        {{-- নতুন একটা --}}
        <section data-boxed
                 class="rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card) p-4">
            <h2 class="mb-2 font-semibold">{{ __('core.action.create') }}</h2>

            <form method="POST" action="{{ route('backup.destination.store') }}" class="space-y-3">
                @csrf

                <x-ui.field name="name" :label="__('core.table.name')" :value="old('name')" required />

                <label class="block">
                    <span class="mb-1 block text-2xs font-semibold uppercase tracking-wide
                                 text-(--color-ink-muted)">{{ __('backup::menu.destinations') }}</span>
                    <select name="driver"
                            class="h-(--spacing-field) w-full rounded-(--radius-field) border
                                   border-(--color-border) bg-(--color-surface-app) px-2 text-sm">
                        @foreach ($drivers as $driver)
                            <option value="{{ $driver }}">{{ $driver }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="block">
                    <span class="mb-1 block text-2xs font-semibold uppercase tracking-wide
                                 text-(--color-ink-muted)">{{ __('core.table.type') }}</span>
                    <select name="kind"
                            class="h-(--spacing-field) w-full rounded-(--radius-field) border
                                   border-(--color-border) bg-(--color-surface-app) px-2 text-sm">
                        <option value="secondary">secondary</option>
                        <option value="offline">offline</option>
                        <option value="offsite">offsite</option>
                        <option value="primary">primary</option>
                    </select>
                </label>

                {{--
                    সার্ভার যে ড্রাইভগুলো দেখতে পায়।

                    ⓘ তালিকাটা একটা **সুবিধা** — বাছাই সহজ করে, কিন্তু
                    হাতে পথ লেখাও যায়। খালি তালিকা মানে সার্ভার কোনো
                    অতিরিক্ত ড্রাইভ দেখতে পাচ্ছে না, ভুল কিছু নয়।
                --}}
                @if ($drives !== [])
                    <div>
                        <p class="mb-1 text-2xs font-semibold uppercase tracking-wide
                                  text-(--color-ink-muted)">
                            {{ __('backup::menu.destinations') }}
                        </p>
                        <ul class="space-y-1 text-2xs">
                            @foreach ($drives as $drive)
                                <li class="flex justify-between gap-2">
                                    <span class="font-mono">{{ $drive['path'] }}</span>
                                    <span class="num text-(--color-ink-muted)">
                                        {{ $drive['free'] ? number_format($drive['free'] / 1073741824, 1).' GB' : '—' }}
                                        @if ($drive['removable']) · USB? @endif
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <x-ui.field name="path" :label="__('core.backup.folder')" :value="old('path')" />

                <x-ui.button type="submit" tone="primary">{{ __('core.action.save') }}</x-ui.button>
            </form>
        </section>
    </div>
</x-layouts.app>
