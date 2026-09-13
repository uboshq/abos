@props(['menu' => []])

{{--
    মডিউল লঞ্চার — টপবারের ৯-ফোঁটার বোতাম আর তার নিচের প্যানেল।

    ── কেন বোতাম ও প্যানেল একই ফাইলে, একই `x-data`-তে ────────────────────
    প্রথম খসড়ায় দুইটা আলাদা ছিল: বোতাম বারে, আর শিটটা পাতার একদম উপরে,
    যোগাযোগ একটা ঘটনা দিয়ে। ⛔ কিন্তু তাতে প্যানেলটা **বোতামের নিচে**
    বসানো যেত না — `absolute` কাজ করে তার নিকটতম `relative` পূর্বপুরুষের
    সাপেক্ষে, আর দুইটা আলাদা ডালে থাকলে সেই সম্পর্কটাই নেই।

    ⓘ মালিকের দেওয়া ছবিতে প্যানেলটা বোতামের ঠিক নিচে ঝোলে, পুরো পর্দা
    ঢাকে না — তাই দুইটা এখন এক বাক্সে, `create-menu`-র মতোই।

    ── কেন পুরো-পর্দার শিট নয় ───────────────────────────────────────────
    Odoo পুরো পর্দা ঢাকে, আর সেটারও যুক্তি আছে। ⚠️ কিন্তু মালিকের চাওয়া
    গড়নটা আলাদা: পিছনের পাতাটা দেখা যেতে থাকে, তাই মডিউল বদলানো একটা
    **ছোট সিদ্ধান্ত** মনে হয় — পর্দা বদলে যাওয়া নয়। যেটা দিনে দশবার
    লাগে, সেটার জন্য পুরো পর্দা নেওয়া বেশি।
--}}
<div x-data="{ open: false }" class="relative shrink-0">
    <button type="button"
            data-module-launcher
            @click="open = ! open" @click.outside="open = false"
            @keydown.escape.window="open = false"
            :aria-expanded="open.toString()"
            class="grid size-9 shrink-0 place-items-center rounded-(--radius-field)
                   text-(--color-topbar-ink) transition-colors hover:bg-(--color-topbar-hover)"
            aria-label="{{ __('core.ui.launcher') }}"
            title="{{ __('core.ui.launcher') }}">
        {{-- চারটা বর্গ — ছবির বোতামটার মতোই। ⓘ ৯ ফোঁটা Odoo-র চিহ্ন;
             এটা ABOS-এর নিজের, তাই আলাদা দেখতে হওয়াই ঠিক। --}}
        <span class="grid grid-cols-2 gap-[3px]" aria-hidden="true">
            @for ($i = 0; $i < 4; $i++)
                <span class="block size-[6px] rounded-[2px] border-2 border-current"></span>
            @endfor
        </span>
    </button>

    {{--
        প্যানেলটা — তিন কলাম, আর **স্ক্রলবার ছাড়া**।

        ── কেন `max-h` ও `overflow` তুলে দেওয়া হলো ────────────────────
        প্রথম খসড়ায় `max-h-[70vh] overflow-y-auto` ছিল, আর তাতে ১৪টা
        মডিউলের জন্যও একটা স্ক্রলবার বসত। ⛔ মালিকের নির্দেশ: স্ক্রলবার
        যাতে না লাগে সেভাবে সাজাতে।

        ⓘ যুক্তিটাও তাঁর দিকে: লঞ্চারের পুরো কাজই **এক নজরে সবটা
        দেখা**। যে তালিকা গোটাতে হয়, সেটা তালিকা নয় — খোঁজা। আর
        তিন কলামে ১৪টা মানে পাঁচ সারি, যা যেকোনো ল্যাপটপের পর্দাতেই ধরে।

        ⚠️ সীমাটা সৎভাবে বলে রাখি: মডিউল যদি একুশের বেশি হয়ে যায়, তখন
        প্যানেলটা ছোট পর্দায় নিচ দিয়ে বেরোতে পারে। সেদিন কলাম বাড়াতে
        হবে (চার), স্ক্রলবার ফেরানো নয়।

        `end-0` — বোতামটা টপবারের ডান দিকে, তাই প্যানেল ডান ধার ধরে ঝোলে;
        বাঁ ধার ধরলে চওড়া প্যানেলটা পর্দার বাইরে চলে যেত।
    --}}
    <div x-show="open" x-cloak x-transition.opacity.duration.120ms
         class="absolute end-0 top-full z-40 mt-2 w-80
                rounded-(--radius-card) border border-(--color-border)
                bg-(--color-surface-card) p-2 shadow-lg"
         role="menu"
         aria-label="{{ __('core.ui.launcher') }}">

        <div class="grid grid-cols-3 gap-2">
            @foreach ($menu as $module)
                @php
                    /* মডিউলের প্রথম **খোলা** পর্দা — `url` ছাড়া সারি বাদ,
                       কারণ ওগুলো এখনো তৈরি হয়নি (`planned`) আর টালিতে
                       চাপলে কিছুই হত না। */
                    $first = collect($module['groups'])->flatten(1)->firstWhere('url', '!==', null);
                @endphp

                @continue ($first === null)

                <a href="{{ $first['url'] }}" role="menuitem"
                   class="flex flex-col items-center justify-start gap-1 rounded-(--radius-card)
                          border border-(--color-border) bg-(--color-surface-app) px-1 pt-2 pb-2.5
                          text-center transition-colors
                          hover:border-(--color-brand-600) hover:bg-(--color-surface-muted)">
                    <span class="grid size-8 shrink-0 place-items-center rounded-(--radius-card)
                                 bg-(--color-surface-muted) text-(--color-brand-600)">
                        <x-ui.icon :name="$module['icon']" :size="18" />
                    </span>

                    {{--
                        ⚠️ `leading-tight` এখানে চলে না — **বাংলার জন্য**।

                        ⓘ প্রথম খসড়ায় ওটাই ছিল, আর নামগুলোর নিচের অংশ কাটা
                        পড়ছিল ("হিসাব", "অর্থ", "সরবরাহকারী")। কারণ বাংলা
                        অক্ষরের নিচে যুক্তাক্ষর ও কার বসে (ু, ৃ, ্র), আর
                        ১.২৫ লাইন-উচ্চতায় ওগুলোর জায়গা থাকে না।

                        ⓘ ল্যাটিন লেখায় এটা চোখে পড়ত না — ইংরেজিতে নিচে
                        নামে কেবল g, j, p, q, y। তাই ঘনত্ব বাড়াতে গিয়ে
                        ভুলটা করা সহজ, আর ধরা পড়ে কেবল বাংলায়।

                        `break-words` — "নিয়ন্ত্রণ ও নিরীক্ষা"-র মতো লম্বা
                        নাম দুই লাইনে ভাঙবে, টালির বাইরে যাবে না।
                    --}}
                    <span class="w-full break-words text-2xs leading-normal text-(--color-ink-body)">{{ $module['label'] }}</span>
                </a>
            @endforeach
        </div>
    </div>
</div>
