{{--
    ⛔ খবরের কাগজটা আর নাগালে নেই — খোলা হয় না, কারণটা বলা হয় (মালিকের স্পেক §১৩; বিজ্ঞপ্তি ব্যবস্থাপনা, ধাপ ১)।
    ⭐ কাগজটা মুছে গেলে সেটাই বলা হয় — "কাগজটা আর নেই" (`gone`; fe, ২৪ অক্টোবর ২০২৬): মোছা কাগজের খবরে "অনুমতি নেই"
    বললে মানুষ অনুমতি চাইতে যেতেন, অথচ খোলার মতো কিছুই নেই।
    ⓘ চেষ্টাটা নিরীক্ষার খাতায় যায় ([[NotificationController::open()]])।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ ($gone ?? false) ? __('core.notify.gone_title') : __('core.notify.no_access_title') }}</x-slot:title>

    <div data-notify-no-access @if ($gone ?? false) data-notify-gone @endif class="mx-auto max-w-xl space-y-3 rounded-(--radius-card) border border-(--color-border)
                bg-(--color-surface-card) p-6">
        <h1 class="text-lg font-semibold">{{ ($gone ?? false) ? __('core.notify.gone_title') : __('core.notify.no_access_title') }}</h1>
        <p class="text-sm text-(--color-ink-muted)">{{ ($gone ?? false) ? __('core.notify.gone_body') : __('core.notify.no_access_body') }}</p>
        <a href="{{ route('notifications.index') }}" class="text-sm text-(--color-brand-500) hover:underline">{{ __('core.notify.mine') }}</a>
    </div>
</x-layouts.app>
