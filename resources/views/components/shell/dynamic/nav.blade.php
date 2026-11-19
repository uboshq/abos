{{--
    ⭐ Dynamic রূপের নেভিগেশনের সারি — সাদা পাতার মাথায়: কোম্পানির নাম (মোটা) │ মডিউলের মেনুগুলো আড়াআড়ি, প্রতিটায় ⌄ │ ☰
    (মালিক, ১৯ নভেম্বর ২০২৬: নমুনা "খ" — "ok final")। হোমে নিচে বেশি খোলা পাতার টিয়া লিংকের সারি।

    ⛔ নতুন কোনো অনুমতির পথ নেই: সারিগুলো লেআউটের সেই একই `$menu` থেকে ([[\App\Core\Services\MenuBuilder::forUser()]]) —
    রেলে যা দেখা যেত, এখানে ঠিক তাই, একই ঠিকানা, একই চাবি। রেলটা এই রূপে বড় পর্দায় লুকানো ([[dynamic-look.css]]),
    তাই রেলের প্রতিটা পাতা এখানে পৌঁছানোর মতো থাকতেই হবে:
      · মডিউলের ⌄ — ঐ মডিউলের সব ভাগ আর পাতা
      · যে মডিউল সারিতে ধরে না সে লুকায় (নিচের ছোট স্ক্রিপ্ট), কিন্তু ☰ সব মডিউলের সব পাতা দেখায় — কিছুই হারায় না
      · ফোনে মেনুগুলো সরে কেবল ☰ থাকে
--}}
@php
    $user = auth()->user();
    $company = $user?->currentCompany;
    $routeName = (string) (request()->route()?->getName() ?? '');

    // ⓘ চলতি মডিউল — রেল আর উপরের পটির হুবহু একই নিয়ম ([[shell.topnav]])
    $activeModule = collect($menu)->first(
        fn ($m) => collect($m['groups'])->flatten(1)->contains('active', true),
    ) ?? collect($menu)->first(
        fn ($m) => collect($m['codes'] ?? [])->contains(fn ($c) => str_starts_with($routeName, $c.'.')),
    );

    $onHome = request()->routeIs('dashboard');
    $links = $onHome ? \App\Core\Support\DynamicLook::pick($menu, \App\Core\Support\DynamicLook::LINKS) : [];
@endphp

@if ($menu !== [] || $company)
<div data-dyn-nav @if ($onHome) data-dyn-home @endif class="dyn-nav print-hide">
    <div class="dyn-nav-row">
        <a href="{{ route('dashboard') }}" class="dyn-co" data-dyn-company>{{ $company?->name() ?? 'ABOS' }}</a>

        <div class="dyn-menus" data-dyn-menus aria-label="{{ __('core.a11y.module_navigation') }}" role="navigation">
            @foreach ($menu as $module)
                @php $isOn = $activeModule && $module['code'] === $activeModule['code']; @endphp
                <div class="dyn-menu" data-dyn-menu
                     x-data="{ open: false }"
                     @click.outside="open = false"
                     @keydown.escape.window="open = false">
                    <button type="button" @click="open = ! open"
                            :aria-expanded="open ? 'true' : 'false'"
                            @class(['dyn-menu-btn', 'dyn-menu-on' => $isOn])
                            @if ($isOn) aria-current="true" @endif>
                        <span>{{ $module['label'] }}</span>
                        <svg viewBox="0 0 16 16" aria-hidden="true" class="dyn-chev"><path d="M3.5 6l4.5 4.5L12.5 6" fill="none" stroke="currentColor" stroke-width="1"/></svg>
                    </button>

                    <template x-if="open">
                        <div class="dyn-drop pops-onto-page" data-dyn-drop>
                            @foreach ($module['groups'] as $group => $items)
                                <div class="dyn-drop-group">
                                    <p class="dyn-drop-head">{{ __('core.menu.'.$group) }}</p>
                                    @foreach ($items as $item)
                                        <a @if ($item['url']) href="{{ $item['url'] }}" @else aria-disabled="true" @endif
                                           @class(['dyn-drop-item', 'dyn-drop-on' => $item['active'], 'dyn-drop-off' => ! $item['url']])
                                           @if ($item['active']) aria-current="page" @endif>{{ $item['label'] }}</a>
                                    @endforeach
                                </div>
                            @endforeach
                        </div>
                    </template>
                </div>
            @endforeach
        </div>

        {{-- ☰ — সব মডিউলের সব পাতা, এক প্যানেলে (সারিতে না ধরা মডিউলগুলোও; ফোনে এটাই একমাত্র মেনু) --}}
        <div class="dyn-all" x-data="{ open: false }" @click.outside="open = false" @keydown.escape.window="open = false">
            <button type="button" class="dyn-all-btn" data-dyn-all @click="open = ! open"
                    :aria-expanded="open ? 'true' : 'false'"
                    aria-label="{{ __('dynamic.all_menus') }}" title="{{ __('dynamic.all_menus') }}">
                <svg viewBox="0 0 16 16" aria-hidden="true"><path d="M2 4.5h12M2 8h12M2 11.5h12" stroke="currentColor" stroke-width="1" fill="none"/></svg>
            </button>
            <template x-if="open">
                <div class="dyn-all-panel pops-onto-page" data-dyn-all-panel>
                    @foreach ($menu as $module)
                        <div class="dyn-all-module">
                            <p class="dyn-all-title">{{ $module['label'] }}</p>
                            @foreach (collect($module['groups'])->flatten(1) as $item)
                                <a @if ($item['url']) href="{{ $item['url'] }}" @else aria-disabled="true" @endif
                                   @class(['dyn-drop-item', 'dyn-drop-on' => $item['active'], 'dyn-drop-off' => ! $item['url']])>{{ $item['label'] }}</a>
                            @endforeach
                        </div>
                    @endforeach
                </div>
            </template>
        </div>
    </div>

    @if ($links !== [])
        <nav class="dyn-links" data-dyn-links aria-label="{{ __('dynamic.most_used') }}">
            @foreach ($links as $link)
                <a href="{{ $link['url'] }}">{{ $link['label'] }}</a>
            @endforeach
        </nav>
    @endif
