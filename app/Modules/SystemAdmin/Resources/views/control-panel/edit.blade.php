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
        {{--
            তিপ্পান্নটা সুইচ, আর সংরক্ষণ বোতামটা পর্দার বাইরে।

            ── কেন এই পটিটা লাগল, ২৯ আগস্ট ২০২৬ ────────────────────────
            মালিক তিন দিন ধরে বলেছিলেন চেহারার পাতায় "থিম বদলায় না",
            আর আসল কারণ ছিল দুই ধাপ: বাছাই, তারপর নিচে নেমে সংরক্ষণ
            খোঁজা। ওটা সারানোর পর গোটা পণ্যে একই রোগ আর কোথায় আছে তা
            মেপে দেখা হলো — ১২৭টা পর্দার মধ্যে দুইটা, আর তার একটা এই।

            এখানে ৫৩টা সুইচ, আর শেষ সুইচ থেকে সংরক্ষণ বোতাম **৫৫৯px
            নিচে** — অর্থাৎ যে সুইচটা টিপছেন, সেখান থেকে বোতামটা দেখাই
            যায় না।

            ── তবু ক্লিকে-সংরক্ষণ কেন নয় ───────────────────────────────
            চেহারার পাতায় প্রতিটা বাছাই ক্লিকেই বসে, আর সেটাই ঠিক:
            ওখানে একটা বাছাই একটা সিদ্ধান্ত।

            এখানে নয়। সেটিংস দলবেঁধে চলে — "ভ্যাট চালু" আর "ভ্যাটের
            হার" একসাথে বদলাতে হয়, আর মাঝপথে সেভ হয়ে গেলে কিছুক্ষণের
            জন্য কোম্পানির হিসাব ভুল থাকত। তাই বদলগুলো জমে, আর পটিটা
            গুনে বলে কয়টা জমেছে।
        --}}
        <form method="POST" action="{{ route('system_admin.control-panel.update') }}"
              x-data="{
                  changed: {},
                  get count() { return Object.keys(this.changed).length; },
                  touch(el) {
                      const now = el.type === 'checkbox' ? (el.checked ? '1' : '') : el.value;
                      if (now === el.dataset.was) { delete this.changed[el.name]; }
                      else { this.changed[el.name] = true; }
                  },
              }"
              @change="touch($event.target)"
              class="max-w-3xl space-y-4 pb-20">
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
                                                       data-was="{{ $setting['value'] ? '1' : '' }}"
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
                                                        data-was="{{ $setting['value'] }}"
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

            {{-- বদল না হলে পটিটা নেই। "সংরক্ষণ" লেখা একটা পটি সবসময়
                 ভেসে থাকলে ওটা আসবাব হয়ে যায়, আর কেউ পড়ে না। --}}
            <div x-show="count > 0" x-cloak
                 class="fixed inset-x-0 bottom-(--spacing-bottom-nav) z-40 border-t border-(--color-border)
                        bg-(--color-surface-card) px-4 py-3 shadow-lg md:bottom-0">
                <div class="mx-auto flex max-w-3xl items-center gap-3">
                    <span class="text-sm">
                        <span class="num font-semibold" x-text="count"></span>
                        {{ __('system_admin::message.unsaved') }}
                    </span>

                    <span class="flex-1"></span>

                    {{-- ফিরিয়ে দেওয়া মানে পাতাটা আবার আনা: সার্ভারের
                         মানটাই সত্যি, আর হাতে ফেরত বসাতে গেলে
                         দুইজায়গায় দুই হিসাব থাকত। --}}
                    <x-ui.button type="button" tone="secondary" x-data
                                 @click="window.location.reload()">
                        {{ __('core.action.discard') }}
                    </x-ui.button>

                    <x-ui.button type="submit" tone="primary">{{ __('core.action.save') }}</x-ui.button>
                </div>
            </div>

            <x-ui.button type="submit" tone="primary">{{ __('core.action.save') }}</x-ui.button>
        </form>
    @endif
</x-layouts.app>
