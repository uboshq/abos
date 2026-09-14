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
        {{--
            ── জ্যামিতিটা inline style-এ, আর সেটা ইচ্ছাকৃত ব্যতিক্রম ──────
            এই রিপোর নিয়ম টোকেন ও ইউটিলিটি ক্লাস, আর সেটাই ঠিক। ⛔ কিন্তু
            এই প্যানেলটা পরপর তিনবার ভাঙা অবস্থায় মালিকের পর্দায় গেছে,
            আর প্রতিবার কারণ ছিল এক: কোনো একটা ক্লাস **কম্পাইলই হয়নি**,
            অথচ ব্লেডে ওটা লেখা ছিল। ⚠️ যে ক্লাস নেই সে চুপ করে কিছুই
            করে না — কোনো ভুলের বার্তা নেই, কেবল ভাঙা পর্দা।

            ⓘ তাই যেটুকুর উপর গড়নটা দাঁড়িয়ে আছে — ছক, মাপ, ফাঁক — সেটুকু
            inline, কারণ inline style কম্পাইল হওয়ার অপেক্ষা করে না। রং
            আগের মতোই টোকেনে, তাই থিম বদলালে সাথে যায়।

            `auto-fill minmax(120px, 1fr)` — কলামের সংখ্যা আর কোথাও লেখা
            নেই; যত জায়গা, তত কলাম। জুম করা পর্দা বা সরু জানালায় নিজে
            থেকেই কমে যায়, আর কোনো breakpoint মনে রাখতে হয় না।
        --}}
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:8px">
            @foreach ($menu as $module)
                @php
                    $first = collect($module['groups'])->flatten(1)->firstWhere('url', '!==', null);
                @endphp

                @continue ($first === null)

                <a href="{{ $first['url'] }}" role="menuitem"
                   class="transition-colors hover:bg-(--color-surface-muted)"
                   style="display:flex;flex-direction:column;align-items:center;justify-content:flex-start;
                          gap:8px;min-height:104px;padding:12px 8px;text-align:center;
                          border:1px solid var(--color-border);border-radius:10px;
                          background:var(--color-surface-app)">
                    <span style="display:grid;place-items:center;width:40px;height:40px;flex:none;
                                 border-radius:10px;background:var(--color-surface-muted)">
                        <x-ui.icon :name="$module['icon']" :size="24" />
                    </span>

                    <span style="width:100%;font-size:12px;line-height:1.35;overflow-wrap:break-word;
                                 color:var(--color-ink-body)">{{ $module['label'] }}</span>
                </a>
            @endforeach
        </div>
    </div>
</div>
