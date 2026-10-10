{{--
    বিজ্ঞপ্তির সেটিংস — কে কোন খবর পেতে চান।

    ── কেন সব ধরন দেখানো হয়, কেবল চালুগুলো নয় ─────────────────────────
    যে খবর এখনো কেউ পাননি, সেটাও তালিকায় থাকে — নাহলে আগেভাগে বন্ধ করার
    উপায় থাকত না, আর প্রথমবার সেটা এসেই পড়ত।

    ⓘ সুইচগুলো একটা ফর্মে, নিচে একটাই "সংরক্ষণ" — প্রতিটা টিকে আলাদা
    অনুরোধ পাঠালে অর্ধেক বদল সংরক্ষিত আর অর্ধেক নয়, এমন অবস্থা হত।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('core.notify.settings_title') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('core.notify.settings_title')"
                          :subtitle="__('core.notify.settings_note')" />
    </x-slot:header>

    @if (session('saved'))
        <p role="status" class="mb-3 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2
                                text-sm text-(--color-badge-success-ink)">{{ session('saved') }}</p>
    @endif

    <form method="POST" action="{{ route('notifications.settings.update') }}"
          data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                            bg-(--color-surface-card)">
        @csrf
        @method('PUT')

        {{--
            ⚠️ চিঠি আদৌ বাইরে যাবে কি না — সেটা এই পর্দার সবচেয়ে সৎ লাইন।

            ⓘ `MAIL_MAILER=log` বসানো থাকলে টিকগুলো কাজ করে, সংরক্ষণও হয়,
            আর **একটা চিঠিও যায় না**। ⛔ কথাটা না লিখলে মানুষ টিক দিয়ে
            ইনবক্স খুলে বসে থাকতেন — ঠিক যে আকৃতিটা [[MailReach]] ঠেকাতে
            বানানো।
        --}}
        @unless ($postable)
            <p role="status" class="border-b border-(--color-border) bg-(--color-badge-warning-bg)
                                    px-4 py-3 text-2xs text-(--color-badge-warning-ink)">
                {{ __('core.notify.mail_silent') }}
            </p>
        @endunless

        <ul class="divide-y divide-(--color-border)">
            @foreach ($kinds as $type => $label)
                @php $on = ! in_array($type, $silenced, true); @endphp

                {{--
                    ⓘ প্রতিটা সারির নিজের ছোট অবস্থা: ঘণ্টা বন্ধ করলে চিঠির
                    টিকটাও সঙ্গে সঙ্গে নিভে যায়।

                    ⚠️ CSP-র কারণে `onchange=` চলে না (`script-src 'self'`),
                    তাই Alpine। ⭐ আর এখানে একটাও `x-model`/`x-bind` নেই,
                    ইচ্ছাকৃতভাবে: `x-model` বসালে Alpine চালু হওয়ার সময়
                    ⛔ **সার্ভারের বসানো `@checked` মুছে দিত** নিজের শুরুর
                    মান দিয়ে, আর সেটা হত নীরব — পর্দা খুলেই সব টিক উল্টে
                    যেত, অথচ কেউ কিছু ছোঁয়নি।

                    ⓘ তাই কম্পোনেন্ট নিজেই DOM থেকে পড়ে, আর HTML-টাই
                    সত্যের একমাত্র উৎস থাকে।
                --}}
                <li x-data="notifyRow" class="flex items-start gap-3 px-4 py-3">
                    <input type="checkbox" id="kind-{{ $loop->index }}" name="kinds[]" value="{{ $type }}"
                           @checked($on) data-notify-bell
                           class="mt-1 size-4 shrink-0 rounded-(--radius-field) border-(--color-border)">

                    <label for="kind-{{ $loop->index }}" class="min-w-0 flex-1">
                        <span class="font-medium">{{ __($label) }}</span>
                        <span class="mt-0.5 block text-2xs text-(--color-ink-muted)">
                            {{ __($label.'_note') }}
                        </span>
                    </label>

                    {{--
                        ⭐ দ্বিতীয় দিক: এটা আমার ইনবক্সেও যাক।

                        ⓘ ডানদিকে, আলাদা করে — কারণ প্রশ্ন দুইটা আলাদা, আর
                        একটার উত্তর অন্যটার উত্তর নয়। ⚠️ একই টিকে দুইটা
                        কাজ করালে "ঘণ্টায় চাই কিন্তু চিঠিতে নয়" বলার কোনো
                        উপায়ই থাকত না — অথচ ওটাই সবচেয়ে সাধারণ চাওয়া।
                    --}}
                    <label data-notify-mail-label
                           class="flex shrink-0 items-center gap-1.5 text-2xs text-(--color-ink-muted)">
                        <input type="checkbox" name="mailed[]" value="{{ $type }}"
                               @checked($mailed[$type]) data-notify-mail
                               class="size-4 rounded-(--radius-field) border-(--color-border)">
                        {{ __('core.notify.by_email') }}
                    </label>
                </li>
            @endforeach
        </ul>

        {{--
            ⭐ কীভাবে আর কখন পাবেন — মাধ্যম, চুপ করা শ্রেণি, ঘনত্ব, নীরব সময় (মালিকের স্পেক §৪ "Preferences", §১৪;
            বিজ্ঞপ্তি ব্যবস্থাপনা, ধাপ ৩)। একই ফর্মে, একই "সংরক্ষণ" — অর্ধেক বদল সংরক্ষিত হওয়ার ভয় নেই।
            ⛔ জরুরি খবর নীরব সময়েও আসে, চুপও হয় না — লেখা থাকে, যাতে কেউ ভুল না বোঝেন।
        --}}
        <div data-notify-preferences class="space-y-3 border-t border-(--color-border) px-4 py-4">
            <div>
                <p class="font-medium">{{ __('core.notify.pref_title') }}</p>
                <p class="text-2xs text-(--color-ink-muted)">{{ __('core.notify.pref_note') }}</p>
            </div>

            <div class="grid gap-4 md:grid-cols-3">
                <fieldset class="space-y-1">
                    <legend class="text-2xs text-(--color-ink-muted)">{{ __('core.notify.pref_channels') }}</legend>
                    @foreach (\App\Models\NotificationChannel::ALL as $channel)
                        <label class="flex items-center gap-2 text-sm">
                            <input type="checkbox" name="pref[channels][]" value="{{ $channel }}" @checked($pref->allowsChannel($channel))>
                            {{ __('core.notify.channel_name.'.$channel) }}
                        </label>
                    @endforeach
                </fieldset>

                <fieldset class="space-y-1">
                    <legend class="text-2xs text-(--color-ink-muted)">{{ __('core.notify.pref_muted') }}</legend>
                    @foreach (\App\Core\Support\NotificationKinds::CATEGORIES as $category)
                        <label class="flex items-center gap-2 text-sm">
                            <input type="checkbox" name="pref[muted][]" value="{{ $category }}" @checked($pref->mutes($category))>
                            {{ __('core.notify.category.'.$category) }}
                        </label>
                    @endforeach
                </fieldset>

                <div class="space-y-2">
                    <label class="grid gap-1 text-2xs text-(--color-ink-muted)">
                        {{ __('core.notify.pref_frequency') }}
                        <select name="pref[frequency]" class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-sm">
                            @foreach (\App\Models\NotificationPreference::FREQUENCIES as $frequency)
                                <option value="{{ $frequency }}" @selected($pref->frequency === $frequency)>{{ __('core.notify.frequency.'.$frequency) }}</option>
                            @endforeach
                        </select>
                    </label>
                    <div class="grid grid-cols-2 gap-2">
                        <label class="grid gap-1 text-2xs text-(--color-ink-muted)">
                            {{ __('core.notify.pref_digest_hour') }}
                            <select name="pref[digest_hour]" class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-sm">
                                @for ($h = 0; $h < 24; $h++)
                                    <option value="{{ $h }}" @selected((int) $pref->digest_hour === $h)>{{ sprintf('%02d:00', $h) }}</option>
                                @endfor
                            </select>
                        </label>
                        <label class="grid gap-1 text-2xs text-(--color-ink-muted)">
                            {{ __('core.notify.pref_digest_day') }}
                            <select name="pref[digest_day]" class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-sm">
                                @for ($d = 0; $d < 7; $d++)
                                    <option value="{{ $d }}" @selected((int) $pref->digest_day === $d)>{{ __('core.notify.days.'.$d) }}</option>
                                @endfor
                            </select>
                        </label>
                    </div>
                </div>
            </div>

            <div class="grid gap-3 md:grid-cols-4">
                <label class="flex items-center gap-2 self-end text-sm">
                    <input type="hidden" name="pref[quiet_enabled]" value="0">
                    <input type="checkbox" name="pref[quiet_enabled]" value="1" @checked($pref->quiet_enabled)>
                    {{ __('core.notify.pref_quiet_on') }}
                </label>
                <label class="grid gap-1 text-2xs text-(--color-ink-muted)">
                    {{ __('core.notify.pref_quiet_start') }}
                    <input type="time" name="pref[quiet_start]" value="{{ substr((string) $pref->quiet_start, 0, 5) }}"
                           class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-sm">
                </label>
                <label class="grid gap-1 text-2xs text-(--color-ink-muted)">
                    {{ __('core.notify.pref_quiet_end') }}
                    <input type="time" name="pref[quiet_end]" value="{{ substr((string) $pref->quiet_end, 0, 5) }}"
                           class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-sm">
                </label>
                <label class="grid gap-1 text-2xs text-(--color-ink-muted)">
                    {{ __('core.notify.pref_timezone') }}
                    <select name="pref[timezone]" class="h-(--spacing-field) rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-card) px-2 text-sm">
                        <option value="">{{ __('core.notify.pref_timezone_company') }}</option>
                        @foreach (\App\Models\NotificationPreference::ZONES as $zone)
                            <option value="{{ $zone }}" @selected($pref->timezone === $zone)>{{ $zone }}</option>
                        @endforeach
                    </select>
                </label>
            </div>
            <p class="text-2xs text-(--color-ink-muted)">{{ __('core.notify.pref_critical') }}</p>
        </div>

        <div class="flex items-center gap-3 border-t border-(--color-border) px-4 py-3">
            <x-ui.button type="submit" tone="primary">{{ __('core.action.save') }}</x-ui.button>

            {{-- ⓘ বন্ধ করলে কী হয় সেটা লেখা থাকে: সারিটা ঘণ্টায় আসে না,
                 কিন্তু কাজটা তবু অপেক্ষায় থাকে — দুইটা এক জিনিস নয় --}}
            <p class="text-2xs text-(--color-ink-muted)">{{ __('core.notify.settings_warning') }}</p>
        </div>
    </form>

    {{--
        ⭐ এই ব্রাউজারে Web Push — বিজ্ঞপ্তি ব্যবস্থাপনা, ধাপ ২ (মালিকের স্পেক §৭)।
        ⓘ মাধ্যম সংযুক্ত হলে (প্রশাসক VAPID চাবি বানালে) তবেই; জরুরি আর বেশি গুরুত্বের খবর ব্রাউজার বন্ধ থাকলেও আসে।
        অনুমতি ব্রাউজার নিজে চায়। ⓘ ফর্মটা কেবল CSRF টোকেনের জন্য — পাঠায় কম্পোনেন্ট।
    --}}
    @if ($pushKey)
        <form x-data="pushToggle" data-push-toggle data-key="{{ $pushKey }}"
              data-subscribe-url="{{ route('notifications.push.subscribe') }}"
              data-unsubscribe-url="{{ route('notifications.push.unsubscribe') }}"
              class="mt-4 flex flex-wrap items-center gap-3 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) px-4 py-3">
            @csrf
            <div class="min-w-0 flex-1">
                <p class="text-sm font-medium">{{ __('core.notify.push_title') }}</p>
                <p class="text-2xs text-(--color-ink-muted)">{{ __('core.notify.push_note') }}</p>
                <p x-show="failed" x-cloak class="text-2xs text-(--color-danger)">{{ __('core.notify.push_failed') }}</p>
            </div>
            <button type="button" x-show="canUse()" @click="toggle" :disabled="busy"
                    class="rounded-(--radius-field) border border-(--color-border) px-3 py-1.5 text-sm hover:bg-(--color-surface-hover)">
                <span x-show="isOn()" x-cloak>{{ __('core.notify.push_off') }}</span>
                <span x-show="! isOn()">{{ __('core.notify.push_on') }}</span>
            </button>
            <p x-show="! canUse()" x-cloak class="text-2xs text-(--color-ink-muted)">{{ __('core.notify.push_unsupported') }}</p>
        </form>
    @endif
</x-layouts.app>
