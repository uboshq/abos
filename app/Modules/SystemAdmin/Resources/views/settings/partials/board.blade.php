{{-- ⭐ মডিউল ধরে সব সেটিং, এক ফর্মে — প্রতিষ্ঠানের সেটিংসের পাতা আর কন্ট্রোল প্যানেলের নিচে একই অংশ (কন্ট্রোল প্যানেলের পরিকল্পনা, দফা ২ — "মডিউলের সেটিংস দুই পাতায় → \"মডিউল ও সেটিংস\"-এ একটাই পাতা"; মালিক, ৭ নভেম্বর ২০২৬: "baki kaj gulo kore rako") --}}
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
                                                {{-- ⛔ কেবল সুপার অ্যাডমিনের সুইচ — পাহারা সার্ভারে, এখানে কেবল দেখানো --}}
                                                @php($locked = ! $settingsGate->mayChange($setting['key'], auth()->user()))
                                                <input type="checkbox" name="settings[{{ $setting['key'] }}]"
                                                       value="1" @checked($setting['value']) @disabled($locked)
                                                       class="mt-1 size-4">
                                                <span>
                                                    {{ __($setting['label']) }}
                                                    @if ($locked)
                                                        <span class="block text-xs text-(--color-ink-muted)">{{ __('system_admin::settings.super_admin_only') }}</span>
                                                    @endif
                                                </span>
                                            </label>
                                        @elseif ($setting['type'] === 'choice')
                                            {{-- ⭐ বাছাইয়ের ঘর — ছাপার কাগজের মাপের জন্য (২০ সেপ্টেম্বর ২০২৬)।

                                                 ⓘ মালিক চেয়েছেন কোন কাগজে ছাপা হবে সেটা তিনি ঠিক করে
                                                 দেবেন। ⚠️ লেখার ঘরে ছাড়লে কেউ "৮০" লিখতেন আর সেটা
                                                 চুপচাপ A4 হয়ে যেত — তিনটা মাপের বাইরে কিছু বৈধ নয়। --}}
                                            <label class="block">
                                                <span class="mb-1 block text-sm font-medium">
                                                    {{ __($setting['label']) }}
                                                </span>
                                                <select name="settings[{{ $setting['key'] }}]"
                                                        class="h-(--spacing-field) w-full max-w-40 rounded-(--radius-field)
                                                               border border-(--color-border) bg-(--color-surface-card) px-3">
                                                    {{-- ⛔ নামটা এখানে হাতে লেখা `PaperSize::of()` দিয়ে আসত —
                                                         ২২ সেপ্টেম্বর ২০২৬-এ ধরা পড়েছে।

                                                         ⚠️ অর্থাৎ **কাগজের মাপ ছাড়া অন্য কোনো বাছাই যোগ করলেই**
                                                         `PaperSize::of()` একটা `InvalidArgumentException` ছুঁড়ত,
                                                         আর গোটা সেটিংস পাতাটা ৫০০ দিত — ⓘ যে সুইচটা যোগ করা
                                                         হলো তার সাথে যার কোনো সম্পর্ক নেই, এমন সব সুইচও।

                                                         ⭐ এখন ঘোষণাটাই বলে দেয় নামগুলো কোথায়: `option_label`
                                                         একটা অনুবাদের উপসর্গ। ⓘ না থাকলে পুরনো আচরণ, তাই
                                                         কাগজের মাপের সারিগুলো একটুও বদলায়নি। --}}
                                                    @foreach ($setting['options'] ?? [] as $option)
                                                        <option value="{{ $option }}"
                                                                @selected((string) $setting['value'] === (string) $option)>
                                                            {{ isset($setting['option_label'])
                                                                ? __($setting['option_label'].$option)
                                                                : \App\Core\Engines\Print\PaperSize::of($option)->label() }}
                                                        </option>
                                                    @endforeach
                                                </select>
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
                    @unless ($onePage ?? false)
                    <a href="{{ route('system_admin.control-panel') }}"
                       class="text-sm text-(--color-ink-muted) underline underline-offset-2">
                        {{ __('system_admin::settings.screens_live_in_control_panel') }}
                    </a>
                    @endunless
                </div>
            </div>
        </div>
    </form>
