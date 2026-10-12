{{--
    ⛔ খবরের কাগজটা আর নাগালে নেই — খোলা হয় না, কারণটা বলা হয় (মালিকের স্পেক §১৩; বিজ্ঞপ্তি ব্যবস্থাপনা, ধাপ ১)।
    ⓘ চেষ্টাটা নিরীক্ষার খাতায় যায় ([[NotificationController::open()]])।
--}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('core.notify.no_access_title') }}</x-slot:title>

    <div data-notify-no-access class="mx-auto max-w-xl space-y-3 rounded-(--radius-card) border border-(--color-border)
                bg-(--color-surface-card) p-6">
        <h1 class="text-lg font-semibold">{{ __('core.notify.no_access_title') }}</h1>
        <p class="text-sm text-(--color-ink-muted)">{{ __('core.notify.no_access_body') }}</p>
        <a href="{{ route('notifications.index') }}" class="text-sm text-(--color-brand-500) hover:underline">{{ __('core.notify.mine') }}</a>
    </div>
</x-layouts.app>
