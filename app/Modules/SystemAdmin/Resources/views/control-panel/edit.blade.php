{{--
    Control Panel — সব মডিউলের সুইচ এক জায়গায়।

    প্রতিটা মডিউলের নিজের সেটিংস পর্দাও আছে, আর সেটাই রোজকার জায়গা।
    এই পর্দাটা নতুন কোম্পানি চালু করার দিনের জন্য: একবার বসে পুরো
    সিস্টেমটা নিজের ব্যবসার মতো করে নেওয়া, আটটা পর্দা না ঘুরে।

    কোনো মডিউলের নাম এখানে লেখা নেই — মডিউল যা ঘোষণা করে সেটাই দেখায়।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('system_admin::menu.control_panel') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('system_admin::menu.control_panel')"
                          :subtitle="__('system_admin::message.control_panel_note')" />
    </x-slot:header>

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    @if ($modules === [])
        <x-ui.empty-state :message="__('system_admin::message.no_switches')" />
    @else
        <form method="POST" action="{{ route('system_admin.control-panel.update') }}"
              class="max-w-3xl space-y-4">
            @csrf
            @method('PUT')

            @foreach ($modules as $module)
                <section data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                                bg-(--color-surface-card)">
                    <h2 class="border-b border-(--color-border) bg-(--color-section-head) px-4 py-3 font-semibold">
                        {{ $module['label'] }}
                    </h2>

                    <div class="divide-y divide-(--color-border)">
                        @foreach ($module['groups'] as $group => $settings)
                            <div class="p-4">
                                {{-- গ্রুপের নাম মডিউলের নিজের অনুবাদ থেকে।
                                     না থাকলে অনুবাদের কী-টাই ফেরত আসে, আর
                                     তখন একটা সাধারণ নাম দেখানো হয় — কাঁচা
                                     কী পর্দায় দেখানোর চেয়ে ভালো। --}}
                                @php
                                    $groupKey = $module['code'] . '::settings_group.' . $group;
                                    $groupLabel = __($groupKey);

                                    if ($groupLabel === $groupKey) {
                                        $groupLabel = __('system_admin::settings_group.' . $group);
                                    }

                                    if (str_contains($groupLabel, '::')) {
                                        $groupLabel = ucfirst($group);
                                    }
                                @endphp

                                <h3 class="mb-3 text-2xs font-semibold uppercase tracking-wide
                                           text-(--color-ink-muted)">
                                    {{ $groupLabel }}
                                </h3>

                                <div class="space-y-3">
                                    @foreach ($settings as $setting)
                                        @if ($setting['type'] === 'boolean')
                                            <label class="flex min-h-(--spacing-touch) items-start gap-2 text-sm">
                                                <input type="checkbox" name="settings[{{ $setting['key'] }}]"
                                                       value="1" @checked($setting['value'])
                                                       class="mt-1 size-4">
                                                <span>{{ __($setting['label']) }}</span>
                                            </label>
                                        @elseif (! empty($setting['options']))
                                            {{--
                                                বাছাইয়ের তালিকা — লেখার ঘর নয়।

                                                তারিখের ছকের মতো সেটিংসে যা লেখা
                                                থাকে সেটা সরাসরি PHP-র format()-এ
                                                যায়। লেখার ঘর রাখলে একটা টাইপো
                                                প্রতিটা তারিখকে আবর্জনা করে ছাপাত,
                                                আর ছাপা কাগজ আর ফেরানো যায় না।

                                                পাশে নমুনা থাকে (১৮/০২/২০২৬), কারণ
                                                "d/m/Y" পড়ে কেউ বলতে পারে না কী
                                                দেখাবে — আর ঠিক ওই না-বোঝাটাই
                                                ভুল ছক বাছার কারণ।
                                            --}}
                                            <label class="block">
                                                <span class="mb-1 block text-sm font-medium">
                                                    {{ __($setting['label']) }}
                                                </span>
                                                <select name="settings[{{ $setting['key'] }}]"
                                                        class="h-(--spacing-field) w-full max-w-56 rounded-(--radius-field)
                                                               border border-(--color-border) bg-(--color-surface-card) px-3">
                                                    @foreach (is_callable($setting['options']) ? call_user_func($setting['options']) : $setting['options'] as $value => $sample)
                                                        <option value="{{ $value }}"
                                                                @selected((string) $setting['value'] === (string) $value)>
                                                            {{ $sample }}
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
                                                       @class([
                                                           'h-(--spacing-field) w-full max-w-40 rounded-(--radius-field)',
                                                           'border border-(--color-border) bg-(--color-surface-card) px-3',
                                                           'num text-end' => $setting['type'] === 'integer',
                                                       ])>
                                            </label>
                                        @endif
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                </section>
            @endforeach

            <x-ui.button type="submit" tone="primary">{{ __('core.action.save') }}</x-ui.button>
        </form>
    @endif
</x-layouts.app>
