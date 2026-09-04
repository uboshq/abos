<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `visible_to_dealer` → `visible_to_customer`।
 *
 * ── কেন নামটা বদলাতে হলো ────────────────────────────────────────────
 * মালিকের সিদ্ধান্ত (৪ সেপ্টেম্বর ২০২৬): **এটা ডিলার পোর্টাল নয়,
 * গ্রাহক পোর্টাল।** ডিলার এগারো শিল্পের একটা ধরন মাত্র — যিনি
 * পোর্টালে ঢোকেন তিনি একজন গ্রাহক, আর তিনি ডিলার হতেও পারেন, না-ও
 * হতে পারেন।
 *
 * পুরনো নামটা রাখলে ছয় মাস পর কেউ পড়তেন "এই কারণটা ডিলাররা দেখেন"
 * আর ভাবতেন খুচরা গ্রাহক দেখেন না। **কলামটা আসলে কখনোই ধরন দেখেনি —
 * সে দেখে কে পোর্টালে ঢুকেছেন।**
 *
 * ── কেন পুরনো মাইগ্রেশনটা শুধরে দেওয়া হয়নি ──────────────────────────
 * `2026_10_16_100000_a_reason_the_dealer_should_never_read.php` চলে
 * গেছে, আর Laravel `migrations` টেবিলে **ফাইলের নামটাই** রাখে। ওটা
 * বদলালে সে ফাইলটাকে নতুন মাইগ্রেশন ভাবত আর আবার চালাত — তখন
 * `column already exists`-এ deploy থামত। **চলে যাওয়া মাইগ্রেশন
 * ইতিহাস; সংশোধন সবসময় নতুন মাইগ্রেশনে।**
 *
 * ⓘ ডাটাবেসে আজ যা আছে সবটাই পরীক্ষার সারি (মালিক, ৪ সেপ্টেম্বর)।
 * তবু `renameColumn` ব্যবহার করা হয়েছে, drop-and-add নয় — কারণ
 * **কোডটা আসল**: যেদিন সত্যিকারের ক্রেতা এই মাইগ্রেশনটা চালাবেন,
 * তাঁর সারিগুলোয় কে কোন কারণ দেখতে পান সেই সিদ্ধান্তটুকু অক্ষত
 * থাকতে হবে।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mdm_reason_codes', function (Blueprint $table): void {
            $table->renameColumn('visible_to_dealer', 'visible_to_customer');
        });
    }

    public function down(): void
    {
        Schema::table('mdm_reason_codes', function (Blueprint $table): void {
            $table->renameColumn('visible_to_customer', 'visible_to_dealer');
        });
    }
};
