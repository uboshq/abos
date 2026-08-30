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

    {{-- ── ট্যাব, ৩০ আগস্ট ২০২৬ ────────────────────────────────────────
         মালিকের কথা: "এত লম্বা জিনিস Ctrl+F কইরা খুঁইজা বাইর করতে হবে না,
         প্রত্যেকটা জিনিস আলাদা আলাদা রাখ।"

         তিপ্পান্নটা সুইচ এক পাতায় ছিল, আর এখন যোগ হচ্ছে একশোর বেশি মেনু
         সারি। এক পাতায় দেড়শো সুইচ মানে কেউ কিছু খুঁজে পায় না, তাই কেউ
         কিছু বদলায়ও না — জিনিসটা থাকা আর না থাকা সমান।

         ট্যাবগুলো লিংক, JS নয়: প্রতিটার নিজের ঠিকানা থাকে, তাই কেউ
         "মজুদের সুইচগুলো" বুকমার্ক করে রাখতে পারেন, আর ফিরে গেলে একই
         জায়গায় ফেরে। --}}
    <nav class="mb-4 flex flex-wrap gap-1 border-b border-(--color-border) print-hide"
         aria-label="{{ __('system_admin::menu.control_panel') }}">
        @foreach ($tabs as $one)
            <a href="{{ route('system_admin.control-panel', ['tab' => $one['key']]) }}"
               @class([
                   'min-h-(--spacing-touch) rounded-t-(--radius-field) px-3 py-2 text-sm transition-colors',
                   'border-b-2 border-(--color-brand-600) font-semibold' => $tab === $one['key'],
                   'text-(--color-ink-muted) hover:bg-(--color-surface-hover)' => $tab !== $one['key'],
               ])
               @if ($tab === $one['key']) aria-current="page" @endif>
                {{ $one['label'] }}
            </a>
        @endforeach
    </nav>

    {{-- খালি-অবস্থা এখন গাছটাও হিসেবে ধরে।

         আগে শর্তটা ছিল শুধু `$modules === []` — অর্থাৎ কোনো মডিউল
         সেটিং ঘোষণা না করলে গোটা ফর্মটাই, মেনুর সুইচসহ, উধাও হয়ে যেত।
         আজ কোনো না কোনো মডিউল সেটিং ঘোষণা করে বলে সেটা ধরা পড়ত না,
         আর একদিন কেউ শেষ ঘোষণাটা সরালে পুরো পর্দাটা খালি দেখাত। --}}
    @if ($modules === [] && $tree === [])
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
                  /*
                   * কোন সুইচ এখন চালু — কেবল দেখানোর জন্য।
                   *
                   * মডিউল বা সাবমডিউল বন্ধ করলে ভেতরেরগুলো সাথে সাথে
                   * ম্লান হয়ে যায়, সেভ করার আগেই। নাহলে ব্যবহারকারী
                   * মডিউল বন্ধ করে ভেতরের একটা সারি চালু দেখে ভাবতেন
                   * ওটা তবু কাজ করবে — অথচ সার্ভার উপরের স্তরটাই
                   * আগে দেখে ([[MenuSwitches::itemIsOn()]])।
                   */
                  on: {{ Js::from($switchState ?? []) }},
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

            {{-- মেনুর সুইচ — প্রথম ট্যাবে মডিউলগুলো, মডিউলের ট্যাবে তার
                 নিজের গ্রুপ ও সারি। কোনটা কতটুকু সেটা কন্ট্রোলার ঠিক করে
                 ([[ControlPanelController::treeFor()]]); ব্লেড যা পেয়েছে
                 তা-ই আঁকে। --}}
            @include($tab === 'switches'
                ? 'system_admin::control-panel.partials.modules'
                : 'system_admin::control-panel.partials.tree')

            @foreach ($modules as $module)
                @continue ($tab !== $module['code'])

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
                                        {{-- ফর্মটা নিজে বলে দেয় সে কোন সুইচগুলো বহন করছে।

                                             ── কেন লাগল, ৩০ আগস্ট ২০২৬ ────────────────────
                                             সার্ভার "চেকবক্স অনুপস্থিত" মানে "বন্ধ" ধরে, আর ট্যাব
                                             আসার পর একটা পাঠানোয় কেবল একটা ট্যাবের ঘর থাকে।
                                             এই লাইনটা ছাড়া হিসাব ট্যাব সংরক্ষণ করলেই অন্য ছয়
                                             মডিউলের ৩৪টা সেটিং নীরবে বন্ধ হয়ে যেত — ৩০ আগস্ট
                                             ব্রাউজারে সত্যিই একবার হয়েছিল। --}}
                                        <input type="hidden" name="scope[]" value="{{ $setting['key'] }}">

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
