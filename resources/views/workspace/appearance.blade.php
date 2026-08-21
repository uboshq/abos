{{--
    চেহারা — ব্যবহারকারীর নিজের পর্দার রং ও থিম।

    কোম্পানির সেটিং নয়, ব্যক্তির: এক ডিপোর একটা কম্পিউটারে দিনে তিনজন
    বসে, আর যে নীল-সবুজের পার্থক্য বোঝে না তাকে অন্যজনকে অনুরোধ করতে
    হবে না। Control Panel-এ নেই সেই কারণেই — ওটা কোম্পানির জিনিস।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('core.appearance.title') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('core.appearance.title')"
                          :subtitle="__('core.appearance.subtitle')" />
    </x-slot:header>

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ __('core.appearance.saved') }}
        </div>
    @endif

    <form method="POST" action="{{ route('appearance.save') }}" class="space-y-4">
        @csrf

        {{--
            চেহারা — সবার আগে, আর কারণটা ক্রমেই লেখা।

            এই একটা বাছাই বাকি সবগুলোকে ঘিরে রাখে: চেহারা বদলালে
            অ্যাকসেন্ট একই রংয়েও অন্যরকম বসে, ঘনত্ব বদলায়, ধার বদলায়।
            রং আগে বেছে তারপর চেহারা বদলালে মানুষটাকে দুইবার বাছতে
            হত।
        --}}
        <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="font-semibold">{{ __('core.appearance.ui') }}</h2>
            <p class="mt-0.5 mb-3 max-w-(--spacing-prose-max) text-sm text-(--color-ink-muted)">
                {{ __('core.appearance.ui_note') }}
            </p>

            {{--
                রূপের নিজের রং — টিক দিলে বসে।

                ডিফল্টে টিক দেওয়া, কারণ বেশিরভাগ মানুষ রূপটা বাছেন
                "ওই ERP-র মতো দেখতে চাই" বলে, আর ওই ERP-র রংটাই তার
                অর্ধেক। যিনি নিজের রং ঠিক করে রেখেছেন, তিনি টিক তুলে
                দেবেন — আর তখন নিচের রঙের সারিটাই শেষ কথা।
            --}}
            <label class="mb-3 inline-flex items-center gap-2 text-sm">
                <input type="checkbox" name="match_accent" value="1" checked
                       class="size-4 rounded-(--radius-field) border-(--color-border)">
                <span>{{ __('core.appearance.match_accent') }}</span>
            </label>

            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($uis as $key => $ui)
                    @include('workspace.partials.ui-card', [
                        'key' => $key,
                        'ui' => $ui,
                        'selected' => $key === $current['ui'],
                    ])
                @endforeach
            </div>
        </section>

        {{-- রং --}}
        <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="font-semibold">{{ __('core.appearance.accent') }}</h2>
            <p class="mt-0.5 mb-3 max-w-(--spacing-prose-max) text-sm text-(--color-ink-muted)">
                {{ __('core.appearance.accent_note') }}
            </p>

            <div class="flex flex-wrap gap-2">
                @foreach ($accents as $key => $accent)
                    <label @class([
                        'flex min-h-(--spacing-touch) cursor-pointer items-center gap-2 rounded-(--radius-field)',
                        'border px-3 transition-colors',
                        'border-(--color-brand-500) bg-(--color-surface-selected)' => $key === $current['accent'],
                        'border-(--color-border) hover:bg-(--color-surface-hover)' => $key !== $current['accent'],
                    ])>
                        <input type="radio" name="accent" value="{{ $key }}"
                               @checked($key === $current['accent'])
                               class="sr-only">

                        <span class="size-5 shrink-0 rounded-full ring-1 ring-black/10"
                              style="background: {{ $accent['swatch'] }}" aria-hidden="true"></span>

                        <span class="text-sm">{{ __($accent['label']) }}</span>

                        @if ($key === $current['accent'])
                            <span class="text-(--color-brand-500)" aria-hidden="true">✓</span>
                        @endif
                    </label>
                @endforeach
            </div>
        </section>

        {{-- থিম --}}
        <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="font-semibold">{{ __('core.appearance.theme') }}</h2>
            <p class="mt-0.5 mb-3 max-w-(--spacing-prose-max) text-sm text-(--color-ink-muted)">
                {{ __('core.appearance.theme_note') }}
            </p>

            <div class="flex flex-wrap gap-2">
                @foreach (['light', 'dark'] as $theme)
                    <label @class([
                        'flex min-h-(--spacing-touch) cursor-pointer items-center gap-2 rounded-(--radius-field)',
                        'border px-4 transition-colors',
                        'border-(--color-brand-500) bg-(--color-surface-selected)' => $theme === $current['theme'],
                        'border-(--color-border) hover:bg-(--color-surface-hover)' => $theme !== $current['theme'],
                    ])>
                        <input type="radio" name="theme" value="{{ $theme }}"
                               @checked($theme === $current['theme'])
                               class="sr-only">
                        <span class="text-sm">{{ __('core.appearance.' . $theme) }}</span>
                    </label>
                @endforeach
            </div>
        </section>

        {{-- ভাষা — এখানেও, কারণ ব্যবহারকারী "চেহারা"-তেই এটা খোঁজে --}}
        <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="font-semibold">{{ __('core.appearance.language') }}</h2>

            <div class="mt-3 flex flex-wrap gap-2">
                @foreach (['bn' => 'বাংলা', 'en' => 'English'] as $code => $label)
                    <label @class([
                        'flex min-h-(--spacing-touch) cursor-pointer items-center gap-2 rounded-(--radius-field)',
                        'border px-4 transition-colors',
                        'border-(--color-brand-500) bg-(--color-surface-selected)' => $code === $current['locale'],
                        'border-(--color-border) hover:bg-(--color-surface-hover)' => $code !== $current['locale'],
                    ])>
                        <input type="radio" name="locale" value="{{ $code }}"
                               @checked($code === $current['locale'])
                               class="sr-only">
                        <span class="text-sm">{{ $label }}</span>
                    </label>
                @endforeach
            </div>
        </section>

        <x-ui.button type="submit" tone="primary">{{ __('core.action.save') }}</x-ui.button>
    </form>
</x-layouts.app>
