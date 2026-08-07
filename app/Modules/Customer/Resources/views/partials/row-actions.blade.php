{{--
    সারির কাজগুলো — দেখা আর সম্পাদনা।

    ── সক্রিয়/নিষ্ক্রিয়ের বোতাম এখানে নেই, আর সেটা ইচ্ছাকৃত ───────────
    অবস্থার কলামের পিলটাই এখন সেই বোতাম (মালিকের নমুনা, ২০২৬-০৮-০৭)।
    দুই জায়গায় একই কাজ রাখলে একদিন একটা বদলাত আর অন্যটা পুরনো আচরণে
    থেকে যেত — আর ব্যবহারকারীকেও ভাবতে হত দুইটার মধ্যে তফাত কী।

    ── মোছার বোতামও নেই ────────────────────────────────────────────────
    মালিকের তালিকায় "delete?" প্রশ্নচিহ্ন সহ লেখা ছিল, আর উত্তরটা না।
    যে গ্রাহকের নামে একটাও বিল আছে তাঁকে মুছে ফেলা মানে ওই বিলগুলোর
    মালিক হারিয়ে যাওয়া — খতিয়ানে টাকা থাকত, কার কাছে পাওনা তা থাকত না।

    নিষ্ক্রিয় করাটাই যা মানুষ আসলে চায়: দোকান বন্ধ হয়ে গেছে, নতুন বিল
    যাবে না, কিন্তু পুরনো হিসাব ও বকেয়া যেমন আছে তেমনই থাকবে।
--}}
<div class="flex items-center justify-end gap-1">
    {{-- বিস্তারিত — চোখের আইকন, মালিকের চাওয়া অনুযায়ী --}}
    <a href="{{ route('customer.show', $customer) }}"
       title="{{ __('core.action.view') }}"
       aria-label="{{ __('core.action.view') }} {{ $customer->name() }}"
       class="rounded-(--radius-field) p-1.5 text-(--color-ink-muted)
              hover:bg-(--color-surface-hover) hover:text-(--color-ink)">
        <svg viewBox="0 0 24 24" aria-hidden="true" class="size-4 fill-current">
            <path d="M12 5c-5 0-9 4.5-10 7 1 2.5 5 7 10 7s9-4.5 10-7c-1-2.5-5-7-10-7Zm0 12a5 5 0 1 1 0-10 5 5 0 0 1 0 10Zm0-2.5a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5Z"/>
        </svg>
    </a>

    @can('customer.update')
        <a href="{{ route('customer.edit', $customer) }}"
           title="{{ __('core.action.edit') }}"
           aria-label="{{ __('core.action.edit') }} {{ $customer->name() }}"
           class="rounded-(--radius-field) p-1.5 text-(--color-ink-muted)
                  hover:bg-(--color-surface-hover) hover:text-(--color-ink)">
            <svg viewBox="0 0 24 24" aria-hidden="true" class="size-4 fill-current">
                <path d="M3 17.25V21h3.75L17.8 9.94l-3.75-3.75L3 17.25ZM20.7 7.04a1 1 0 0 0 0-1.41l-2.34-2.34a1 1 0 0 0-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83Z"/>
            </svg>
        </a>
    @endcan
</div>
