{{-- নিয়ম ১: প্রতিটা সারি তার উৎসে নিয়ে যায়, আর এখানে সেটাই সবচেয়ে
     জরুরি — সংখ্যাটা দেখার জন্য নয়, সারানোর জন্য। --}}
@if ($finding->isDrillable())
    <x-ui.drill :source="$finding->sourceType" :id="$finding->sourceId">{{ $finding->what }}</x-ui.drill>
@else
    {{ $finding->what }}
@endif
