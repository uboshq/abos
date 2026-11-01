{{--
    মডিউলের ছক — প্রথম ট্যাব।

    ── কেন ছক, কার্ড নয়, ৩০ আগস্ট ২০২৬ ────────────────────────────────
    মালিকের কথা: *"এগুলো একটা টেবিলে রাখ।"*

    প্রথমে প্রতিটা মডিউল একটা করে কার্ড ছিল, আর বারোটা কার্ডে পাতাটা
    দাঁড়াল ১,৩৫০ পিক্সেল — অথচ তথ্য মোটে বারো লাইনের। ছকে সুইচগুলো
    **একই খাড়া রেখায়** থাকে, তাই কে চালু আর কে বন্ধ সেটা এক নজরে
    পড়া যায়; কার্ডে প্রতিটা সুইচ আলাদা জায়গায় বলে চোখকে বারোবার
    খুঁজতে হত।

    ── কেন গুনতি দুইটা ────────────────────────────────────────────────
    "হিসাব" বন্ধ করলে ঠিক কতটা বন্ধ হচ্ছে — চারটা ভাগ, তেত্রিশটা
    পর্দা — সেটা না জানিয়ে সুইচটা দিলে সিদ্ধান্তটা অন্ধ হত।
--}}
{{-- ⭐ মডিউলের কার্ড — টেবিলের বদলে (কন্ট্রোল প্যানেলের পরিকল্পনা, মালিকের অনুমোদিত, ২১ অক্টোবর ২০২৬, দফা ৫):
     সুইচ, এক লাইনে কী করে, বন্ধ করলে কী হবে, আর "সেটিংস ›"। ⓘ ঘরের নাম আর মান আগের মতো (settings[…], scope[]) — সংরক্ষণ,
     বন্ধের আগে নিশ্চিত করা ([[forms.js]] confirmOff) আর গোনার পটি বদলায়নি। --}}
<div data-module-cards class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
    @foreach (collect($tree)->reject(fn ($m) => $m['essential'] ?? false) as $module)
        @php $moduleOn = $settings->get($module['key'], true); @endphp
        <div data-module-card="{{ $module['code'] }}" data-boxed
             class="flex min-w-0 flex-col gap-2 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-3"
             :class="on['{{ $module['key'] }}'] ? '' : 'opacity-75'">
            <div class="flex items-start gap-3">
                <span class="min-w-0 flex-1">
                    <span class="block font-semibold text-(--color-ink)">{{ $module['label'] }}</span>
                    <span data-module-about class="block text-xs text-(--color-ink-muted)">{{ __('system_admin::control.about.'.$module['code']) }}</span>
                </span>
                {{-- ⓘ চেকবক্সটাই সুইচ — দেখতে সুইচ (role="switch"), পাঠায় আগের মতোই --}}
                <label class="relative inline-flex shrink-0 cursor-pointer items-center">
                    <input type="checkbox" role="switch" name="settings[{{ $module['key'] }}]" value="1"
                           @checked($moduleOn) data-was="{{ $moduleOn ? '1' : '' }}"
                           x-model="on['{{ $module['key'] }}']"
                           aria-label="{{ $module['label'] }}"
                           data-module-label="{{ $module['label'] }}"
                           class="peer sr-only">
                    <span aria-hidden="true"
                          class="h-6 w-11 rounded-full bg-(--color-border-strong) transition-colors peer-checked:bg-(--color-brand-600)
                                 peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-(--color-brand-500)"></span>
                    <span aria-hidden="true"
                          class="absolute start-0.5 size-5 rounded-full bg-white shadow transition-transform peer-checked:translate-x-5 rtl:peer-checked:-translate-x-5"></span>
                </label>
                <input type="hidden" name="scope[]" value="{{ $module['key'] }}">
            </div>
            <div class="flex items-center gap-2 text-2xs text-(--color-ink-muted)">
                <span class="tabular">{{ __('system_admin::control.column_screens') }}: {{ $module['row_count'] }}</span>
                <span x-cloak x-show="! on['{{ $module['key'] }}']"
                      class="rounded-(--radius-field) bg-(--color-badge-draft-bg) px-2 py-0.5 text-(--color-badge-draft-ink)">
                    {{ __('system_admin::control.module_off') }}
                </span>
                <a href="{{ route('system_admin.control-panel', ['tab' => $module['code']]) }}" data-module-settings
                   class="ms-auto font-semibold text-(--color-link) hover:underline">{{ __('system_admin::control.settings_link') }}</a>
            </div>
            {{-- ⭐ বন্ধ করার আগে প্রভাব — টিক তুলতেই কার্ডেই দেখায়, সংরক্ষণের আগে --}}
            <span x-cloak x-show="! on['{{ $module['key'] }}']" data-module-impact
                  class="text-2xs text-(--color-badge-warning-ink)">
                {{ __('system_admin::control.off_impact', ['screens' => $module['row_count']]) }}
            </span>
        </div>
    @endforeach
</div>

@php($foundation = collect($tree)->filter(fn ($m) => $m['essential'] ?? false))
@if ($foundation->isNotEmpty())
    <section data-boxed data-foundation class="mt-4 rounded-(--radius-card) border border-(--color-border) bg-(--color-surface-card) p-4">
        <h2 class="flex flex-wrap items-center gap-1 text-sm font-semibold"><x-ui.icon name="lock" :size="14" /> {{ __('system_admin::control.foundation') }}
            <span class="ms-1 text-2xs font-normal text-(--color-ink-muted)">{{ __('system_admin::control.foundation_note') }}</span></h2>
        <ul class="mt-2 flex flex-wrap gap-2">
            @foreach ($foundation as $module)
                <li data-foundation-module="{{ $module['code'] }}"
                    class="rounded-(--radius-field) border border-(--color-border) bg-(--color-surface-muted) px-3 py-1 text-sm">
                    <a href="{{ route('system_admin.control-panel', ['tab' => $module['code']]) }}" class="hover:underline">{{ $module['label'] }}</a>
                </li>
            @endforeach
        </ul>
    </section>
@endif
