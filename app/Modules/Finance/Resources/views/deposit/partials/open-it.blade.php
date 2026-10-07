{{-- জমার নিজের পাতায় নিয়ে যায় — কিস্তি, মুনাফা, ভাঙা, আর ইতিহাস।

     ── কেন কাজগুলো এখানে নয় ─────────────────────────────────────────
     কিস্তি দিতে চাই তারিখ, টাকা আর কোন খাত। তিনটা ঘর এই কলামে গুঁজলে
     ছোট পর্দায় একটার ঘাড়ে আরেকটা পড়ত, আর টেবিলের স্ক্রলার প্যানেলটা
     কেটে দিত। --}}
<a href="{{ route('finance.deposit.show', ['issuer' => $issuer, 'deposit' => $deposit->id]) }}"
   class="inline-flex min-h-(--spacing-touch) items-center rounded-(--radius-field) px-2 text-sm
          text-(--color-link) transition-colors hover:bg-(--color-surface-hover) print-hide">
    {{ __('core.action.view') }}
</a>
