{{--
    মডিউল › সাবমডিউল › মেনু — তিন স্তরের সুইচ, এক ছকে।

    ── কেন ছক, কার্ড নয় ────────────────────────────────────────────────
    মালিকের কথা: *"সব কটা মডিউল অন-অফ করা যাবে, মডিউলের ভিতরে সাবমডিউল,
    সব মেনু অন-অফ করা যাবে, সাব মেনুও... এগুলো একটা টেবিলে রাখ।"*

    কার্ডে সাজালে একশোর বেশি সারি চোখে পড়ত না — কে চালু আর কে বন্ধ
    সেটা এক নজরে দেখতে হলে সুইচগুলো **একই খাড়া রেখায়** থাকা চাই।

    ── উপরের স্তর নিচেরটাকে হারায় ─────────────────────────────────────
    মডিউল বন্ধ করলে ভেতরের সব বন্ধ, গ্রুপ বন্ধ করলে তার সারিগুলো।
    পর্দায় তাই ভেতরেরগুলো ম্লান দেখায় — সুইচটা চালু থাকা সত্ত্বেও ওটা
    কিছু করছে না, আর সেটা লুকিয়ে রাখলে ব্যবহারকারী ভাবতেন জিনিসটা নষ্ট।

    ── কেন `scope[]` ─────────────────────────────────────────────────
    ফর্মটা কেবল এই ট্যাবের সুইচগুলো পাঠায়। চেকবক্স বন্ধ থাকলে ব্রাউজার
    কিছুই পাঠায় না, তাই সার্ভার "অনুপস্থিত" আর "এই ট্যাবে ছিলই না"
    আলাদা করতে পারত না — আর একটা ট্যাব সেভ করলে বাকি সব নীরবে বন্ধ
    হয়ে যেত।
--}}
@foreach ($tree as $module)
    @php
        $moduleOn = $settings->get($module['key'], true);
    @endphp

    <section data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                    bg-(--color-surface-card)">
        {{-- মডিউলের নিজের সুইচ — শিরোনামের সাথেই, কারণ এটাই সবচেয়ে
             বড় সিদ্ধান্ত: বন্ধ করলে নিচের সবটা অর্থহীন। --}}
        <label class="flex min-h-(--spacing-touch) items-center gap-3 border-b border-(--color-border)
                      bg-(--color-section-head) px-4 py-3">
            <input type="checkbox" name="settings[{{ $module['key'] }}]" value="1"
                   @checked($moduleOn) data-was="{{ $moduleOn ? '1' : '' }}"
                   x-model="on['{{ $module['key'] }}']"
                   class="size-4">
            <input type="hidden" name="scope[]" value="{{ $module['key'] }}">

            <span class="font-semibold">{{ $module['label'] }}</span>

            <span class="flex-1"></span>

            <span x-cloak x-show="! on['{{ $module['key'] }}']"
                  class="rounded-(--radius-field) bg-(--color-badge-draft-bg) px-2 py-0.5 text-2xs
                         text-(--color-badge-draft-ink)">
                {{ __('system_admin::control.module_off') }}
            </span>
        </label>

        {{-- প্রথম ট্যাবে ভেতরটা আসে না — ওখানে প্রশ্ন একটাই: *কোন
             মডিউলগুলো এই ব্যবসায় লাগে?* ভেতরে যেতে হলে মডিউলের
             নিজের ট্যাব, আর সেই লিংকটাই এখানে। --}}
        @if ($module['groups'] === [])
            <div class="px-4 py-3 text-sm">
                <a href="{{ route('system_admin.control-panel', ['tab' => $module['code']]) }}"
                   class="text-(--color-link) hover:underline">
                    {{ __('system_admin::control.open_module_menu') }}
                </a>
            </div>
        @endif

        <div class="divide-y divide-(--color-border)"
             x-cloak x-show="on['{{ $module['key'] }}']">
            @foreach ($module['groups'] as $group)
                @php
                    $groupOn = $settings->get($group['key'], true);

                    $groupKey = $module['code'].'::settings_group.'.$group['name'];
                    $groupLabel = __($groupKey);

                    if ($groupLabel === $groupKey) {
                        $groupLabel = __('system_admin::settings_group.'.$group['name']);
                    }

                    if (str_contains($groupLabel, '::')) {
                        $groupLabel = ucfirst($group['name']);
                    }
                @endphp

                <div class="p-4">
                    {{-- সাবমডিউল — মডিউলের ভেতরের একটা ভাগ (মাস্টার,
                         লেনদেন, রিপোর্ট, সেটিংস)। --}}
                    <label class="flex min-h-(--spacing-touch) items-center gap-3">
                        <input type="checkbox" name="settings[{{ $group['key'] }}]" value="1"
                               @checked($groupOn) data-was="{{ $groupOn ? '1' : '' }}"
                               x-model="on['{{ $group['key'] }}']"
                               class="size-4">
                        <input type="hidden" name="scope[]" value="{{ $group['key'] }}">

                        <span class="text-2xs font-semibold uppercase tracking-wide
                                     text-(--color-ink-muted)">{{ $groupLabel }}</span>

                        <span class="flex-1"></span>

                        <span class="num text-2xs text-(--color-ink-muted)">
                            {{ count($group['items']) }}
                        </span>
                    </label>

                    {{-- মেনু সারিগুলো — গ্রুপ বন্ধ থাকলে ম্লান, কারণ
                         তখন ওদের নিজের সুইচ কিছুই করে না।

                         ── কিন্তু ম্লান, নিষ্ক্রিয় নয় ─────────────────
                         প্রথমে এখানে `:disabled` ছিল, আর সেটাই ছিল একটা
                         নীরব ফাঁদ: **বন্ধ করা চেকবক্স ব্রাউজার পাঠায়
                         না**। অথচ `scope[]`-এ নামটা থেকে যেত, তাই সার্ভার
                         পড়ত "এই সুইচটা বন্ধ করা হয়েছে" — আর গ্রুপটা বন্ধ
                         করে একবার সংরক্ষণ করলেই ভেতরের **প্রতিটা সারি
                         চিরতরে বন্ধ** হয়ে যেত। পরে গ্রুপটা আবার চালু
                         করলে পর্দাগুলো ফিরত না, আর কেন ফিরল না তা কেউ
                         বুঝত না।

                         তাই সারিগুলো ছোঁয়া যায়, শুধু ম্লান — নিজের
                         অবস্থাটা মনে রাখে, গ্রুপ ফিরলে সেটাও ফেরে। --}}
                    <div class="mt-2 space-y-1 ps-7"
                         :class="on['{{ $group['key'] }}'] ? '' : 'opacity-40'">
                        @foreach ($group['items'] as $item)
                            @php $itemOn = $settings->get($item['key'], true); @endphp

                            <label class="flex min-h-(--spacing-field-compact) items-center gap-3 text-sm">
                                <input type="checkbox" name="settings[{{ $item['key'] }}]" value="1"
                                       @checked($itemOn) data-was="{{ $itemOn ? '1' : '' }}"
                                       class="size-4">
                                <input type="hidden" name="scope[]" value="{{ $item['key'] }}">

                                <span>{{ __($item['label']) }}</span>

                                <span class="flex-1"></span>

                                {{-- সুইচের কী-টাই ম্লান করে পাশে।
                                     
                                     ── কেন রুট নয় ──────────────────────
                                     পাঁচ ধরনের ভাউচার একই রুটের পাঁচটা
                                     সারি, তাই রুট লিখলে পাঁচবার একই লেখা
                                     উঠত আর কিছুই আলাদা হত না। কী-টা
                                     আলাদা হয় (`:type=receipt`), আর ওটাই
                                     সত্যিই এই সারিটাকে চেনায় — সমস্যা
                                     হলে এই লেখাটা ধরেই খোঁজা যায়। --}}
                                <span class="truncate text-2xs text-(--color-ink-disabled)">
                                    {{ \Illuminate\Support\Str::after($item['key'], 'menu.') }}
                                </span>
                            </label>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    </section>
@endforeach
