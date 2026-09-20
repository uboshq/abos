{{--
    আমানতের ধরনের নাম → তার নিয়মের পাতা।

    ⓘ "শর্ট টার্ম ডিপোজিট" পড়ে পরের প্রশ্ন হয় "এটার সুদ কত, মেয়াদ কত" —
    উত্তরটা ঐ ধরনের ফর্মে (২০ সেপ্টেম্বর ২০২৬)।

    @param $id     ধরনের আইডি
    @param $label  যা লেখা থাকবে
--}}
<a href="{{ route('finance.deposit_kind.edit', $id) }}"
   class="text-(--color-brand-600) underline-offset-2 hover:underline">{{ $label }}</a>
