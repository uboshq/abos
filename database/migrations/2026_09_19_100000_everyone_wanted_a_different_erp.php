<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * সবাই আলাদা ERP চেয়েছিল।
 *
 * ── কেন কলামটা `ui`, `theme` নয় ─────────────────────────────────────
 * `users.theme` আগে থেকেই আছে, আর সে একটাই প্রশ্নের উত্তর দেয়: আলো না
 * অন্ধকার। ওটার সাথে এটার কোনো সম্পর্ক নেই — একজন Apps (Odoo) বেছে
 * অন্ধকারে কাজ করতে পারেন, আরেকজন Tiles (SAP) বেছে আলোয়। দুইটা আলাদা
 * অক্ষ, তাই দুইটা আলাদা কলাম।
 *
 * নামটা এক করলে ছয় মাস পরে কেউ `theme` দেখে ধরে নিতেন ওটা light/dark,
 * আর একটা `in:light,dark` যাচাই বসিয়ে আটটা চেহারার সাতটাই মুছে দিতেন।
 *
 * ── কেন ব্যবহারকারীর, কোম্পানির নয় ──────────────────────────────────
 * এক ডিপোর একটা কম্পিউটারে দিনে তিনজন বসেন। যিনি বিশ বছর SAP চালিয়ে
 * এসেছেন তাঁর হাত টাইলসেই চলে; যিনি Odoo-তে শিখেছেন তাঁর চোখ তালিকা
 * খোঁজে। কোম্পানি-স্তরে বাঁধলে দুইজনের একজনকে সারাজীবন অন্যের অভ্যাসে
 * কাজ করতে হত।
 *
 * অ্যাকসেন্ট ও ভাষার মতোই এটা ব্যবহারকারীর রেকর্ডে বসে, সেশনে নয় —
 * তাই বাড়ির ফোনে খুললেও একই ERP (নিয়ম ৯)।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            /*
             * ডিফল্ট `classic` — যা এখন সবাই দেখছেন ঠিক তাই।
             *
             * অন্য কিছু ডিফল্ট দিলে এই মাইগ্রেশনটা চলামাত্র প্রত্যেক
             * ব্যবহারকারীর ERP একরাতে বদলে যেত, কেউ না চাইতেই।
             */
            $table->string('ui', 16)->default('classic')->after('theme');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('ui');
        });
    }
};
