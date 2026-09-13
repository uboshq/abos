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
         class="absolute end-0 top-full z-40 mt-2 w-[42rem] max-w-[calc(100vw-2rem)]
                rounded-(--radius-card) border border-(--color-border)
                bg-(--color-surface-card) p-3 shadow-lg"
         role="menu"
         aria-label="{{ __('core.ui.launcher') }}">

        {{--
            WARN: কলামের সংখ্যা **জায়গা অনুযায়ী**, স্থির নয়।

            আগে `grid-cols-4` স্থির ছিল, আর তাতে সরু জায়গায় — ব্রাউজার
            জুম করা থাকলে, বা ছোট জানালায় — টালিগুলো এত সরু হয়ে যেত যে
            নাম বাক্সের বাইরে উপচে পড়ত। উপরের `max-w` প্যানেলটাকে ছোট
            করত, কিন্তু কলাম চারটাই থেকে যেত।

            জুম করা পর্দা অস্বাভাবিক কিছু নয় — যিনি সারাদিন সংখ্যা পড়েন
            তিনি প্রায়ই বড় করে রাখেন।
        --}}
        <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-4">
            @foreach ($menu as $module)
                @php
                    /* মডিউলের প্রথম **খোলা** পর্দা — `url` ছাড়া সারি বাদ,
                       কারণ ওগুলো এখনো তৈরি হয়নি (`planned`) আর টালিতে
                       চাপলে কিছুই হত না। */
                    $first = collect($module['groups'])->flatten(1)->firstWhere('url', '!==', null);
                @endphp

                @continue ($first === null)

                <a href="{{ $first['url'] }}" role="menuitem"
                   class="flex min-h-24 flex-col items-center justify-center gap-2
                          rounded-(--radius-card) border border-(--color-border)
                          bg-(--color-surface-app) px-2 py-3 text-center transition-colors
                          hover:border-(--color-brand-600) hover:bg-(--color-surface-muted)">
                    <span class="grid size-10 shrink-0 place-items-center rounded-(--radius-card)
                                 bg-(--color-surface-muted)">
                        <x-ui.icon :name="$module['icon']" :size="24" />
                    </span>

                    {{--
                        ⚠️ নামের জন্য জায়গা রাখতেই হয়।

                        ⓘ আগের খসড়ায় টালিগুলো ছিল ৯৫px চওড়া আর উচ্চতা
                        বাঁধা ছিল না, তাই "System Administration"-এর মতো
                        নাম বাক্সের বাইরে বেরিয়ে পড়ত — পর্দায় নামগুলো
                        টালির নিচে ভাসত। ⛔ পর্দায় জায়গা ছিল, আমি
                        ব্যবহার করিনি।
                    --}}
                    <span class="w-full break-words text-xs leading-snug text-(--color-ink-body)">{{ $module['label'] }}</span>
                </a>
            @endforeach
        </div>
    </div>
</div>
