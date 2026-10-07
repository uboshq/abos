{{--
    জমার নম্বর — নিজের পাতায় খোলে।

    ── ⓘ কেন লিংক, ২০ সেপ্টেম্বর ২০২৬ ────────────────────────────────
    মালিকের কথা: *"sob jaygay hyper link dewar kotha but notun kaje kotaw
    hyperlink dicche na"*। ⛔ নম্বরটা এতদিন নিষ্প্রাণ লেখা ছিল, অথচ
    ব্যবহারকারী নম্বর দেখেই ওটার পাতায় যেতে চান — আর যেতে হত শেষ
    কলামের বোতাম খুঁজে।

    ⓘ শেষ কলামের "খুলুন" বোতামটা রয়ে গেছে: ছোট পর্দায় আঙুলের জন্য ওটাই
    সহজ, আর দুইটা একই জায়গায় নামে।
--}}
<a href="{{ route('finance.deposit.show', [
        'issuer' => $deposit->kind?->issuer ?? 'bank',
        'deposit' => $deposit->id,
    ]) }}"
   class="text-(--color-brand-500) underline-offset-2 hover:underline">
    {{ $deposit->document_no }}
</a>
