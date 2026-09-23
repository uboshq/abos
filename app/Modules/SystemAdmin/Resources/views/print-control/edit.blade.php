{{--
    ছাপার নিয়ন্ত্রণ — প্রতিটা কাগজের নিজের সুইচ, নিজের ঠিকানা।

    ── ⭐ মালিকের দুইটা কথা, ২৩ সেপ্টেম্বর ২০২৬ ──────────────────────────
    *"ami agei bolechi print seting alada seting hobe, ekhane alada tab
    hobe"* — তাই উপরের সারিটা কন্ট্রোল প্যানেলেরই, আর ছাপার ট্যাবটা
    সেখানে জ্বলে থাকে; কিন্তু পর্দাটা আলাদা, নিজের ফর্মে।

    *"print e invoice template vew kore deke select korar bebosta koro.
    zate age sample dekha zay tarpor select kora zay"* — তাই প্রতিটা
    রূপের নিজের নমুনা কাগজ পাশেই আঁকা থাকে।

    ── ⚠️ কেন কাগজগুলো এখন লিংক, Alpine নয় ──────────────────────────────
    আগে ছয়টা কাগজই এক পাতায় বসত আর `x-show` লুকিয়ে রাখত। ⛔ তাতে ট্যাবের
    নিজের কোনো ঠিকানা ছিল না, সংরক্ষণের পর সে প্রথম কাগজে ফিরে যেত, আর
    এখন ছয় গুচ্ছ নমুনা একসাথে আনতে হত — ছিয়াত্তরটা iframe।

    ⭐ এখন `?paper=challan` — বুকমার্ক করা যায়, সংরক্ষণের পরেও একই
    জায়গায় ফেরে (`back()`), আর পাতায় কেবল একটা কাগজের নমুনা আসে।

    ⚠️ ফর্মটা তখন কেবল একটা কাগজ বহন করে, তাই সে নিজেই বলে দেয় কোনটা
    (`scope[]`) — নাহলে সংরক্ষণ করামাত্র বাকি পাঁচটা কাগজ নীরবে "সাধারণ"
    রূপে ফিরে যেত।
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

    {{-- কন্ট্রোল প্যানেলের সারি — ছাপার ট্যাবটা এই পর্দারই --}}
    @include('system_admin::control-panel.partials.tabs')

    {{-- ── কোন কাগজ ────────────────────────────────────────────────────
         ⓘ প্রতিটার নিজের ঠিকানা, তাই "চালানের সুইচগুলো" বুকমার্ক করা যায়। --}}
    <nav aria-label="{{ __('system_admin::settings.print_papers') }}"
         class="-mx-1 mb-4 flex gap-1 overflow-x-auto px-1 pb-1">
        @foreach ($papers as $one)
            <a href="{{ route('system_admin.print_control', ['paper' => $one['code']]) }}"
               @class([
                   'flex min-h-(--spacing-touch) shrink-0 items-center whitespace-nowrap',
                   'rounded-(--radius-field) px-3 text-sm transition-colors',
                   'bg-(--color-brand-600) text-(--color-brand-ink) font-medium' => $paper['code'] === $one['code'],
                   'text-(--color-ink-muted) hover:bg-(--color-surface-sunken)' => $paper['code'] !== $one['code'],
               ])
               @if ($paper['code'] === $one['code']) aria-current="page" @endif>
                {{ $one['label'] }}
            </a>
        @endforeach
    </nav>

    <form method="POST" action="{{ route('system_admin.print_control.update') }}" class="space-y-4">
        @csrf
        @method('PUT')

        {{-- ফর্মটা নিজে বলে দেয় সে কোন কাগজটা বহন করছে।

             ⛔ এই একটা লাইন না থাকলে চালানের একটা সুইচ বদলে সংরক্ষণ করতেই
             বাকি পাঁচটা কাগজ "সাধারণ" রূপে ফিরে যেত, নীরবে — কারণ অনুপস্থিত
             ঘরকে PrintFormat::chosen() `standard` ধরে। ⓘ একই ভুল কন্ট্রোল
             প্যানেলে ৩০ আগস্ট সত্যিই একবার হয়েছিল (৩৪টা সেটিং নীরবে বন্ধ)। --}}
        <input type="hidden" name="scope[]" value="{{ $paper['code'] }}">

        {{-- ── তৈরি রূপ, আর তার নমুনা ─────────────────────────────── --}}
        <section data-boxed
                 class="rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card) p-4">
            <h2 class="mb-1 text-sm font-semibold">
                {{ __('system_admin::settings.print_pick_format') }}
            </h2>
            <p class="mb-1 text-xs text-(--color-ink-muted)">
                {{ __('system_admin::settings.print_pick_format_note') }}
            </p>
            <p class="mb-3 text-xs text-(--color-ink-muted)">
                {{ __('system_admin::settings.print_sample_note') }}
            </p>

            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($formats as $format)
                    @php $name = __('core.print.format.'.$format); @endphp

                    <label class="block cursor-pointer">
                        {{-- ⓘ ঘরটা লুকানো নয়, `sr-only` — কীবোর্ডে ট্যাব করে
                             পৌঁছানো যায়, আর স্ক্রিন রিডার নামটা পড়ে। ⛔
                             `hidden` দিলে কীবোর্ডে বাছাই করাই যেত না। --}}
                        <input type="radio" class="peer sr-only"
                               name="papers[{{ $paper['code'] }}][format]"
                               value="{{ $format }}" @checked($paper['format'] === $format)>

                        <span class="block overflow-hidden rounded-(--radius-field) border
                                     border-(--color-border) transition-colors
                                     peer-focus-visible:ring-2 peer-focus-visible:ring-(--color-brand-600)
                                     peer-checked:border-(--color-brand-600)
                                     peer-checked:ring-2 peer-checked:ring-(--color-brand-600)">

                            {{-- ── নমুনা কাগজটা ────────────────────────────
                                 ⓘ ছোট করে দেখানো হয় `scale()` দিয়ে, ছবি বানিয়ে
                                 নয় — ছবি বানাতে হলে সার্ভারে একটা ব্রাউজার
                                 চালাতে হত। ⚠️ `loading="lazy"`, কারণ তেরোটা
                                 রূপের তেরোটা কাগজ একসাথে আনলে ফোনে পাতাটাই
                                 দাঁড়াত না।

                                 ⛔ `sandbox` আছে কিন্তু `allow-scripts` নেই:
                                 নমুনায় কোনো JS চলার দরকার নেই। ⚠️ তবু
                                 `allow-same-origin` লাগে — ওটা ছাড়া অরিজিনটা
                                 অস্বচ্ছ হয়ে যেত আর `img-src 'self'` লোগোটাকেই
                                 আটকাত, ফলে নমুনায় লোগো থাকত না অথচ কাগজে
                                 থাকত। --}}
                            <span class="block h-44 w-full overflow-hidden bg-white">
                                <iframe src="{{ route('system_admin.print_control.preview', [
                                            'paper' => $paper['code'],
                                            'format' => $format,
                                        ]) }}"
                                        title="{{ __('system_admin::settings.print_sample_of', ['format' => $name]) }}"
                                        loading="lazy" tabindex="-1"
                                        sandbox="allow-same-origin"
                                        style="width: 760px; height: 1000px; border: 0;
                                               transform: scale(0.34); transform-origin: top left;
                                               pointer-events: none;"></iframe>
                            </span>

                            <span class="flex items-center justify-between gap-2 border-t
                                         border-(--color-border) px-3 py-2 text-sm">
                                <span>{{ $name }}</span>

                                {{-- ⓘ ছোট করে দেখলে সব পড়া যায় না, তাই পুরো
                                     মাপে খোলার একটা পথ। ⚠️ `target="_blank"`,
                                     কারণ একই পাতায় খুললে না-সংরক্ষিত বাছাইটা
                                     হারাত। --}}
                                <a href="{{ route('system_admin.print_control.preview', [
                                       'paper' => $paper['code'],
                                       'format' => $format,
                                   ]) }}"
                                   target="_blank" rel="noopener"
                                   class="shrink-0 text-xs text-(--color-ink-muted) underline">
                                    {{ __('system_admin::settings.print_sample') }}
                                </a>
                            </span>
                        </span>
                    </label>
                @endforeach
            </div>

            {{-- ⭐ বোতামটা বাছাইয়ের ঠিক নিচে, শুধু পাতার শেষে নয়।

                 ⚠️ তেরোটা নমুনা কার্ডের পর পাতাটা লম্বা, আর নিচের অংশ
                 দুইটা (সুইচ, কলাম) পেরিয়ে তবে সংরক্ষণ। ⛔ যিনি কেবল রূপটা
                 বদলাতে এসেছেন, তিনি বাছাই করে বোতাম না পেয়ে ধরে নিতেন
                 কিছুই হয়নি — ২৯ আগস্টের তিন দিনের অভিযোগটা ঠিক এভাবেই
                 এসেছিল ([[TheSaveButtonWasOffTheScreenTest]])। --}}
            <div class="mt-4">
                <x-ui.button type="submit" tone="primary">{{ __('core.action.save') }}</x-ui.button>
            </div>
        </section>

        {{-- ── কাগজে যা আসবে ──────────────────────────────────── --}}
        <section data-boxed
                 class="rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card) p-4">
            <h2 class="mb-1 text-sm font-semibold">
                {{ __('system_admin::settings.print_parts') }}
            </h2>
            <p class="mb-3 text-xs text-(--color-ink-muted)">
                {{ __('system_admin::settings.print_parts_note') }}
            </p>

            <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                @foreach ($paper['allParts'] as $part)
                    <label class="flex min-h-(--spacing-touch) items-start gap-2 text-sm">
                        <input type="checkbox"
                               name="papers[{{ $paper['code'] }}][parts][{{ $part }}]"
                               value="1" @checked(in_array($part, $paper['parts'], true))
                               class="mt-1 size-4">
                        <span>{{ __('core.print.part.'.$part) }}</span>
                    </label>
                @endforeach
            </div>
        </section>

        {{-- ── কলাম ও ক্রম ────────────────────────────────────── --}}
        <section data-boxed
                 class="rounded-(--radius-card) border border-(--color-border)
                        bg-(--color-surface-card) p-4">
            <h2 class="mb-1 text-sm font-semibold">
                {{ __('system_admin::settings.print_columns') }}
            </h2>
            <p class="mb-3 text-xs text-(--color-ink-muted)">
                {{ __('system_admin::settings.print_columns_note') }}
            </p>

            <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                @foreach ($paper['columns'] as $column)
                    @php $place = array_search($column, $paper['on'], true); @endphp

                    <label class="block">
                        <span class="mb-1 block text-sm">{{ __('core.print.column.'.$column) }}</span>

                        {{-- ⓘ "বন্ধ" আলাদা একটা বিকল্প, শূন্য নয় — ⚠️ শূন্য দিলে
                             কেউ ভাবতেন ওটা "প্রথম", আর কলামটা নীরবে উধাও হত। --}}
                        <select name="papers[{{ $paper['code'] }}][columns][{{ $column }}]"
                                class="h-(--spacing-field) w-full rounded-(--radius-field)
                                       border border-(--color-border)
                                       bg-(--color-surface-card) px-3">
                            <option value="" @selected($place === false)>
                                {{ __('system_admin::settings.print_column_off') }}
                            </option>

                            @foreach (range(1, count($paper['columns'])) as $n)
                                <option value="{{ $n }}"
                                        @selected($place !== false && $place + 1 === $n)>{{ $n }}</option>
                            @endforeach
                        </select>
                    </label>
                @endforeach
            </div>
        </section>

        <div class="flex flex-wrap items-center gap-3">
            <x-ui.button type="submit" tone="primary">{{ __('core.action.save') }}</x-ui.button>

            <a href="{{ route('system_admin.settings') }}"
               class="text-sm text-(--color-ink-muted) underline">
                {{ __('system_admin::settings.title') }}
            </a>
        </div>
    </form>
</x-layouts.app>
