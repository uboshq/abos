{{--
    প্রতিষ্ঠানের সেটিংস — সব মডিউল, এক পর্দায়।

    সুইচগুলো এখানে হাতে লেখা নেই — প্রতিটা মডিউলের module.php যা ঘোষণা
    করে, এই পর্দা তা-ই দেখায় (নিয়ম ৭)। নতুন একটা ঐচ্ছিক ফিল্ড যোগ করার
    সময় তার সুইচটা একই ফাইলে লেখা হয়, আর এই পর্দায় সেটা নিজে থেকেই আসে।

    ── ⚠️ কেন বাঁ পাশে মডিউলের তালিকা, লম্বা এক কলাম নয় ────────────────
    প্রথম খসড়ায় দশটা মডিউল একটার নিচে একটা বসানো ছিল, আর পর্দাটা
    দাঁড়িয়েছিল **৬,০৯৪ পিক্সেল লম্বা**। ⓘ একটা সুইচ বদলাতে দশটা কার্ড
    পেরোতে হত, আর কোনটা কোথায় তা মনে রাখা ছাড়া উপায় ছিল না।

    ⛔ ওটা সেটিংসের একটা তালিকা, সেটিংসের পর্দা নয়। D365 · SAP · Odoo
    তিনটাই একই কাজ করে: বাঁয়ে বিষয়ের তালিকা, ডানে একটা বিষয়।

    ── ⭐ ফর্মটা তবু একটাই ─────────────────────────────────────────────
    ট্যাবগুলো কেবল দেখা-না-দেখা (`hidden`), আলাদা ফর্ম নয় — তাই একবার
    "সংরক্ষণ" চাপলে সব মডিউলের বদল একসাথে বসে। ⚠️ প্রতি ট্যাবে আলাদা
    ফর্ম হলে কেউ দুইটা ট্যাবে বদল করে একবার সেভ করতেন, আর অর্ধেকটা
    নীরবে হারিয়ে যেত।
--}}
@inject('settingsGate', 'App\Core\Services\SettingsService')

<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('system_admin::settings.title') }}</x-slot:title>

    <x-slot:header>
        <x-ui.page-header :title="__('system_admin::settings.title')"
                          :subtitle="__('system_admin::settings.note')" />
    </x-slot:header>

    @if (session('saved'))
        <div role="status"
             class="mb-4 rounded-(--radius-field) bg-(--color-badge-success-bg) px-3 py-2 text-sm
                    text-(--color-badge-success-ink)">
            {{ session('saved') }}
        </div>
    @endif

    {{-- ⭐ জরুরি সেটিংয়ের বদল — অপেক্ষায়, আটকানো, অনুমোদন (দফা ৭) --}}
    @include('system_admin::settings.partials.changes')
    @include('system_admin::settings.partials.board', ['modules' => $modules])
</x-layouts.app>
