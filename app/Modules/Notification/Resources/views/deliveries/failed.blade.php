{{-- ⭐ ব্যর্থ-তালিকা (dead-letter) — আর নিজে চেষ্টা হবে না; টেবিলটা `_jobs`-এ (বিজ্ঞপ্তি ব্যবস্থাপনা, ধাপ ২) --}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('notification::delivery.failed_title') }}</x-slot:title>

    <div class="space-y-4">
        @include('notification::deliveries._jobs', ['title' => __('notification::delivery.failed_title'), 'note' => __('notification::delivery.failed_note')])
    </div>
</x-layouts.app>
