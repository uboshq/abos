<?php

declare(strict_types=1);

namespace App\Core\Contracts;

/**
 * ⭐ এক পক্ষের যে বিলগুলো এখনো পুরো শোধ হয়নি — রসিদের "কোন বিলের বিপরীতে" তালিকা (Accounts-Finance অডিট ম১, ৪ অক্টোবর ২০২৬)।
 *
 * ⛔ আগে হিসাবের নিয়ন্ত্রক নিজেই বিক্রয়ের টেবিলে কাঁচা কোয়েরি চালাত, আর বিলের অবস্থা খুঁজত 'posted' — অথচ পাকা বিক্রয়
 * বিল 'confirmed'। তালিকা তাই সবসময় খালি ছিল। আর বাকিটা নিজের মতো মাপত (ফেরত বাদ যেত না), বিলের নিজের পাতার সাথে মিলত না।
 *
 * ⓘ এখন বাকি মাপে বিলের মালিক মডিউল, নিজের একমাত্র নিয়মে (বিক্রয়: [[SalesInvoice::dueAmount()]])। হিসাব কেবল এই চুক্তি চেনে —
 * বিক্রয় বন্ধ থাকলে [[NoPartyOpenBills]] খালি তালিকা দেয়, মিথ্যা তালিকা নয়।
 */
interface PartyOpenBills
{
    /**
     * পুরনো বিল আগে; `against_type` আর `id` জোড়াটাই রসিদের "বিপরীতে" ঘরে বসে।
     *
     * ⭐ `due_date` (শেষ দিন), `days_late` (শেষ দিন পেরিয়ে কত দিন, নইলে ০) আর `total` (বিলের মূল টাকা) — মালিকের রসিদ-পরিশোধ নকশার
     * খোলা বিলের কলাম (মিল-তালিকা দফা ৩৪, ২৪ অক্টোবর ২০২৬)। ⓘ পুরনো ঘর যেমন ছিল, তাই পুরনো পাঠক ভাঙে না।
     * ⓘ `public_id` — এক রসিদে বহু বিল পাঠানোর চাবি (cb-ma-4, `bills[i][id]`); ক্রমিক `id` বাইরে দেওয়া হয় না সেখানে।
     *
     * ⭐ `kind` — invoice, credit_note বা debit_note। `$withNotes` হলে কোনো বিলে না-বাঁধা পাকা নোটও নিজের সারি পায় (মিল-তালিকা দফা ৩৫,
     * fe ২৫ অক্টোবর ২০২৬): ক্রেডিট নোটের `outstanding` আর `total` ঋণাত্মক, ডেবিট নোটের ধনাত্মক; শেষ দিন নোটের দিন।
     * ⛔ ডিফল্টে নোট আসে না — আজকের রসিদের ফর্ম সারিটাকে "কোন বিলের বিপরীতে" বাছাই ভাবত; নোট দেখায় কেবল যে পর্দা চায়।
     *
     * @return list<array{kind: string, against_type: string, id: int, no: string, date: string, age: int, outstanding: string, due_date: string, days_late: int, total: string, public_id: string}>
     */
    public function openBills(string $partyType, int $partyId, int $limit = 50, bool $withNotes = false): array;
}
