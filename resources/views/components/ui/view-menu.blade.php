@props([
    /** কোন পর্দার দৃশ্য — রুটের নাম। */
    'screen' => null,

    /** পর্দার শিরোনাম, যেটা `heading` মোডে বোতামের লেখা হয়। */
    'label' => '',

    /**
     * `heading` — শিরোনামটাই বোতাম (D365)।
     * `button`  — টুলবারের ডানে একটা আলাদা বোতাম (বাকি ন'টা রূপ)।
     */
    'mode' => 'button',
])

{{--
    সংরক্ষিত দৃশ্যের মেনু।

    ── এটা কী, আর এটা কোনটা নয় ─────────────────────────────────────────
        view-menu    (এটা)  সংরক্ষিত দৃশ্য · ড্রপডাউনে · **বানানোর জায়গা**
        view-strip          একই দৃশ্য · সারিতে · এক চাপে বাছা
        stage-strip         কাগজের ধাপ · সারিতে · গোনা ও টাকা

    ⭐ **`view-strip` এর প্রতিদ্বন্দ্বী নয়, জোড়া।** এখানে দৃশ্য **বানানো**
    হয় ("এই দৃশ্যটা রেখে দিন"), সারিতে সেটা **বাছা** হয়। সারিটা একা
    বসালে নতুন দৃশ্য বানানোর পথ থাকে না — আর তখন সারিটা চিরকাল আমাদের
    পাঠানো চারটা নামেই আটকে থাকত।

    ── কেন এই কম্পোনেন্টটা লাগল ─────────────────────────────────────────
    টুলবারে শিরোনামের পাশে অনেকদিন ধরে একটা `▾` চিহ্ন আঁকা হত, আর তার
    পাশে মন্তব্যে লেখা ছিল "চিহ্নটা বলে এটা একটা **দৃশ্য**, স্থির নাম নয়"।

    কিন্তু ওই চিহ্নের পেছনে **কোনো মেনু ছিল না** — ক্লিক করলে কিছুই হত
    না, দশটা রূপের একটাতেও। অর্থাৎ পর্দায় একটা নিয়ন্ত্রণের চেহারা ছিল
    যেটা নিয়ন্ত্রণ নয়; এই কোডবেসের নিজের ভাষায় **মৃত বোতাম**।

    ধরা পড়েছে ২৭ অগাস্ট ২০২৬-এ, D365-এর নকল মিলিয়ে দেখতে গিয়ে।

    ── দুইটা মোড কেন ────────────────────────────────────────────────────
    D365-এ শিরোনামটাই ড্রপডাউন — "Active Accounts ⌄" — আর ওটাই তার
    তালিকার পর্দার সবচেয়ে চেনা জিনিস। বাকি ন'টা পণ্যে শিরোনাম কেবল
    শিরোনাম, আর দৃশ্য বাছার নিয়ন্ত্রণটা টুলবারের অন্য নিয়ন্ত্রণগুলোর
    সাথে বসে।

    জিনিসটা এক, জায়গাটা রূপের — ঠিক যেভাবে `stage-strip` D365-এ শেভরন
    আর Fiori-তে টালি হয়।

    ── কেন খালি অবস্থাতেও মেনুটা থাকে ───────────────────────────────────
    একটাও সংরক্ষিত দৃশ্য না থাকলেও মেনুতে **"এই দৃশ্যটা রেখে দিন"** থাকে,
    তাই মেনুটা কোনোদিনই খালি নয়। প্রথম দৃশ্যটা বানানোর জায়গা ওটাই — আর
    ওটা না থাকলে কেউ কোনোদিন প্রথমটাই বানাতে পারতেন না।
--}}
@php
    $route = request()->route();
    $screen = $screen ?: (string) ($route?->getName() ?? '');
    $user = auth()->user();

    /*
     * পর্দাটা কি প্যারামিটার ছাড়া ঠিকানা বানাতে পারে।
     *
     * ── কেন এই শর্তটা, আর কী ভেঙেছিল ────────────────────────────────
     * প্রথম লেখায় এই যাচাইটা ছিল না, আর নিচে সরাসরি `route($screen)`
     * ডাকা হত। কিছু পর্দার রুটে প্যারামিটার লাগে —
     * `inventory.report.show` তেমন একটা — আর তখন `route()` ছুড়ে ফেলে
     * `UrlGenerationException`, ফলে **গোটা পাতাটাই ৫০০**।
     *
     * ২৭ অগাস্ট ২০২৬-এ পুরো সুইটে ৩৮টা লাল হয়েছিল, তার বেশিরভাগই এই
     * একটা কারণে: রিপোর্টের পর্দা, ভাউচারের পর্দা, মেনুর সারি — সবই
     * ভাঙছিল। ব্রাউজারে দেখা তিনটা তালিকার পর্দা ঠিকই চলছিল, কারণ
     * ওগুলোর রুটে প্যারামিটার নেই।
     *
     * ── কেন শর্তটা "লুকিয়ে ফেলা", "ঠিক করে দেওয়া" নয় ────────────────
     * সংরক্ষিত দৃশ্য মানে **একটা তালিকার ছাঁকনির সেট**। যে পর্দা একটা
     * নির্দিষ্ট রেকর্ড বা রিপোর্টের জন্য খোলে, তার "দৃশ্য" রাখার কোনো
     * মানেই নেই — ওটা তো ওই একটা জিনিসেরই পাতা।
     *
     * অর্থাৎ এটা ফাঁক ঢাকা নয়, জিনিসটার সীমানা।
     */
    $addressable = $route !== null
        && $screen !== ''
        && $route->parameterNames() === [];

    /*
     * এই পর্দার নিজের দৃশ্যগুলো।
     *
     * রুটের নাম না থাকলে (কোনো পর্দা নামহীন রুটে বসলে) কিছুই দেখানো
     * হয় না — সংরক্ষণ করলে ওটা এমন একটা নাম ধরে রাখত যেটা `route()`
     * দিয়ে ফেরত আনা যেত না।
     */
    $views = ($addressable && $user)
        ? \App\Models\SavedView::query()->mine($user->id, $screen)->get()
        : collect();

    // এখন যা দেখা যাচ্ছে — এটাই সংরক্ষণের সময় রেখে দেওয়া হয়
    $currentQuery = (string) (request()->getQueryString() ?? '');

    // চলতি দৃশ্যটা কোনটা: হুবহু একই ছাঁকনি হলে সেটাই
    $active = $views->firstWhere('query', $currentQuery);

    /*
     * সংরক্ষণের ফর্মটার আইডি।
     *
     * এক পাতায় মেনুটা দুইবার বসতে পারে না — D365-এ শিরোনামে, বাকিদের
     * ডানে, কখনো একসাথে নয়। তবু আইডিটা মোডসহ বানানো: একই আইডির দুইটা
     * ফর্ম থাকলে `form=` অ্যাট্রিবিউট প্রথমটাকেই ধরত, আর দ্বিতীয়
     * মেনুটার সংরক্ষণ **নীরবে ভুল ফর্মে** যেত।
     */
    $formId = $mode;
