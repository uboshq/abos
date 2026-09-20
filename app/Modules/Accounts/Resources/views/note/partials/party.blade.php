{{--
    কাকে দেওয়া হলো — আর নামটা তাঁর নিজের পাতায় নিয়ে যায়।

    ⓘ ঠিকানাটা সেবা থেকেই আসে ([[PartyRegistry::routesOf]]), তাই এখানে
    "গ্রাহক হলে এই রুট" লেখা নেই।
--}}
@php $key = $note->party_type.':'.$note->party_id; @endphp

@if (! empty($routes[$key]))
    <a href="{{ route($routes[$key][0], $routes[$key][1]) }}"
       class="text-(--color-brand-600) underline-offset-2 hover:underline">{{ $names[$key] ?? $key }}</a>
@else
    {{ $names[$key] ?? $key }}
@endif
