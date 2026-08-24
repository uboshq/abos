<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * বাছাই করা রূপটা আর ঘরে ধরে না।
 *
 * ── কী ভাঙা ছিল ──────────────────────────────────────────────────────
 * `users.ui` ঘরটা ছোট নামের জন্য বানানো — `navy`, `classic`,
 * `salesforce`। থিম ইঞ্জিনের ধাপ ২-এ ওই একই ঘরে কোম্পানির নিজের
 * রূপের `public_id`-ও বসে, আর ওটা একটা ৩৬ অক্ষরের UUID।
 *
 * ফল: স্কিন বাছলে সেভের সময় MySQL কেটে দিত —
 * *"Data too long for column 'ui'"*। ধরা পড়েছে পরীক্ষায়, পর্দায় নয়।
 *
 * ── কেন একই ঘরে দুইটা জিনিস ──────────────────────────────────────────
 * আলাদা ঘর রাখলে প্রতিটা পাতায় "কোনটা ভরা" জিজ্ঞেস করতে হত, আর দুইটাই
 * ভরা থাকলে কোনটা জেতে সেই নিয়মও লিখতে হত। একটা ঘরে একটাই উত্তর — আর
 * `navy` আর একটা UUID কখনো একরকম দেখায় না।
 *
 * ── ৬৪ কেন ───────────────────────────────────────────────────────────
 * UUID ৩৬। ৬৪ রাখা হলো যাতে ভবিষ্যতে উপসর্গওয়ালা কোনো চাবি এলে
 * (`skin:…`) আবার মাইগ্রেশন লিখতে না হয় — ঘর চওড়া করা সস্তা, আর
 * সংকীর্ণ ঘরের ভুলটা নীরব।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('ui', 64)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('ui', 32)->nullable()->change();
        });
    }
};