@endphp

@if ($addressable && $user)
    <div x-data="{ open: false, naming: false }"
         @click.outside="open = false; naming = false"
         @keydown.escape.window="open = false; naming = false"
         class="relative shrink-0">

        <button type="button" data-view-menu
                @click="open = ! open"
                :aria-expanded="open ? 'true' : 'false'"
                aria-haspopup="menu"
                @class([
                    'flex shrink-0 items-center gap-1.5 transition-colors',
                    // D365 — শিরোনামটাই বোতাম, তাই শিরোনামের মাপেই
                    'text-lg font-semibold text-(--color-ink) hover:text-(--color-brand-600)' => $mode === 'heading',
                    // বাকিরা — টুলবারের আর সব বোতামের মতো
                    'min-h-(--spacing-touch) rounded-(--radius-field) px-2 text-sm text-(--color-ink-muted) hover:bg-(--color-surface-hover) hover:text-(--color-ink)' => $mode !== 'heading',
                ])>
            @if ($mode === 'heading')
                <span class="truncate">{{ $active?->name ?: $label }}</span>
            @else
                <svg viewBox="0 0 24 24" aria-hidden="true" class="size-4 shrink-0 fill-current">
                    <path d="M3 5h18v2H3V5Zm3 6h12v2H6v-2Zm4 6h4v2h-4v-2Z"/>
                </svg>
                <span class="hidden xl:inline">{{ $active?->name ?: __('core.view.views') }}</span>
            @endif

            <svg viewBox="0 0 20 20" aria-hidden="true"
                 class="size-3.5 shrink-0 fill-none stroke-current" stroke-width="1.6">
                <path d="M5.6 8.2 10 12.4l4.4-4.2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        </button>

        <div x-show="open" x-cloak x-transition.opacity.duration.100ms
             role="menu"
             class="pops-onto-page absolute start-0 top-full z-40 mt-1 w-72 rounded-(--radius-card)
                    border border-(--color-border) bg-(--color-surface-card) py-1
                    shadow-(--shadow-overlay)">

            {{-- সব সারি — ছাঁকনি ছাড়া পর্দাটা। এটা সবসময় থাকে, কারণ
                 ছাঁকনি দেওয়ার পর "সবটা আবার দেখি" প্রশ্নটা এমনিতেই আসে। --}}
            <a href="{{ route($screen) }}" role="menuitem"
               @class([
                   'flex min-h-(--spacing-touch) items-center gap-2 px-3 text-sm',
                   'bg-(--color-surface-selected) font-semibold' => $currentQuery === '',
                   'hover:bg-(--color-surface-hover)' => $currentQuery !== '',
               ])>
                <span class="min-w-0 flex-1 truncate">{{ __('core.view.all_rows') }}</span>
            </a>

            @foreach ($views as $view)
                <div @class([
                        'group flex min-h-(--spacing-touch) items-center gap-1 px-1',
                        'bg-(--color-surface-selected)' => $active?->is($view),
                        'hover:bg-(--color-surface-hover)' => ! $active?->is($view),
                     ])>
                    <a href="{{ $view->url() }}" role="menuitem"
                       @class([
                           'flex min-w-0 flex-1 items-center gap-2 px-2 py-1 text-sm',
                           'font-semibold' => $active?->is($view),
                       ])>
                        <span class="min-w-0 flex-1 truncate">{{ $view->name }}</span>

                        {{-- ডিফল্টের চিহ্ন — রং একা অর্থ বহন করে না,
                             তাই title-এ কথাটাও লেখা (নিয়ম ১৪.৯)। --}}
                        @if ($view->is_default)
                            <svg viewBox="0 0 24 24" aria-hidden="true"
                                 class="size-3.5 shrink-0 fill-(--color-brand-500)">
                                <title>{{ __('core.view.is_default') }}</title>
                                <path d="m12 3 2.6 5.6 6.1.8-4.5 4.2 1.2 6-5.4-3-5.4 3 1.2-6L3.3 9.4l6.1-.8L12 3Z"/>
                            </svg>
                        @endif
                    </a>

                    @unless ($view->is_default)
                        {{-- ফর্মটা পাতার শেষে, বোতামটা এখানে — কারণটা
                             `layouts/app.blade.php`-এর `detached-forms`
                             স্ট্যাকে লেখা: টুলবার নিজেই একটা GET ফর্ম,
                             আর ফর্মের ভেতরে ফর্ম বসে না। --}}
                        @push('detached-forms')
                            <form id="view-default-{{ $view->id }}" method="POST"
                                  action="{{ route('views.default', $view) }}" class="hidden">
                                @csrf
                            </form>
                        @endpush

                        <span class="contents">
                            <button type="submit" form="view-default-{{ $view->id }}"
                                    title="{{ __('core.view.make_default') }}"
                                    class="grid size-7 shrink-0 place-items-center rounded-[3px]
                                           text-(--color-ink-muted) opacity-0 transition
                                           group-hover:opacity-100 hover:text-(--color-brand-600)
                                           focus:opacity-100">
                                <svg viewBox="0 0 24 24" aria-hidden="true"
                                     class="size-3.5 fill-none stroke-current" stroke-width="1.6">
                                    <path d="m12 3.8 2.4 5.1 5.6.7-4.1 3.9 1.1 5.5-5-2.8-5 2.8 1.1-5.5L4 9.6l5.6-.7L12 3.8Z"
                                          stroke-linejoin="round"/>
                                </svg>
                            </button>
                        </span>
                    @endunless

                    @push('detached-forms')
                        <form id="view-destroy-{{ $view->id }}" method="POST"
                              action="{{ route('views.destroy', $view) }}" class="hidden"
                              onsubmit="return confirm('{{ __('core.view.confirm_remove', ['name' => $view->name]) }}')">
                            @csrf
                            @method('DELETE')
                        </form>
                    @endpush

                    <span class="contents">
                        <button type="submit" form="view-destroy-{{ $view->id }}"
                                title="{{ __('core.view.remove') }}"
                                class="grid size-7 shrink-0 place-items-center rounded-[3px]
                                       text-(--color-ink-muted) opacity-0 transition
                                       group-hover:opacity-100 hover:text-(--color-danger-hover)
                                       focus:opacity-100">
                            <svg viewBox="0 0 24 24" aria-hidden="true" class="size-3.5 fill-current">
                                <path d="m12 10.6 5.3-5.3 1.4 1.4-5.3 5.3 5.3 5.3-1.4 1.4-5.3-5.3-5.3 5.3-1.4-1.4 5.3-5.3-5.3-5.3 1.4-1.4z"/>
                            </svg>
                        </button>
                    </span>
                </div>
            @endforeach

            <div class="my-1 border-t border-(--color-border)"></div>

            {{-- এখনকার ছাঁকনিটা রেখে দেওয়া।

                 ── কেন নামের ঘরটা মেনুর ভেতরেই ─────────────────────────
                 আলাদা একটা মডাল খুললে ছাঁকনির পর্দাটা ঢাকা পড়ত, আর
                 "আমি কী সংরক্ষণ করছি" প্রশ্নের উত্তরটাই হারিয়ে যেত।
                 নামের ঘরটা মেনুতে থাকায় তালিকাটা পেছনে দেখাই যায়। --}}
            @push('detached-forms')
                <form id="view-store-{{ $formId }}" method="POST"
                      action="{{ route('views.store') }}" class="hidden">
                    @csrf
                    <input type="hidden" name="screen" value="{{ $screen }}">
                    <input type="hidden" name="query" value="{{ $currentQuery }}">
                </form>
            @endpush

            {{-- ঘরগুলো মেনুতেই থাকে, ফর্মটা পাতার শেষে — `form=` দিয়ে
                 জোড়া লাগে। HTML-এ ফর্মের বাইরের ঘরও এভাবে ফর্মের অংশ হয়,
                 আর ঠিক এই কাজের জন্যই অ্যাট্রিবিউটটা আছে।

                 ── কেন নামের ঘরটা মেনুর ভেতরেই ─────────────────────────
                 আলাদা একটা মডাল খুললে ছাঁকনির পর্দাটা ঢাকা পড়ত, আর
                 "আমি কী সংরক্ষণ করছি" প্রশ্নের উত্তরটাই হারিয়ে যেত। --}}
            <div x-show="naming" x-cloak class="flex flex-col gap-1.5 px-2 py-1.5">
                <input type="text" name="name" required maxlength="80"
                       form="view-store-{{ $formId }}"
                       x-ref="viewName"
                       placeholder="{{ __('core.view.name_placeholder') }}"
                       class="min-h-(--spacing-field) w-full rounded-(--radius-field)
                              border border-(--color-border) bg-(--color-surface-app) px-2
                              text-sm text-(--color-ink)">

                <label class="flex items-center gap-2 text-2xs text-(--color-ink-muted)">
                    <input type="checkbox" name="is_default" value="1" class="size-3.5"
                           form="view-store-{{ $formId }}">
                    {{ __('core.view.set_as_default') }}
                </label>

                {{-- `size` প্রপ নেই এই কম্পোনেন্টে — দিলে ওটা কাঁচা
                     HTML অ্যাট্রিবিউট হয়ে বোতামে বসত। --}}
                <x-ui.button type="submit" tone="primary" form="view-store-{{ $formId }}">
                    {{ __('core.action.save') }}
                </x-ui.button>
            </div>

            {{-- `data-view-save` — পাহারা ও পরীক্ষার জন্য।

                 লেখা ধরে খুঁজলে ভাষা বদলালেই পরীক্ষাটা ভাঙত, আর ক্লাস
                 ধরে খুঁজলে নকশা বদলালে। চিহ্নটা দুইটার কোনোটার সাথেই
                 বদলায় না। --}}
            <button type="button" x-show="! naming" data-view-save
                    @click="naming = true; $nextTick(() => $refs.viewName?.focus())"
                    class="flex min-h-(--spacing-touch) w-full items-center gap-2 px-3 text-start text-sm
                           text-(--color-ink-body) hover:bg-(--color-surface-hover)">
                <svg viewBox="0 0 24 24" aria-hidden="true" class="size-4 shrink-0 fill-current">
                    <path d="M11 5h2v6h6v2h-6v6h-2v-6H5v-2h6V5Z"/>
                </svg>
                {{ __('core.view.save_current') }}
            </button>
        </div>
    </div>
@endif
