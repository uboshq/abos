{{--
    সারির কাজগুলো — দেখা আর সম্পাদনা।

    ── ⭐ মালিকের ছক, ২১ সেপ্টেম্বর ২০২৬ ───────────────────────────────
    *"SL#, Code, Name, Type, Address, Mobile, Payable, Status, Action"* —
    শেষ কলামটা এতদিন ছিলই না।

    ── ⓘ গ্রাহকের তালিকার হুবহু একই ─────────────────────────────────
    দুইটা তালিকা পাশাপাশি চলে, তাই আচরণও এক ([[customer::partials
    .row-actions]])। ⚠️ আলাদা হলে ব্যবহারকারীকে দুইটা পর্দা আলাদা করে
    শিখতে হত।

    ── ⛔ সক্রিয়/নিষ্ক্রিয়ের বোতাম এখানে নেই, আর সেটা ইচ্ছাকৃত ─────────
    অবস্থার কলামের পিলটাই সেই বোতাম। ⓘ দুই জায়গায় একই কাজ রাখলে একদিন
    একটা বদলাত আর অন্যটা পুরনো আচরণে থেকে যেত।

    ── ⛔ আর "মুছুন" নেই ──────────────────────────────────────────────
    যে সরবরাহকারীর নামে একটাও বিল আছে তাঁকে মুছলে ঐ বিলগুলোর মালিক
    হারিয়ে যায় — খতিয়ানে টাকা থাকত, **কাকে দিতে হবে** তা থাকত না।

    ⚠️ নিষ্ক্রিয় করাটাই যা মানুষ আসলে চায়: নতুন কেনা বন্ধ, কিন্তু
    পুরনো হিসাব ও প্রদেয় যেমন আছে তেমনই থাকবে।
--}}
<div class="flex items-center justify-end gap-1">
    {{-- ⓘ বিস্তারিত সবসময় — দেখার অনুমতি না থাকলে সারিটাই আসত না। --}}
    <a href="{{ route('supplier.show', $supplier) }}"
       title="{{ __('core.action.view') }}"
       aria-label="{{ __('core.action.view') }} {{ $supplier->name() }}"
       class="rounded-(--radius-field) p-1.5 text-(--color-ink-muted)
              hover:bg-(--color-surface-hover) hover:text-(--color-ink)">
        <svg viewBox="0 0 24 24" aria-hidden="true" class="size-4 fill-current">
            <path d="M12 5c-5 0-9 4.5-10 7 1 2.5 5 7 10 7s9-4.5 10-7c-1-2.5-5-7-10-7Zm0 12a5 5 0 1 1 0-10 5 5 0 0 1 0 10Zm0-2.5a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5Z"/>
        </svg>
    </a>

    @can('supplier.update')
        <a href="{{ route('supplier.edit', $supplier) }}"
           title="{{ __('core.action.edit') }}"
           aria-label="{{ __('core.action.edit') }} {{ $supplier->name() }}"
           class="rounded-(--radius-field) p-1.5 text-(--color-ink-muted)
                  hover:bg-(--color-surface-hover) hover:text-(--color-ink)">
            <svg viewBox="0 0 24 24" aria-hidden="true" class="size-4 fill-current">
                <path d="M3 17.25V21h3.75L17.8 9.94l-3.75-3.75L3 17.25ZM20.7 7.04a1 1 0 0 0 0-1.41l-2.34-2.34a1 1 0 0 0-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83Z"/>
            </svg>
        </a>
    @endcan
</div>
