{{-- ⭐ সেলস ইন্টেলিজেন্স — "শীঘ্রই আসছে" (মালিক, ২৮ নভেম্বর ২০২৬: প্রতিটা মেনু দেখা যাবে, পাতায় "শীঘ্রই আসছে")।
     ⓘ কিছু লেখে না, কিছু পড়ে না; আসল পর্দা এলে এই পাতা সরে যায়। --}}
<x-layouts.app :menu="$menu ?? []">
    <x-slot:title>{{ $title }}</x-slot:title>

    <div class="space-y-4">
        <x-ui.page-header :title="$title" />

        <section class="si-soon" data-si-soon>
            <div class="si-soon-badge"><x-ui.licon name="hourglass" :size="22" /> {{ __('sales_intel::soon.coming') }}</div>
            <h2 class="si-soon-title">{{ $title }}</h2>
            <p class="si-soon-what">{{ $what }}</p>
            <p class="si-soon-phase">{{ __('sales_intel::soon.phase', ['n' => $phase]) }}</p>
            <p class="si-soon-pilot">{{ __('sales_intel::soon.pilot') }}</p>
        </section>
    </div>
</x-layouts.app>
