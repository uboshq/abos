{{--
    প্রতিষ্ঠানের সেটিংস — সব মডিউল, এক পর্দায়।

    সুইচগুলো এখানে হাতে লেখা নেই — প্রতিটা মডিউলের module.php যা ঘোষণা
    করে, এই পর্দা তা-ই দেখায় (নিয়ম ৭)। নতুন একটা ঐচ্ছিক ফিল্ড যোগ করার
    সময় তার সুইচটা একই ফাইলে লেখা হয়, আর এই পর্দায় সেটা নিজে থেকেই আসে।

    ── ⚠️ কেন বাঁ পাশে মডিউলের তালিকা, লম্বা এক কলাম নয় ────────────────
    প্রথম খসড়ায় দশটা মডিউল একটার নিচে একটা বসানো ছিল, আর পর্দাটা
    দাঁড়িয়েছিল **৬,০৯৪ পিক্সেল লম্বা**। ⓘ একটা সুইচ বদলাতে দশটা কার্ড
    পেরোতে হত, আর কোনটা কোথায় তা মনে রাখা ছাড়া উপায় ছিল না।

    ⛔ ওটা সেটিংসের একটা তালিকা, সেটিংসের পর্দা নয়। D365 · SAP · Odoo
    তিনটাই একই কাজ করে: বাঁয়ে বিষয়ের তালিকা, ডানে একটা বিষয়।

    ── ⭐ ফর্মটা তবু একটাই ─────────────────────────────────────────────
    ট্যাবগুলো কেবল দেখা-না-দেখা (`hidden`), আলাদা ফর্ম নয় — তাই একবার
    "সংরক্ষণ" চাপলে সব মডিউলের বদল একসাথে বসে। ⚠️ প্রতি ট্যাবে আলাদা
    ফর্ম হলে কেউ দুইটা ট্যাবে বদল করে একবার সেভ করতেন, আর অর্ধেকটা
    নীরবে হারিয়ে যেত।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('system_admin::settings.title') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('system_admin::settings.title')"
                          :subtitle="__('system_admin::settings.note')" />
    </x-slot:header>

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    <form method="POST" action="{{ route('system_admin.settings.update') }}"
          x-data="{ tab: '{{ $modules[0]['code'] ?? '' }}' }">
        @csrf
        @method('PUT')

        <div class="flex flex-col gap-4 md:flex-row md:items-start">
            {{--
                বাঁ পাশের তালিকা — মডিউলের ক্রমটা সাইডবারের ক্রম।

                ⚠️ `md:` -এর নিচে এটা উপরে বসে আর আড়াআড়ি স্ক্রল করে:
                ফোনে ২৪০px চওড়া একটা কলাম কেড়ে নিলে ডান পাশে ঘরগুলোর
                জন্য কিছুই থাকত না।
            --}}
            <nav aria-label="{{ __('system_admin::settings.title') }}"
                 class="-mx-1 flex shrink-0 gap-1 overflow-x-auto px-1 pb-1
                        md:mx-0 md:w-56 md:flex-col md:overflow-visible md:px-0 md:pb-0">
                @foreach ($modules as $module)
                    <button type="button" @click="tab = '{{ $module['code'] }}'"
                            :aria-current="tab === '{{ $module['code'] }}' ? 'page' : null"
                            class="flex min-h-(--spacing-touch) shrink-0 items-center justify-between gap-2
                                   whitespace-nowrap rounded-(--radius-field) px-3 text-sm transition-colors
                                   md:w-full"
                            :class="tab === '{{ $module['code'] }}'
                                ? 'bg-(--color-brand-600) text-(--color-brand-ink) font-medium'
                                : 'text-(--color-ink-muted) hover:bg-(--color-surface-sunken)'">
                        <span>{{ $module['label'] }}</span>

                        {{-- ⓘ সংখ্যাটা আগেই বলে দেয় ভেতরে কতগুলো আছে --}}
                        <span class="num text-2xs opacity-70">{{ $module['count'] }}</span>
                    </button>
                @endforeach
            </nav>

            <div class="min-w-0 flex-1 space-y-4">
                @foreach ($modules as $module)
                    <div x-show="tab === '{{ $module['code'] }}'" x-cloak class="space-y-4">
                        @foreach ($module['groups'] as $group => $settings)
                            <section data-boxed
                                     class="rounded-(--radius-card) border border-(--color-border)
                                            bg-(--color-surface-card) p-4">
                                <h2 class="mb-3 text-sm font-semibold">
                                    {{ __('core.settings_group.' . $group) }}
                                </h2>

                                <div class="space-y-3">
                                    @foreach ($settings as $setting)
                                        @if ($setting['type'] === 'boolean')
                                            <label class="flex min-h-(--spacing-touch) items-start gap-2 text-sm">
                                                <input type="checkbox" name="settings[{{ $setting['key'] }}]"
                                                       value="1" @checked($setting['value'])
                                                       class="mt-1 size-4">
                                                <span>{{ __($setting['label']) }}</span>
                                            </label>
                                        @else
                                            <label class="block">
                                                <span class="mb-1 block text-sm font-medium">
                                                    {{ __($setting['label']) }}
                                                </span>
                                                <input type="{{ $setting['type'] === 'integer' ? 'number' : 'text' }}"
                                                       name="settings[{{ $setting['key'] }}]"
                                                       value="{{ $setting['value'] }}"
                                                       @if ($setting['type'] === 'integer') min="0" inputmode="numeric" @endif
                                                       class="h-(--spacing-field) w-full max-w-40 rounded-(--radius-field)
                                                              border border-(--color-border) bg-(--color-surface-card) px-3
                                                              @if ($setting['type'] === 'integer') num text-end @endif">
                                            </label>
                                        @endif
                                    @endforeach
                                </div>
                            </section>
                        @endforeach
                    </div>
                @endforeach

                <div class="flex flex-wrap items-center gap-3">
                    <x-ui.button type="submit" tone="primary">{{ __('core.action.save') }}</x-ui.button>

                    {{--
                        পর্দা চালু/বন্ধ এখানে নয় — সেখানে যাওয়ার পথটা দেওয়া
                        থাকে, নাহলে মানুষ এই পর্দায় খুঁজে না পেয়ে ভাবতেন
                        সুইচটা নেই।
                    --}}
                    <a href="{{ route('system_admin.control-panel') }}"
                       class="text-sm text-(--color-ink-muted) underline underline-offset-2">
                        {{ __('system_admin::settings.screens_live_in_control_panel') }}
                    </a>
                </div>
            </div>
        </div>
    </form>
</x-layouts.app>
