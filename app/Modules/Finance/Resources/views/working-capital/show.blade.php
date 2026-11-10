{{-- ⭐ চলতি মূলধনের পুরো পাতা — কেবল মালিকের চাবিতে ([[WorkingCapitalController]]); ঘরটা আগের অনুমোদিত নমুনার হুবহু --}}
<x-layouts.app :menu="$menu">
    <x-slot:title>{{ __('finance::working_capital.title') }}</x-slot:title>

    @include('finance::dashboard.working-capital', ['wc' => $wc])
</x-layouts.app>
