{{--
    সই দেওয়ার ভার — কে কার হয়ে, কোন সময় পর্যন্ত।

    ── ⭐ মালিকের নকশা, ২৪ সেপ্টেম্বর ২০২৬ ─────────────────────────────
    ছুটিতে যাওয়ার আগে সই দেওয়ার ভার অন্যকে দিয়ে যাওয়া যাবে, আর মেয়াদ
    শেষে সেটা **নিজে থেকেই** বন্ধ হবে।

    ── ⚠️ কেন দুইটা তালিকা ─────────────────────────────────────────────
    ⓘ *"আমি কাকে দিয়েছি"* — ফিরে এসে বন্ধ করার জন্য, আর কে এখন আমার
    হয়ে সই দিচ্ছেন তা জানার জন্য।
    ⓘ *"আমার কাছে কার ভার"* — কারণ ইনবক্সে হঠাৎ অন্যের কাগজ দেখলে
    মানুষ ভাবেন কিছু একটা ভুল হয়েছে, আর সই দিতে ভয় পান।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('approval::menu.delegation') }}</x-slot:title>

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
            {{ $errors->first() }}
        </div>
    @endif

    <p class="mb-4 rounded-(--radius-field) bg-(--color-surface-sunken) px-3 py-2 text-sm">
        {{ __('approval::message.delegation_note') }}
    </p>

    <div class="grid gap-4 lg:grid-cols-2 lg:items-start">
        {{-- ── ভার দেওয়ার ফর্ম ─────────────────────────────────── --}}
        <div class="min-w-0 space-y-4">
            <section data-boxed
                     class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                <h2 class="mb-3 font-semibold">{{ __('approval::action.delegate') }}</h2>

                <form method="POST" action="{{ route('approval.delegation.store') }}" class="space-y-3">
                    @csrf

                    <x-ui.select name="to_user_id" :label="__('approval::field.delegate_to')" required
                                 :options="$people->pluck('name', 'id')"
                                 :selected="old('to_user_id')" />

                    <div class="grid gap-3 sm:grid-cols-2">
                        {{--
                            ⛔ শুরুর তারিখ পিছনে যেতে পারে না।

                            ⚠️ পিছনের তারিখে ভার দিলে **ইতিমধ্যে হয়ে যাওয়া** সিদ্ধান্ত
                            বৈধ দেখাত — কেউ অনুমতি ছাড়া সই দিয়ে পরে ভারটা পিছিয়ে
                            বসিয়ে দিতে পারতেন। ⓘ বাধাটা কন্ট্রোলারেও আছে; এটা
                            কেবল সৌজন্য।
                        --}}
                        <x-ui.field name="starts_on" type="date" required
                                    :label="__('approval::field.starts_on')"
                                    :min="now()->format('Y-m-d')"
                                    :value="old('starts_on', now()->format('Y-m-d'))" />

                        <x-ui.field name="ends_on" type="date" required
                                    :label="__('approval::field.ends_on')"
                                    :min="now()->format('Y-m-d')"
                                    :value="old('ends_on', now()->addDays(7)->format('Y-m-d'))" />
                    </div>

                    <fieldset>
                        <legend class="mb-1 block text-sm font-medium">
                            {{ __('approval::field.delegate_modules') }}
                        </legend>

                        {{--
                            ⓘ একটাও না বাছলে **সব** — সেটাই সবচেয়ে সাধারণ ব্যবহার
                            ("আমার সব অনুমোদন")। ⚠️ খালিকে "কিছুই না" ধরলে
                            জিনিসটা কাজই করত না।
                        --}}
                        <p class="mb-2 text-2xs text-(--color-ink-muted)">
                            {{ __('approval::field.delegate_all') }}
                        </p>

                        <div class="grid gap-2 sm:grid-cols-2">
                            @foreach ($modules as $code => $label)
                                <label class="flex min-h-(--spacing-touch) items-center gap-2 text-sm">
                                    <input type="checkbox" name="modules[]" value="{{ $code }}"
                                           @checked(in_array($code, old('modules', []), true))
                                           class="size-4">
                                    <span>{{ $label }}</span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>

                    <x-ui.field name="reason" :label="__('approval::field.delegate_reason')"
                                :value="old('reason')" />

                    <x-ui.button type="submit" tone="primary">
                        {{ __('approval::action.delegate') }}
                    </x-ui.button>
                </form>
            </section>

            {{-- ── আমার কাছে কার ভার ────────────────────────────── --}}
            <section data-boxed
                     class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                <h2 class="mb-3 font-semibold">{{ __('approval::field.delegation_held') }}</h2>

                @forelse ($held as $row)
                    <p class="border-t border-(--color-border) py-2 text-sm first:border-0 first:pt-0">
                        <strong>{{ $row->from?->name ?? '—' }}</strong>
                        <span class="text-(--color-ink-muted)">
                            · {{ $row->starts_on?->format('d-m-Y') }} — {{ $row->ends_on?->format('d-m-Y') }}
                        </span>
                    </p>
                @empty
                    <p class="text-sm text-(--color-ink-muted)">
                        {{ __('approval::message.delegation_held_none') }}
                    </p>
                @endforelse
            </section>
        </div>

        {{-- ── আমি যাঁদের দিয়েছি ───────────────────────────────── --}}
        <div class="min-w-0 space-y-4">
            <section data-boxed
                     class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                <h2 class="mb-3 font-semibold">{{ __('approval::field.delegation_given') }}</h2>

                @forelse ($given as $row)
                    @php($live = $row->revoked_at === null
                        && $row->starts_on?->isPast()
                        && $row->ends_on?->isFuture())

                    <div class="flex items-start justify-between gap-2 border-t border-(--color-border)
                                py-2 text-sm first:border-0 first:pt-0">
                        <div class="min-w-0">
                            <strong>{{ $row->to?->name ?? '—' }}</strong>

                            {{--
                                ⚠️ অবস্থাটা লেখা থাকে, কারণ তারিখ দেখে মাথায় হিসাব
                                করা ভুল হয় — বিশেষ করে যেদিন মেয়াদ শেষ।
                            --}}
                            @if ($row->revoked_at !== null)
                                <span class="text-(--color-ink-muted)">· {{ __('approval::action.delegation_stop') }}</span>
                            @elseif ($live)
                                <span class="text-(--color-badge-success-ink)">· ●</span>
                            @endif

                            <span class="block text-2xs text-(--color-ink-muted)">
                                {{ $row->starts_on?->format('d-m-Y') }} — {{ $row->ends_on?->format('d-m-Y') }}
                                @if (($row->modules ?? []) !== [])
                                    · {{ implode(' · ', array_map(fn ($m) => $modules[$m] ?? $m, $row->modules)) }}
                                @else
                                    · {{ __('approval::field.delegate_all') }}
                                @endif
                            </span>
                        </div>

                        @if ($row->revoked_at === null)
                            <form method="POST" action="{{ route('approval.delegation.destroy', $row) }}">
                                @csrf
                                @method('DELETE')

                                <button type="submit"
                                        class="min-h-(--spacing-touch) rounded-(--radius-field) border
                                               border-(--color-border) px-3 text-xs">
                                    {{ __('approval::action.delegation_stop') }}
                                </button>
                            </form>
                        @endif
                    </div>
                @empty
                    <p class="text-sm text-(--color-ink-muted)">
                        {{ __('approval::message.delegation_none') }}
                    </p>
                @endforelse

                <x-ui.pager :rows="$given" />
            </section>
        </div>
    </div>
</x-layouts.app>
