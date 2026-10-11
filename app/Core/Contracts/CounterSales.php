<?php

declare(strict_types=1);

namespace App\Core\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * কাউন্টার বিক্রয়ের যে কাজগুলো বিক্রয়ের ঘরে থাকে — বিল, আদায়, ফেরত, বাকির সীমা, সই আর আজকের বিক্রি
 * (পিওএস ধাপ ২, ১১ অক্টোবর ২০২৬; docs/cloud-tasks/pos-module.md §৪.২)।
 *
 * ── ⭐ কেন একটা চুক্তি ────────────────────────────────────────────────
 * কাউন্টার এখন নিজের মডিউলে (পিওএস), কিন্তু **বিল আর তার নম্বর (S ধারা), বাকির সীমা, খাতায় লেখা আর
 * মাল বের হওয়া** বিক্রয়েরই থাকে — মালিকের সিদ্ধান্ত। ⓘ তাই কাউন্টার বিক্রয়ের সেবাগুলোর নাম জানে না,
 * কেবল এই চুক্তিটা চায়; বিক্রয় বাস্তবায়ন দেয় (`module.php`-র `bindings`)।
 *
 * ⛔ এখানে নতুন কোনো নিয়ম নেই — প্রতিটা পদ্ধতি বিক্রয়ের আগের সেবাকে হুবহু ডাকে। তাই আচরণ বদলায় না:
 * একই বৈধতা, একই তালা, একই ভুলের বার্তা।
 *
 * ⓘ কোর মডিউলের ক্লাসের নাম জানে না (§১৯.৭), তাই কাগজগুলো এখানে `Model` — বাস্তবে বিক্রয়ের বিল, আদায় আর ফেরত।
 */
interface CounterSales
{
    /** খসড়া বিল — নম্বর বিক্রয়ের S ধারা থেকে। */
    public function draftInvoice(array $data, array $lines): Model;

    /** ধরে রাখা খসড়া বিল নতুন কার্টে বদলানো। */
    public function redraftInvoice(Model $invoice, array $data, array $lines): Model;

    /** ছাড়ের সই আছে কি না — না থাকলে বা সীমার উপরে হলে ছোঁড়ে। */
    public function assertDiscountSigned(Model $invoice): void;

    /** বিল নিশ্চিত — খাতা, মাল বের হওয়া আর বাকির সীমার পরীক্ষা বিক্রয়েই। */
    public function confirmInvoice(Model $invoice, string $payingNow = '0'): Model;

    /** গ্রাহকের সারিতে তালা — বাকির সীমা গোনার আগে। */
    public function lockCustomer(Model|int $customer): Model;

    public function draftCollection(array $data, array $lines): Model;

    public function confirmCollection(Model $collection): Model;

    public function draftReturn(array $data, array $lines): Model;

    public function confirmReturn(Model $return): Model;

    /** কাগজটার উপর অপেক্ষমাণ সই (যেমন ছাড়), না থাকলে `null`। */
    public function pendingSignature(Model $document, string $action): ?Model;

    /** কাউন্টারে দাঁড়িয়ে সই — যিনি সই দিলেন তাঁকে ফেরত দেয়। */
    public function signAtCounter(Model $approval, string $email, string $password, ?string $remarks = null): Model;

    /** আজ আমার কাউন্টারে কত বিক্রি — সংজ্ঞাটা বিক্রয়ের সংখ্যার ঘরে, একবার। */
    public function salesTodayAtMyCounter(): string;
}
