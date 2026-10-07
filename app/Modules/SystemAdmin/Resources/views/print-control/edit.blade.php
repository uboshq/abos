{{--
    ছাপার নিয়ন্ত্রণ — কাগজ ধরে ট্যাব, প্রতিটার ভেতরে মাপ (A4 · A5 · Thermal), প্রতিটায় সেই মাপের নকশা।

    ⭐ মালিক, ৩০ সেপ্টেম্বর ২০২৬: আগের তেরোটা তৈরি রূপ সরল (ছয় কাগজে ৭৮টা কার্ড); কাজ চালানোর
    জন্য থাকল কেবল "সাধারণ"। নতুন নকশা আসে মডিউলের ঘোষণা থেকে ([[PrintControlController::designsFor()]])।

    ⚠️ ফর্ম নিজে বলে সে কোন কাগজ-মাপ বহন করছে (`paper`, `size`) — তার বাইরে কিছু ছোঁয়া হয় না।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('system_admin::settings.print_title') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('system_admin::settings.print_title')"
                          :subtitle="__('system_admin::settings.print_note')" />
    </x-slot:header>

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    @include('system_admin::control-panel.partials.tabs')
    @include('system_admin::print-control.partials.papers', ['current' => $paper])

    {{-- ── কোন শাখার — মালিক, ৩০ সেপ্টেম্বর ২০২৬: শাখা ধরে আলাদা নকশা ─────────── --}}
    <nav aria-label="{{ __('system_admin::settings.invoice_info_for') }}" data-branch-picker
         class="mb-3 flex flex-wrap items-center gap-1">
        <span class="me-2 text-xs text-(--color-ink-muted)">{{ __('system_admin::settings.invoice_info_for') }}</span>
        @foreach ([null, ...$branches] as $one)
            @php $id = $one?->id; @endphp
            <a href="{{ route('system_admin.print_control', array_filter(['paper' => $paper, 'size' => $size, 'branch' => $id])) }}"
               @class([
                   'rounded-(--radius-field) px-3 py-1 text-sm transition-colors',
                   'bg-(--color-surface-sunken) font-semibold' => $branch === $id,
                   'text-(--color-ink-muted) hover:bg-(--color-surface-hover)' => $branch !== $id,
               ])
               @if ($branch === $id) aria-current="page" @endif>
                {{ $one === null ? __('system_admin::settings.invoice_info_company') : $one->name() }}
            </a>
        @endforeach
    </nav>

    {{-- ── মাপ — প্রতিটার নিজের ঠিকানা ─────────────────────────────── --}}
    <nav aria-label="{{ __('system_admin::settings.print_sizes') }}"
         class="mb-4 flex gap-1 border-b border-(--color-border)">
        @foreach ($sizes as $one)
            <a href="{{ route('system_admin.print_control', array_filter(['paper' => $paper, 'size' => $one, 'branch' => $branch])) }}"
               @class([
                   'min-h-(--spacing-touch) px-3 py-2 text-sm transition-colors',
                   'border-b-2 border-(--color-brand-600) font-semibold' => $size === $one,
                   'text-(--color-ink-muted) hover:bg-(--color-surface-hover)' => $size !== $one,
               ])
               @if ($size === $one) aria-current="page" @endif>
                {{ __('system_admin::settings.print_size.'.$one) }}
            </a>
        @endforeach
    </nav>

    @if ($target === null)
        {{-- ⓘ কোটেশনের এখনো কোনো ছাপা নেই — নমুনা নিশ্চিত হলে আসবে --}}
        <section data-boxed data-no-print-yet
                 class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4 text-sm">
            {{ __('system_admin::settings.print_not_yet') }}
        </section>
    @else
        {{-- ⭐ কার্ডে চাপলে আসল ছাপা, পপআপে — মালিক, ৩০ সেপ্টেম্বর ২০২৬: *"eigulote clic korle popup e real print
             a4 size er ber hobe"*। ⓘ `src` খালি = বন্ধ; পাতা না ছেড়ে, না-সংরক্ষিত বাছাই না হারিয়ে। --}}
        <div x-data="{ src: '' }">
        <form method="POST" action="{{ route('system_admin.print_control.update') }}" class="space-y-4">
            @csrf
            @method('PUT')
            <input type="hidden" name="paper" value="{{ $paper }}">
            <input type="hidden" name="size" value="{{ $size }}">
            <input type="hidden" name="branch" value="{{ $branch }}">

            {{-- ── নকশা, আর তার নমুনা ────────────────────────────────── --}}
            <section data-boxed
                     class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                <h2 class="mb-1 text-sm font-semibold">{{ __('system_admin::settings.print_pick_design') }}</h2>
                <p class="mb-3 text-xs text-(--color-ink-muted)">
                    {{ count($cards) > 1 ? __('system_admin::settings.print_pick_design_note') : __('system_admin::settings.print_only_standard') }}
                </p>

                {{-- ⓘ শাখায় প্রথম বিকল্প "কোম্পানির মতো" — বাছলে শাখার বদল মোছে, কোম্পানির নকশাই ছাপে --}}
                @if ($branch !== null && count($cards) > 0)
                    <label class="mb-3 flex items-center gap-2 text-sm" data-design-inherit>
                        <input type="radio" name="design" value="" @checked($chosen === '') class="size-4">
                        <span>{{ __('system_admin::settings.invoice_info_inherit') }}
                            ({{ $companyChoiceName }})</span>
                    </label>
                @endif

                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach ($cards as $card)
                        <label class="block cursor-pointer" data-design-card="{{ $card['code'] }}">
                            {{-- ⓘ `sr-only`, `hidden` নয় — কীবোর্ডে পৌঁছানো যায় --}}
                            <input type="radio" class="peer sr-only" name="design" value="{{ $card['code'] }}"
                                   @checked($chosen === $card['code']) @disabled(count($cards) === 1)>

                            <span class="block overflow-hidden rounded-(--radius-field) border border-(--color-border)
                                         transition-colors peer-focus-visible:ring-2 peer-focus-visible:ring-(--color-brand-600)
                                         peer-checked:border-(--color-brand-600) peer-checked:ring-2 peer-checked:ring-(--color-brand-600)">
                                @if ($card['sample'] !== null)
                                    {{-- ⛔ `allow-scripts` নেই; `allow-same-origin` লাগে, নইলে লোগো আটকাত --}}
                                    <span class="block h-44 w-full cursor-zoom-in overflow-hidden bg-white" data-open-pdf
                                          @if ($card['pdf']) @click="src = '{{ $card['pdf'] }}'" @endif>
                                        <iframe src="{{ $card['sample'] }}"
                                                title="{{ __('system_admin::settings.print_sample_of', ['format' => $card['name']]) }}"
                                                loading="lazy" tabindex="-1" sandbox="allow-same-origin"
                                                style="width: 760px; height: 1000px; border: 0; transform: scale(0.34);
                                                       transform-origin: top left; pointer-events: none;"></iframe>
                                    </span>
                                @endif

                                <span class="flex items-center justify-between gap-2 border-t border-(--color-border) px-3 py-2 text-sm">
                                    <span>{{ $card['name'] }}</span>
                                    @if ($card['sample'] !== null)
                                        <a href="{{ $card['sample'] }}" target="_blank" rel="noopener"
                                           class="shrink-0 text-xs text-(--color-ink-muted) underline">
                                            {{ __('system_admin::settings.print_sample') }}
                                        </a>
                                    @endif
                                </span>
                            </span>
                        </label>
                    @endforeach
                </div>

                <div class="mt-4">
                    <x-ui.button type="submit" tone="primary">{{ __('core.action.save') }}</x-ui.button>
                </div>
            </section>

            {{-- ── "সাধারণ" কাগজের সুইচ — কোন অংশ, কোন কলাম কোন ক্রমে ───────────
                 ⓘ কেবল যেখানে নতুন নকশা নেই; নকশাগুলো নিজের সুইচ মানে ("Set Your Invoice Information")। --}}
            @unless ($hasDesigns)
            <section data-boxed
                     class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                <h2 class="mb-1 text-sm font-semibold">{{ __('system_admin::settings.print_parts') }}</h2>
                <p class="mb-3 text-xs text-(--color-ink-muted)">{{ __('system_admin::settings.print_parts_note') }}</p>

                <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($profile['allParts'] as $part)
                        <label class="flex min-h-(--spacing-touch) items-start gap-2 text-sm">
                            <input type="checkbox" name="papers[{{ $target }}][parts][{{ $part }}]" value="1"
                                   @checked(in_array($part, $profile['parts'], true)) class="mt-1 size-4">
                            <span>{{ __('core.print.part.'.$part) }}</span>
                        </label>
                    @endforeach
                </div>
            </section>

            <section data-boxed
                     class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                <h2 class="mb-1 text-sm font-semibold">{{ __('system_admin::settings.print_columns') }}</h2>
                <p class="mb-3 text-xs text-(--color-ink-muted)">{{ __('system_admin::settings.print_columns_note') }}</p>

                <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach ($profile['columns'] as $column)
                        @php $place = array_search($column, $profile['on'], true); @endphp

                        <label class="block">
                            <span class="mb-1 block text-sm">{{ __('core.print.column.'.$column) }}</span>
                            {{-- ⓘ "বন্ধ" আলাদা বিকল্প, শূন্য নয় --}}
                            <select name="papers[{{ $target }}][columns][{{ $column }}]"
                                    class="h-(--spacing-field) w-full rounded-(--radius-field) border border-(--color-border)
                                           bg-(--color-surface-card) px-3">
                                <option value="" @selected($place === false)>{{ __('system_admin::settings.print_column_off') }}</option>
                                @foreach (range(1, count($profile['columns'])) as $n)
                                    <option value="{{ $n }}" @selected($place !== false && $place + 1 === $n)>{{ $n }}</option>
                                @endforeach
                            </select>
                        </label>
                    @endforeach
                </div>
            </section>

            @endunless

            <div class="flex flex-wrap items-center gap-3">
                <x-ui.button type="submit" tone="primary">{{ __('core.action.save') }}</x-ui.button>
            </div>
        </form>

        {{-- ── পপআপ: আসল PDF ──────────────────────────────────────────────
             ⓘ জ্যামিতি ইনলাইন `style`-এ, [[shell.peek]]-এর মতো — বান্ডলে শ্রেণি না থাকলে প্যানেল নীরবে ভাঙত। --}}
        <div x-show="src !== ''" x-cloak role="dialog" aria-modal="true" data-pdf-popup
             aria-label="{{ __('system_admin::settings.print_sample') }}"
             @keydown.escape.window="src = ''" @click.self="src = ''"
             style="position:fixed;inset:0;z-index:60;display:flex;justify-content:center;align-items:flex-start;
                    padding:3vh 16px 16px;background:rgb(0 0 0 / 0.55)">
            <div class="w-full rounded-(--radius-field) bg-(--color-surface-app) shadow-lg"
                 style="max-width:900px;height:94vh;display:flex;flex-direction:column">
                <div class="flex items-center justify-between gap-3 border-b border-(--color-border) px-4 py-2">
                    <a x-bind:href="src" target="_blank" rel="noopener"
                       class="text-xs text-(--color-link) underline underline-offset-2">{{ __('core.peek.open_full') }}</a>
                    <button type="button" @click="src = ''"
                            class="text-xs text-(--color-ink-muted) underline underline-offset-2">{{ __('core.peek.close') }}</button>
                </div>
                <iframe x-bind:src="src" title="{{ __('system_admin::settings.print_sample') }}"
                        style="flex:1;width:100%;border:0;background:#fff"></iframe>
            </div>
        </div>
        </div>
    @endif
</x-layouts.app>