</div>

{{-- ⓘ যে মেনু সারিতে ধরে না সে লুকায় — সারিটা ভাঙে (flex-wrap), প্রথম লাইনের নিচে যা নামল তা `hidden`; ☰-এ সবই থাকে।
     স্ক্রিপ্ট না চললে সারিটা কেবল দুই লাইনে ভাঙে, কিছু হারায় না। --}}
<script @nonce>
    (() => {
        const fit = () => document.querySelectorAll('[data-dyn-menus]').forEach((row) => {
            const items = Array.from(row.querySelectorAll(':scope > [data-dyn-menu]'));
            items.forEach((item) => { item.hidden = false; });
            if (items.length === 0) { return; }
            const top = items[0].offsetTop;
            items.forEach((item) => { if (item.offsetTop > top + 4) { item.hidden = true; } });
        });
        fit();
        window.addEventListener('resize', fit);
        window.addEventListener('load', fit);
        if (document.fonts && document.fonts.ready) { document.fonts.ready.then(fit); }

        /*
         * ⭐ ফাস্ট-ট্যাব — অংশের মাথায় চাপলে অংশটা গুটায়/খোলে, ডানে "কম দেখান"/"আরও দেখান"। ⓘ কেবল আঁকা: ঘরগুলো DOM-এই
         * থাকে, তাই গুটানো অংশের ঘরও ফর্মের সাথে জমা পড়ে। মাথার ভিতরের বোতাম বা লিংকে চাপলে কিছুই গুটায় না।
         */
        const less = @json(__('dynamic.show_less'));
        const more = @json(__('dynamic.show_more'));
        const tabs = () => document.querySelectorAll('main section[data-boxed] > h2:first-child').forEach((head) => {
            const section = head.parentElement;
            if (head.querySelector('.dyn-ft-toggle') || section.children.length < 2) { return; }
            const toggle = document.createElement('span');
            toggle.className = 'dyn-ft-toggle';
            toggle.textContent = less;
            head.appendChild(toggle);
            head.setAttribute('aria-expanded', 'true');
            head.addEventListener('click', (event) => {
                if (event.target.closest('a, button, input, select, textarea, label') && !event.target.closest('.dyn-ft-toggle')) { return; }
                const folded = section.toggleAttribute('data-dyn-folded');
                toggle.textContent = folded ? more : less;
                head.setAttribute('aria-expanded', folded ? 'false' : 'true');
            });
        });
        if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', tabs); } else { tabs(); }
    })();
</script>
@endif
