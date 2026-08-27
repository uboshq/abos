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

            {{--
                দুইটা দল — যেগুলো বিন্যাস বদলায়, আর যেগুলো কেবল রং।

                ── কেন ভাগটা দরকার ─────────────────────────────────────
                আগে দশটা কার্ড এক গ্রিডে বসত, আর দেখে বোঝার উপায় ছিল না
                কোনটা কী করবে। Navy বাছলে কেবল রং বদলায়; Apps বাছলে গোটা
                শেলটাই — সাইডবার উধাও, উপরে বেগুনি বার, লঞ্চার শিট।

                দুইটাকে একইভাবে দেখানো মানে বাছাইটা আন্দাজে করানো। কেউ
                "একটু অন্য রং চাই" ভেবে Apps বেছে ফেলতেন, আর গোটা ERP
                অচেনা হয়ে যেত।

                ── দলটা কোথা থেকে আসে ──────────────────────────────────
                `Ui::changesArrangement()` — হাতে লেখা কোনো তালিকা নয়।
                নতুন রূপ যোগ হলে সে নিজে থেকেই ঠিক দলে বসে।
            --}}
            @php
                /*
                 * `preserveKeys: true` — আর এটা বাদ দেওয়া যাবে না।
                 *
                 * `groupBy()` ডিফল্টে মূল চাবিগুলো ফেলে দিয়ে ০,১,২…
                 * বসায়। এখানে চাবিটাই রূপের নাম (`navy`, `apps`), আর
                 * সেটাই কার্ডের রেডিওর `value` হয়।
                 *
                 * ছাড়া চালিয়ে দেখা গেল রেডিওগুলোর মান হয়ে গেছে ০,১,২ —
                 * অর্থাৎ **কোনো রূপই আর বাছাই করা যেত না**, আর সেভ
                 * করলে `clean()` সবটাকে ডিফল্টে নামিয়ে দিত।
                 *
                 * পাতাটা দেখতে নিখুঁত ছিল: দুইটা শিরোনাম, ৮ ও ২টা কার্ড,
                 * সব রং ঠিক। ধরা পড়েছে ব্রাউজারে রেডিও খুঁজতে গিয়ে।
                 */
                $grouped = collect($uis)->groupBy(
                    fn ($ui, $key) => \App\Core\Support\Ui::changesArrangement($key) ? 'shape' : 'colour',
                    preserveKeys: true,
                );
            @endphp

            @foreach (['shape', 'colour'] as $band)
                @php $group = $grouped->get($band); @endphp

                {{-- দলটা খালি থাকলে শিরোনামটাও থাকে না — একটা খালি
                     শিরোনাম প্রতিবার একটা অনুপস্থিত জিনিস পড়ায়। --}}
                @if ($group?->isNotEmpty())
                    <h3 class="mt-4 mb-1 text-2xs font-semibold tracking-wide
                               text-(--color-ink-muted) uppercase first:mt-0">
                        {{ __('core.appearance.band_'.$band) }}
                    </h3>

                    <p class="mb-3 max-w-(--spacing-prose-max) text-2xs text-(--color-ink-muted)">
                        {{ __('core.appearance.band_'.$band.'_note') }}
                    </p>

                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        @foreach ($group as $key => $ui)
                            @include('workspace.partials.ui-card', [
                                'key' => $key,
                                'ui' => $ui,
                                'selected' => $key === $current['ui'],
                            ])
                        @endforeach
                    </div>
                @endif
            @endforeach
        </section>

        {{--
            কোম্পানির নিজের রূপ — থাকলে তবেই।

            ── কেন আলাদা একটা ভাগ, একই গ্রিডে মিশিয়ে নয় ─────────────────
            দশটা কোড-রূপ আমাদের লেখা ও অপরিবর্তনীয়; এগুলো কোম্পানির
            নিজের, আর কাল বদলে যেতে পারে। এক গ্রিডে মিশিয়ে দিলে মানুষ
            বুঝতেন না কোনটা কোনটা — আর "আমাদেরটা কে বদলাল" প্রশ্নের
            উত্তর খুঁজতে গিয়ে দশটা কার্ড ঘেঁটে দেখতেন।

            ── কেন খালি থাকলে ভাগটাই থাকে না ─────────────────────────────
            বেশিরভাগ কোম্পানি কোনোদিন নিজের রূপ বানাবে না। একটা খালি
            শিরোনাম রেখে দিলে প্রতিটা ব্যবহারকারী প্রতিবার একটা
            অনুপস্থিত জিনিস পড়তেন।
        --}}
        @if ($skins->isNotEmpty())
            <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
                <h2 class="font-semibold">{{ __('core.look.title') }}</h2>
                <p class="mt-0.5 mb-3 max-w-(--spacing-prose-max) text-sm text-(--color-ink-muted)">
                    {{ __('core.look.mine_note') }}
                </p>

                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach ($skins as $skin)
                        {{-- নমুনার গড়নটা গোড়ার কোড-রূপ থেকে — ঘনত্ব ও মেনুর
                             জায়গা ওখান থেকেই নামে। নাম ও ব্লার্ব তার নিজের। --}}
                        @include('workspace.partials.ui-card', [
                            'key' => $skin->public_id,
                            'ui' => array_replace(
                                \App\Core\Support\Ui::all()[$skin->rootLook()],
                                [
                                    'label' => $skin->name,
                                    'blurb' => __('core.look.stands_on', [
                                        'name' => __('core.ui.'.$skin->rootLook()),
                                    ]),
                                    'imitates' => null,
                                ],
                            ),
                            'selected' => $skin->public_id === $current['ui'],
                        ])
                    @endforeach
                </div>
            </section>
        @endif

        {{-- রং --}}
        <section data-boxed class="rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
            <h2 class="font-semibold">{{ __('core.appearance.accent') }}</h2>
            <p class="mt-0.5 mb-3 max-w-(--spacing-prose-max) text-sm text-(--color-ink-muted)">
                {{ __('core.appearance.accent_note') }}
            </p>

            <div class="flex flex-wrap gap-2">
                @foreach ($accents as $key => $accent)
                    <label class="group flex min-h-(--spacing-touch) cursor-pointer select-none items-center gap-2
                                  rounded-(--radius-field) border border-(--color-border) px-3 transition-colors
                                  hover:bg-(--color-surface-hover)
                                  has-[:checked]:border-(--color-brand-500)
                                  has-[:checked]:bg-(--color-surface-selected)
                                  has-[:focus-visible]:outline-2
                                  has-[:focus-visible]:outline-offset-2
                                  has-[:focus-visible]:outline-(--color-brand-500)">
                        <input type="radio" name="accent" value="{{ $key }}"
                               @checked($key === $current['accent'])
                               class="sr-only">

                        <span class="size-5 shrink-0 rounded-full ring-1 ring-black/10"
                              style="background: {{ $accent['swatch'] }}" aria-hidden="true"></span>

                        <span class="text-sm">{{ __($accent['label']) }}</span>

                        <span class="hidden text-(--color-brand-500) group-has-[:checked]:inline"
                              aria-hidden="true">✓</span>
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
                    <label class="group flex min-h-(--spacing-touch) cursor-pointer select-none items-center gap-2
                                  rounded-(--radius-field) border border-(--color-border) px-4 transition-colors
                                  hover:bg-(--color-surface-hover)
                                  has-[:checked]:border-(--color-brand-500)
                                  has-[:checked]:bg-(--color-surface-selected)
                                  has-[:focus-visible]:outline-2
                                  has-[:focus-visible]:outline-offset-2
                                  has-[:focus-visible]:outline-(--color-brand-500)">
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
                    <label class="group flex min-h-(--spacing-touch) cursor-pointer select-none items-center gap-2
                                  rounded-(--radius-field) border border-(--color-border) px-4 transition-colors
                                  hover:bg-(--color-surface-hover)
                                  has-[:checked]:border-(--color-brand-500)
                                  has-[:checked]:bg-(--color-surface-selected)
                                  has-[:focus-visible]:outline-2
                                  has-[:focus-visible]:outline-offset-2
                                  has-[:focus-visible]:outline-(--color-brand-500)">
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
