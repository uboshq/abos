@props(['sections' => [], 'kind' => 'page'])

{{--
    ⭐ SAP Fiori (Horizon) লঞ্চপ্যাডের ভাগ আর টালি — কেবল SAP রূপে ডাকা হয় ([[FioriLaunchpad]]; মালিক, ২০ নভেম্বর ২০২৬)।

    ⓘ আসলটার মতো: প্রতিটা ভাগের মাথায় মোটা শিরোনাম, নিচে সাদা গোল টালি — উপরে নাম (মোটা), তার নিচে ছোট উপশিরোনাম, নিচের
      বাঁয়ে চিহ্ন, ডানে সংখ্যা (থাকলে)। গড়ন [[resources/css/fiori-look.css]]-এ, কেবল `[data-ui='tiles']`-এ।
    ⓘ `kind`: `page` — স্পেসের পাতা, প্রতিটা টালি মেনুর একটা সারি (Lucide চিহ্ন); `home` — হোম, প্রতিটা টালি একটা মডিউল (স্পেস)।
    ⓘ "শীঘ্রই" বা খোলা যায় না এমন সারি মেনুর মতোই: ফিকে, লিংক নয়, ঘড়ি চিহ্ন।
    ⛔ পাতা রূপের নাম লেখে না ([[EveryScreenObeysTheThemeTest]]) — তাই `kind`-এর মান রূপের নাম নয়।
--}}
@if ($sections !== [])
    <div data-fiori-launchpad="{{ $kind }}" class="fiori-launchpad">
        @foreach ($sections as $section)
            <section data-fiori-section="{{ $section['key'] }}"
                     aria-labelledby="fiori-section-{{ $kind }}-{{ $loop->index }}">
                <h2 id="fiori-section-{{ $kind }}-{{ $loop->index }}" class="fiori-section-title">{{ $section['title'] }}</h2>

                <div class="fiori-tiles">
                    {{-- ⓘ টালির তালিকার চাবি `items` — `tiles` একটা রূপের নাম, পাতা রূপের নাম লেখে না ([[EveryScreenObeysTheThemeTest]]) --}}
                    @foreach ($section['items'] as $tile)
                        @php
                            $opens = ($tile['url'] ?? null) !== null && ! ($tile['planned'] ?? false);
                            $sub = $kind === 'home'
                                ? __('core.fiori.apps_count', ['count' => $tile['app_count'] ?? 0])
                                : (($tile['planned'] ?? false) ? __('core.menu.coming_soon') : null);
                        @endphp
                        <a @if ($opens) href="{{ $tile['url'] }}" @else aria-disabled="true" @endif
                           data-fiori-tile @if ($kind === 'home') data-fiori-space-tile="{{ $tile['code'] }}" @endif
                           @class(['fiori-tile', 'is-off' => ! $opens])
                           @if ($tile['active'] ?? false) aria-current="page" @endif
                           title="{{ $tile['label'] }}">
                            <span class="fiori-tile-head">
                                <span class="fiori-tile-title">{{ $tile['label'] }}</span>
                                @if ($sub)
                                    <span class="fiori-tile-sub">{{ $sub }}</span>
                                @endif
                            </span>
                            <span class="fiori-tile-foot">
                                {{-- ⓘ চিহ্ন সবসময় এক-রঙা Lucide, টালির চিহ্নের রঙে (`--fiori-label`) — হোমের স্পেসেও ([[FioriLaunchpad::spaceIcon()]]) --}}
                                <span class="fiori-tile-icon">
                                    <x-ui.licon :name="$tile['icon']" :size="28" />
                                </span>
                                @if (! $opens && ($tile['planned'] ?? false))
                                    <span class="fiori-tile-kpi is-muted" aria-hidden="true"><x-ui.icon name="clock" :size="18" /></span>
                                @elseif (($tile['badge'] ?? null))
                                    <span class="fiori-tile-kpi num" title="{{ $tile['label'] }} — {{ $tile['badge'] }}">{{ $tile['badge'] }}</span>
                                @endif
                            </span>
                        </a>
                    @endforeach
                </div>
            </section>
        @endforeach
    </div>
@endif
