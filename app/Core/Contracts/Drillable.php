<?php

declare(strict_types=1);

namespace App\Core\Contracts;

/**
 * "এই সংখ্যাটা কোথা থেকে এল" — নিয়ম ১।
 *
 * যেকোনো মডেল যা কোনো রিপোর্টের সংখ্যায় অবদান রাখে, সে এটা বাস্তবায়ন করে।
 * তখন ledger_entries-এর source_type/source_id জোড়া থেকে কোর নিজেই বের করে
 * নিতে পারে কোন ডকুমেন্ট, তার নম্বর কী, আর কোন স্ক্রিনে গেলে সেটা দেখা যাবে।
 *
 * প্রতিটা রিপোর্টে আলাদা করে "এই টাইপ হলে এই লিংক" লেখার বদলে একটাই মানচিত্র
 * (module.php-র drill_sources) — নাহলে নতুন কোনো ডকুমেন্ট টাইপ যোগ হলে
 * পুরনো রিপোর্টগুলোতে সেটা ক্লিকযোগ্য হয় না, আর কেউ খেয়ালও করে না।
 */
interface Drillable
{
    /** ledger_entries.source_type-এ যে মান বসে। */
    public static function drillSourceType(): string;

    /** ব্যবহারকারী যে নম্বরটা চেনে — INV-2026-0001। */
    public function drillDocumentNo(): string;

    /** এক লাইনের বিবরণ, তালিকায় দেখানোর জন্য। */
    public function drillLabel(): string;

    /** এই ডকুমেন্টটা খোলার রুট — ['sales.invoice.show', ['invoice' => 12]] */
    public function drillRoute(): array;
}
