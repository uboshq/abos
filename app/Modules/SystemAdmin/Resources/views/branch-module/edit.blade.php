{{--
    কোন শাখা কোন মডিউল পাবে।

    ── ⓘ কেন ছক, আর শাখা ধরে ট্যাব ──────────────────────────────────────
    কন্ট্রোল প্যানেলের মডিউল ছকটার হুবহু একই চেহারা — ইচ্ছাকৃত। দুইটা
    পর্দা একই জিনিসের দুই স্তর, আর আলাদা দেখালে মানুষকে দুইবার শিখতে হত।

    ⚠️ ট্যাবগুলো লিংক, JS নয়: প্রতিটা শাখার নিজের ঠিকানা থাকে, তাই
    "ঢাকা ডিপোর মডিউল" বুকমার্ক করে রাখা যায়।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('system_admin::branch_module.title') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('system_admin::branch_module.title')"
                          :subtitle="__('system_admin::branch_module.note')" />
    </x-slot:header>

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    @if ($current === null)
        <x-ui.empty-state :message="__('system_admin::branch_module.no_branches')" />
    @else
        <nav class="mb-4 flex flex-wrap gap-1 border-b border-(--color-border) print-hide"
             aria-label="{{ __('system_admin::branch_module.title') }}">
            @foreach ($branches as $one)
                <a href="{{ route('system_admin.branch-module', ['branch' => $one->id]) }}"
                   @class([
                       'min-h-(--spacing-touch) rounded-t-(--radius-field) px-3 py-2 text-sm transition-colors',
                       'border-b-2 border-(--color-brand-600) font-semibold' => $current->id === $one->id,
                       'text-(--color-ink-muted) hover:bg-(--color-surface-hover)' => $current->id !== $one->id,
                   ])
                   @if ($current->id === $one->id) aria-current="page" @endif>
                    {{ $one->name() }}
                </a>
            @endforeach
        </nav>

        <form method="POST" action="{{ route('system_admin.branch-module.update') }}"
              {{-- ⓘ কন্ট্রোল প্যানেলের সেই একই পটি — কয়টা বদল জমেছে গোনে
                   (`resources/js/components/forms.js`-এ `switchBoard`)। --}}
              x-data="switchBoard({ on: @js(collect($modules)->pluck('on', 'code')->all()) })"
              @change="touch($event.target)"
              class="max-w-3xl space-y-4 pb-20">
            @csrf
            @method('PUT')

            <input type="hidden" name="branch" value="{{ $current->id }}">

            <div data-boxed class="overflow-hidden rounded-(--radius-card) border border-(--color-border)
                            bg-(--color-surface-card)">
                <div class="table-responsive">
                    <table class="ui-list table-cards w-full border-collapse">
                        <thead>
                            <tr class="border-b border-(--color-border)">
                                <th class="w-10">
                                    {{-- ⭐ "সব বাছাই" — মালিকের দেখানো পর্দায় যেটা ছিল।

                                         ⚠️ এটা কেবল টিকগুলো নাড়ে, সংরক্ষণ করে না — সংরক্ষণ
                                         নিচের পটিতে। ⓘ কাজটা `switchBoard.setAll()`-এ, ব্লেডে
                                         নয়: Alpine-এর CSP বিল্ডে ইনলাইন এক্সপ্রেশন চলে না
                                         ([[EveryInlineScriptCarriesItsNonceTest]]-র কারণে
                                         আমাদের বিল্ড `@alpinejs/csp`)। --}}
                                    <input type="checkbox" @change="setAll($event.target)"
                                           aria-label="{{ __('system_admin::branch_module.select_all') }}"
                                           class="size-4">
                                </th>
                                <th class="text-start">{{ __('system_admin::control.column_module') }}</th>
                                <th class="num">{{ __('system_admin::branch_module.column_off_elsewhere') }}</th>
                            </tr>
                        </thead>

                        <tbody>
                            @foreach ($modules as $module)
                                <tr class="hover:bg-(--color-surface-hover)">
                                    <td data-label="{{ __('system_admin::control.column_on') }}">
                                        {{-- ⓘ নামটা সারি-প্রতি আলাদা (`modules[accounts]`), `modules[]` নয়।

                                             ⚠️ `switchBoard.touch()` বদলগুলো **`el.name` ধরে** গোনে।
                                             সবগুলোর নাম এক হলে দশটা বদলও "১টা বদল" দেখাত, আর
                                             পটিটা মিথ্যা বলত। --}}
                                        <input type="checkbox" name="modules[{{ $module['code'] }}]" value="1"
                                               @checked($module['on']) data-was="{{ $module['on'] ? '1' : '' }}"
                                               data-key="{{ $module['code'] }}"
                                               x-model="on['{{ $module['code'] }}']"
                                               @disabled($module['locked'])
                                               aria-label="{{ $module['label'] }}"
                                               class="size-4">

                                        {{-- ⓘ সার্ভার কেবল ফর্মে থাকা সারিগুলোই দেখে
                                             ([[BranchModuleController::update()]]) — ৩০ আগস্ট
                                             ২০২৬-এ কন্ট্রোল প্যানেলে এটা না থাকায় এক ট্যাব
                                             সংরক্ষণ করলে অন্যগুলো নীরবে বন্ধ হয়েছিল। --}}
                                        <input type="hidden" name="scope[]" value="{{ $module['code'] }}">
                                    </td>

                                    <td data-label="{{ __('system_admin::control.column_module') }}">
                                        <span class="font-medium">{{ $module['label'] }}</span>

                                        @if (! $module['company_on'])
                                            {{-- ⛔ কোম্পানিতেই বন্ধ — শাখার টিক দিয়ে এটা খোলা
                                                 যায় না। ⚠️ সারিটা লুকানো হয়নি ইচ্ছা করেই:
                                                 লুকালে মানুষ ভাবতেন মডিউলটা নেই, আর আসল
                                                 সুইচটা কোথায় তা কেউ বলত না। --}}
                                            <span class="ms-2 rounded-(--radius-field) bg-(--color-badge-draft-bg)
                                                         px-2 py-0.5 text-2xs text-(--color-badge-draft-ink)">
                                                {{ __('system_admin::branch_module.company_off') }}
                                            </span>
                                        @endif

                                        @if ($module['locked'])
                                            <span class="ms-2 rounded-(--radius-field) bg-(--color-badge-draft-bg)
                                                         px-2 py-0.5 text-2xs text-(--color-badge-draft-ink)">
                                                {{ __('system_admin::branch_module.always_on') }}
                                            </span>
                                        @endif

                                        <span x-cloak x-show="! on['{{ $module['code'] }}']"
                                              class="ms-2 rounded-(--radius-field) bg-(--color-badge-draft-bg)
                                                     px-2 py-0.5 text-2xs text-(--color-badge-draft-ink)">
                                            {{ __('system_admin::control.module_off') }}
                                        </span>
                                    </td>

                                    <td class="num text-(--color-ink-muted)"
                                        data-label="{{ __('system_admin::branch_module.column_off_elsewhere') }}">
                                        {{ $module['off_elsewhere'] > 0 ? $module['off_elsewhere'] : '—' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- বদল না হলে পটিটা নেই — কন্ট্রোল প্যানেলের হুবহু একই আচরণ।
                 "সংরক্ষণ" লেখা একটা পটি সবসময় ভেসে থাকলে ওটা আসবাব হয়ে
                 যায়, আর কেউ পড়ে না। --}}
            <div x-show="count > 0" x-cloak
                 class="fixed inset-x-0 bottom-(--spacing-bottom-nav) z-40 border-t border-(--color-border)
                        bg-(--color-surface-card) px-4 py-3 shadow-lg md:bottom-0">
                <div class="mx-auto flex max-w-3xl items-center gap-3">
                    <span class="text-sm">
                        <span class="num font-semibold" x-text="count"></span>
                        {{ __('system_admin::message.unsaved') }}
                    </span>

                    <span class="flex-1"></span>

                    <x-ui.button type="button" tone="secondary" x-data
                                 @click="$reload()">
                        {{ __('core.action.discard') }}
                    </x-ui.button>

                    <x-ui.button type="submit" tone="primary">{{ __('core.action.save') }}</x-ui.button>
                </div>
            </div>

            <x-ui.button type="submit" tone="primary">{{ __('core.action.save') }}</x-ui.button>
        </form>
    @endif
</x-layouts.app>
