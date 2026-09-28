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
        <span class="block text-2xs"
              :class="marginBelow(line) ? 'text-(--color-badge-danger-ink)' : 'text-(--color-ink-muted)'"
              x-text="marginText(line)"></span>
    </template>
@endcan
