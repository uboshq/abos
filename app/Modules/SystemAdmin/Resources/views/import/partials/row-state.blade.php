{{-- সারিটা নেওয়া যাবে কি না — আর না গেলে কেন।

     শুধু "ভুল" লিখলে ব্যবহারকারী ফাইলে গিয়ে অনুমান করতেন। কারণটা
     লেখা থাকলে তিনি সরাসরি ওই ঘরটা ঠিক করতে পারেন। --}}
@if ($row['errors'] === [])
    <x-ui.badge tone="success">{{ __('core.yes') }}</x-ui.badge>
@else
    <span class="text-2xs text-(--color-danger)">
        {{ implode(' · ', $row['errors']) }}
    </span>
@endif
