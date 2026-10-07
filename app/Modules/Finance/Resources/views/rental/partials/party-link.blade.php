{{--
    বাড়িওয়ালার নাম — আর তাঁর নিজের পাতা।

    ⓘ পক্ষের ধরন যে মডিউল ঘোষণা করে, পাতাটাও তার — তাই লিংকটা কোরের
    drill রেজিস্ট্রি দিয়ে ([[x-ui.drill]])। ⚠️ ব্যক্তির পাতা মাস্টারে,
    গ্রাহকের গ্রাহকে — এখানে কোনো ধরনের নাম লেখা নেই বলে নতুন ধরন এলেও
    এই ঘরটা বদলাতে হবে না।
--}}
<span class="inline-flex flex-col">
    <x-ui.drill :source="$row['party_type']" :id="$row['party_id']">{{ $row['name'] }}</x-ui.drill>

    {{-- ⓘ ধরনটা ছোট করে নিচে: একই নামের গ্রাহক আর ব্যক্তি দুইজন হতে পারেন --}}
    <span class="text-2xs text-(--color-ink-muted)">
        {{ __('core.source.'.$row['party_type']) }}
    </span>
</span>
