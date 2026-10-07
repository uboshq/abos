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

        <div class="flex items-center gap-3 border-t border-(--color-border) px-4 py-3">
            <x-ui.button type="submit" tone="primary">{{ __('core.action.save') }}</x-ui.button>

            {{-- ⓘ বন্ধ করলে কী হয় সেটা লেখা থাকে: সারিটা ঘণ্টায় আসে না,
                 কিন্তু কাজটা তবু অপেক্ষায় থাকে — দুইটা এক জিনিস নয় --}}
            <p class="text-2xs text-(--color-ink-muted)">{{ __('core.notify.settings_warning') }}</p>
        </div>
    </form>
</x-layouts.app>
