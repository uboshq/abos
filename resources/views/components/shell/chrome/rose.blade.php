@props(['menu' => [], 'region'])

{{--
    ⭐ "ABOS" রূপের নিজের খোলস — মালিকের অনুমোদিত নমুনা, ১৯ নভেম্বর ২০২৬ (claude.ai artifact AAjAfjZ8TnCfnjtt3Kthjz v11)।

    ⛔ স্বাধীন — মালিক: "একটা বদলালে অন্যটা বদলাবে না"। navy বা blue-র খোলস এখানে ধার নয়; নিচের যোগফলের পট্টিও এই ফাইলের নিজের
       কপি। গড়ন (সমতল নীল মেনু, গোল কাজের প্যানেল, চিকন নিচের বার) [[resources/css/abos-look.css]]-এ, কেবল `[data-ui='rose']`-এ।

    ── অঞ্চল ─────────────────────────────────────────────────────────────
        rail-foot     মেনুর একদম নিচে মডিউল-বদলের বোতাম, তালিকা উপরে খোলে (Dynamics 365-এর "Change area"-র মতো) —
                      এক কলাম, Lucide চিহ্ন রঙিন টাইলে, খোঁজার ঘর, "⇅ সাজান" (▲ ▼) আর "আগের ক্রম"; ক্রম নিজের সারিতে
                      (`users.nav_layout`, রেলের একই ঘর আর একই দরজা `rail.layout` — [[MenuBuilder::arranged()]])
        ticker-start  নিচের বারের চলন্ত নোটিশের আগে নীল "Notice" চিহ্ন
        page-foot     তালিকার যোগফলের পট্টি + কীবোর্ডের হিন্ট
--}}

@if ($region === 'rail-foot')
    @php
        $pickerUser = auth()->user();
        $registry = app(\App\Core\Module\ModuleRegistry::class);
        $looks = (array) config('abos.module_picker', []);
        $fallback = $looks['default'] ?? ['icon' => 'layout-grid', 'colour' => '#2563eb'];
        $routeName = (string) (request()->route()?->getName() ?? '');

        // ⓘ সাইডবারের একই উত্তর — কোন মডিউলে আছি: চালু সারি, নইলে রুটের উপসর্গ ([[shell.sidebar]]-এর `$hereModule`)
        $here = collect($menu)->first(fn ($m) => collect($m['groups'])->flatten(1)->contains('active', true))
            ?? collect($menu)->first(fn ($m) => collect($m['codes'])->contains(fn ($c) => str_starts_with($routeName, $c.'.')));

        $pickerRows = [];
        foreach (app(\App\Core\Services\MenuBuilder::class)->arranged($menu, $pickerUser) as $module) {
            $first = collect($module['groups'])->flatten(1)->firstWhere('url', '!==', null);

            // ⓘ একটাও খোলা পাতা নেই এমন মডিউল তালিকায় নেই — মরা সারি রাখা হয় না ([[shell.launcher]]-এর একই নিয়ম)
            if ($first === null) {
                continue;
            }

            $keys = [$module['code'], $module['label']];
            foreach ($module['codes'] as $code) {
                $definition = $registry->get($code);
                $keys[] = $code;
                $keys[] = $definition?->label('bn');
                $keys[] = $definition?->label('en');
            }

            $look = $looks[$module['code']] ?? $fallback;

            $pickerRows[] = [
                'code' => $module['code'],
                'label' => $module['label'],
                'url' => $first['url'],
                'icon' => \App\Core\Navigation\LucideIcons::has((string) ($look['icon'] ?? '')) ? $look['icon'] : $fallback['icon'],
                'colour' => (string) ($look['colour'] ?? $fallback['colour']),
                'keys' => mb_strtolower(implode(' ', array_unique(array_filter($keys)))),
                'pinned' => (bool) ($module['pinned'] ?? false),
                'here' => $here !== null && $module['code'] === $here['code'],
            ];
        }

        // ⓘ বোতামের নাম — যে মডিউলের মেনু পাশে খোলা (ড্যাশবোর্ডে সাইডবারের শেষ ভরসার একই নিয়ম: প্রথম কাজের মডিউল); তালিকায় চিহ্ন কেবল সত্যিকারের "এখানে"
        $panel = $here ?? collect($menu)->first(fn ($m) => ! in_array($m['section'], ['top', 'system'], true)) ?? ($menu[0] ?? null);
        $current = collect($pickerRows)->firstWhere('here', true)
            ?? ($panel ? collect($pickerRows)->firstWhere('code', $panel['code']) : null);
    @endphp

    @if ($pickerRows !== [])
        <div x-data="modulePicker" data-module-picker
             data-save="{{ route('rail.layout') }}"
             data-arrange-label="{{ __('core.picker.arrange') }}" data-done-label="{{ __('core.picker.done') }}"
             data-note="{{ __('core.picker.note') }}" data-note-arranging="{{ __('core.picker.note_arranging') }}"
             @open-module-picker.window="toggle()"
             @keydown.escape.window="close()"
             @click.outside="away()"
             class="abos-modpick print-hide">
            <input type="hidden" value="{{ csrf_token() }}" data-picker-token>

            {{-- ⭐ তালিকা — বোতামের উপরে খোলে; এক কলাম, ১০৮০-এ সব সারি স্ক্রল ছাড়াই (নমুনার মাপ: চিহ্ন ৩৪px, নাম ১৫px) --}}
            <div x-show="open" x-cloak data-modlist :class="listClass"
                 role="dialog" aria-label="{{ __('core.picker.title') }}" class="abos-modlist">
                <label class="abos-modfind">
                    <x-ui.licon name="search" :size="15" />
                    <span class="sr-only">{{ __('core.picker.find') }}</span>
                    <input type="search" x-ref="find" x-model="q" data-picker-search autocomplete="off" enterkeyhint="go"
                           @keydown.enter.prevent="openFirst()"
                           placeholder="{{ __('core.picker.find') }}">
                </label>

                <ol data-picker-rows class="abos-modrows">
                    @foreach ($pickerRows as $row)
                        <li data-picker-row="{{ $row['code'] }}" data-keys="{{ $row['keys'] }}" data-pinned="{{ $row['pinned'] ? '1' : '0' }}">
                            <a href="{{ $row['url'] }}" data-picker-link @click="follow($event)"
                               @class(['abos-modrow', 'is-here' => $row['here']])
                               @if ($row['here']) aria-current="true" @endif>
                                <i class="abos-modtile" style="background: {{ $row['colour'] }}" aria-hidden="true">
                                    <x-ui.licon :name="$row['icon']" :size="19" />
                                </i>
                                <span class="abos-modname">{{ $row['label'] }}</span>
                            </a>
                            <span class="abos-mv">
                                <button type="button" data-up @click="up($event)"
                                        aria-label="{{ __('core.picker.up') }} {{ $row['label'] }}">▲</button>
                                <button type="button" data-down @click="down($event)"
                                        aria-label="{{ __('core.picker.down') }} {{ $row['label'] }}">▼</button>
                            </span>
                        </li>
                    @endforeach
                </ol>

                <p x-show="none" x-cloak data-picker-none class="abos-modnone">{{ __('core.picker.none') }}</p>

                <div class="abos-modfoot">
                    <button type="button" data-arrange @click="arrange()" :class="arrangeClass" x-text="arrangeLabel">{{ __('core.picker.arrange') }}</button>
                    <button type="button" data-reset x-show="arranging" x-cloak @click="reset()">{{ __('core.picker.reset') }}</button>
                    <small data-arrange-note x-text="note">{{ __('core.picker.note') }}</small>
                </div>
            </div>

            {{-- ⭐ মেনুর নিচের বোতাম — চলতি মডিউলের চিহ্ন আর নাম, নিচে "মডিউল বদলান"; ⊞-ও এই তালিকাই খোলে --}}
            <button type="button" data-modpick @click="toggle()" :aria-expanded="openFlag" aria-expanded="false"
                    aria-haspopup="dialog" class="abos-modpick-btn">
                <i aria-hidden="true">
                    <x-ui.licon :name="$current['icon'] ?? $fallback['icon']" :size="17" />
                </i>
                <span class="min-w-0 flex-1 truncate">
                    {{ $current['label'] ?? __('core.picker.title') }}
                    <small>{{ __('core.picker.change') }}</small>
                </span>
                <span class="abos-chev" aria-hidden="true">▴</span>
            </button>
        </div>
    @endif
