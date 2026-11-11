{{-- ⭐ চলতি মূলধনের পুরো পাতা — কেবল মালিকের চাবিতে ([[WorkingCapitalController]]); ঘরটা আগের অনুমোদিত নমুনার হুবহু --}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('finance::working_capital.title') }}</x-slot:title>

    {{-- ⓘ তারিখ বাছার ঘর আছে — ভুল তারিখে কেন দেখাল না, তা বলার জায়গা --}}
    <x-ui.errors />

    @include('finance::dashboard.working-capital', ['wc' => $wc])
</x-layouts.app>
