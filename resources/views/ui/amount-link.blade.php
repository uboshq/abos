{{--
    তালিকার ঘরে একটা ক্লিকযোগ্য অঙ্ক।

    x-ui.table-এর render ক্লোজার একটা view ফেরত দেয়, কম্পোনেন্ট নয় —
    তাই এই এক লাইনের মোড়কটা লাগে। সব যুক্তি x-ui.amount-এ।
--}}
<x-ui.amount :value="$value"
             :href="$href ?? null"
             :tone="$tone ?? null"
             :blank-on-zero="$blankOnZero ?? false" />
