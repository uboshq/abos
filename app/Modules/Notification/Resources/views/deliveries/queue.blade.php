{{-- ⭐ ডেলিভারির কিউ — অপেক্ষায়, চলছে, আবার চেষ্টা হবে; টেবিলটা `_jobs`-এ (বিজ্ঞপ্তি ব্যবস্থাপনা, ধাপ ২) --}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('notification::delivery.queue_title') }}</x-slot:title>

    <div class="space-y-4">
        @include('notification::deliveries._jobs', ['title' => __('notification::delivery.queue_title'), 'note' => __('notification::delivery.queue_note')])
    </div>
</x-layouts.app>