@endif

@if ($region === 'ticker-start')
    {{-- ⭐ নিচের বারে চলন্ত নোটিশের আগে নীল "Notice" চিহ্ন (নমুনার `.ticker .tag`) --}}
    <span class="abos-notice-tag" data-notice-tag><i aria-hidden="true"></i>{{ __('core.picker.notice') }}</span>
@endif

@if ($region === 'page-foot')
    @php
        $showTotals = \App\Core\Support\Ui::listFoot(auth()->user()?->ui) === 'totals';
        $showHints = \App\Core\Support\Ui::hints(auth()->user()?->ui) === 'keys';

        // ⚠️ যোগফলের স্ট্যাক কেবল একবার পড়া যায়, তাই মানটা ধরে রাখা হয়
        $totals = trim($__env->yieldPushContent('list-totals'));
    @endphp

    {{-- ⛔ পট্টিটা কেবল তালিকার পর্দায় — পর্দা নিজের যোগফল ঘোষণা করলে; ফর্ম, রেকর্ড আর ড্যাশবোর্ডে নয় (navy-র একই নিয়ম, নিজের কপি) --}}
    @if ($showTotals && $totals !== '')
        <div data-look-listfoot
             class="sticky bottom-(--spacing-bottom-nav) z-[3] flex flex-wrap items-center
                    gap-x-5 gap-y-1 border-t border-(--color-border)
                    bg-(--color-surface-card) px-3 py-1.5 text-2xs
                    md:bottom-0 md:px-5 lg:px-6">
            {!! $totals !!}

            @if ($showHints)
                <span data-look-hints
                      class="ms-auto hidden items-center gap-3 text-(--color-ink-muted) md:flex">
                    @foreach ([
                        '/' => 'core.keys.search',
                        'N' => 'core.keys.new',
                        '↑↓' => 'core.keys.row',
                        '↵' => 'core.keys.open',
                    ] as $key => $label)
                        <span class="inline-flex items-center gap-1">
                            <kbd class="rounded-(--radius-badge) border border-(--color-border)
                                        bg-(--color-surface-sunken) px-1 font-medium">{{ $key }}</kbd>
                            {{ __($label) }}
                        </span>
                    @endforeach
                </span>
            @endif
        </div>
    @endif
@endif
