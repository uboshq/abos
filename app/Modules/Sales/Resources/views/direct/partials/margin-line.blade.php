{{--
    কার্টের সারিতে মার্জিন — কেবল খরচের চাবিধারীর জন্য (NEXUS §৩২)।

    ⓘ বসে কার্টের পণ্যের ঘরে, লটের নিচে ([[cart.blade.php]]-এর
    `x-for="(line, i) in lines"`-এর ভিতরে), তাই `line` এখানে জানা।

    ⛔ চাবি না থাকলে খণ্ডটা কিছুই আঁকে না, আর নিয়ন্ত্রক খরচের তালিকাও
    পাঠায় না (`marginCosts` খালি) — পাতার উৎসেও খরচ থাকে না।

    ⚠️ সংখ্যাটা আনুমানিক, FIFO স্তরের মাথা থেকে। ⭐ আসল দেয়াল সেবায়
    ([[MarginGuard]]) — পর্দা কেবল আগে থেকে বলে দেয়, যাতে বিক্রেতা
    "নিশ্চিত" চাপার আগেই জানেন কোন সারিটা থামবে।

    ⓘ CSP-Alpine: কেবল কম্পোনেন্টের পদ্ধতি ডাকা হয়, কোনো অ্যাসাইনমেন্ট নয়।
--}}
@can('sales.cost.view')
    <template x-if="marginShown(line)">
        {{-- ⭐ গোল ছোট চিপ — মালিক, ৪ অক্টোবর ২০২৬ ("hilite kore round box"); সীমার নিচে লাল, নাহলে সবুজ --}}
        <span class="ms-1 inline-block rounded-full px-2 text-2xs font-semibold"
              :class="marginBelow(line) ? 'bg-(--color-badge-danger-bg) text-(--color-badge-danger-ink)' : 'bg-(--color-badge-success-bg) text-(--color-badge-success-ink)'"
              x-text="marginText(line)"></span>
    </template>
@endcan
