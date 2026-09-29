<?php

declare(strict_types=1);

use App\Core\Support\CompanyContext;
use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

/**
 * ⭐ আগের তেরোটা ছাপার রূপ সরল — মালিকের নির্দেশ, ৩০ সেপ্টেম্বর ২০২৬: *"ager sokol sample bad daw
 * ekta kore rakho sudhu kajer jonno"*, *"total 6 tab e mot 78 ti sorabe"*।
 *
 * ⛔ পর্দা থেকে কার্ড সরানো যথেষ্ট নয়: যে কোম্পানি আগে "ঘেরা ছক" বেছে রেখেছিল তার কাগজ ঐ রূপেই
 * ছাপত, আর বদলানোর কোনো পথ থাকত না। ⭐ তাই প্রতিটা কাগজের বাছা রূপ মুছে ডিফল্টে ("সাধারণ";
 * কাউন্টারের রসিদে "ছোট") ফেরা — [[SettingsService::reset()]]-এর মতো মডেল ধরে, যাতে অডিটে ওঠে।
 *
 * ⓘ অংশ আর কলামের সুইচ (`print.*.parts`, `print.*.columns`) থাকে — ওগুলো "সাধারণ"-এরই সুইচ।
 */
return new class extends Migration
{
    public function up(): void
    {
        Setting::query()
            ->where('key', 'like', 'print.%.format')
            ->get()
            /* ⓘ নিজের কোম্পানির ভিতরে — অডিটের সারি ঠিক কোম্পানিতে বসে */
            ->each(fn (Setting $row) => $row->company_id === null
                ? $row->delete()
                : CompanyContext::forCompany((int) $row->company_id, fn () => $row->delete()));
    }

    /** ⓘ ফেরত আনার কিছু নেই — কোন কোম্পানি কোন রূপ বেছেছিল তা অডিটের খাতায় আছে */
    public function down(): void {}
};
