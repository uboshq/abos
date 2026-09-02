<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * যে সতর্কতা এক পাতার বেশি বাঁচত না।
 *
 * ── কী ভাঙা ছিল ──────────────────────────────────────────────────────
 * [[SalesInvoiceService]]-এ দরের সতর্কতার পাশে মন্তব্য লেখা আছে:
 *
 * > "পর্দায় দেখিয়ে ফেলে দিলে কেউ পরে বলতে পারত না কোন সারিতে সতর্কতা
 * > এসেছিল। **সারির বিবরণে লেখা থাকলে ছয় মাস পরেও কাগজ দেখেই বোঝা
 * > যায়।**"
 *
 * কোডটা ঠিক উল্টোটা করত: সতর্কতাগুলো জমা হত একটা অ্যারেতে, আর শেষে
 * `session()->flash()` — অর্থাৎ **পরের এক পাতা পর্যন্ত টিকত, তারপর
 * নেই**। মন্তব্যটা যে জিনিসটাকে সমস্যা বলছিল, কোডটা ঠিক সেটাই করত।
 *
 * ── ভ্যাটেও একই ফাঁক ─────────────────────────────────────────────────
 * ক্লায়েন্ট ভ্যাটের অঙ্ক **পাঠালে** সেটা পণ্যের নিজের হারের সাথে
 * কোথাও মেলানো হত না। ছাড়ের সীমা আছে; ভ্যাটের কোনো সীমা নেই। ফলে
 * হাতে লেখা একটা অঙ্ক আর হারের অঙ্ক আলাদা হলে **কোথাও কোনো চিহ্ন
 * থাকত না**।
 *
 * দুইটার সমাধান এক, আর সেটাই এই মাইগ্রেশন: সারিতে দুইটা ঘর, যেখানে
 * লেখা থাকে **"এই সংখ্যাটা নিয়মের বাইরে, আর কতটা"**।
 *
 * ── কেন বাক্য নয়, সংখ্যা ─────────────────────────────────────────────
 * প্রথমে ভেবেছিলাম একটা `rule_note` ঘরে বাংলা বাক্যটাই লিখে রাখব।
 * কিন্তু তাহলে **ভাষাটা ডাটাবেজে জমে যেত**: বাংলায় লেখা সারি ছয় মাস
 * পরে একজন ইংরেজি ব্যবহারকারীর পর্দায়ও বাংলাতেই দেখাত, আর অনুবাদ
 * বদলালে পুরনো সারিগুলো পুরনো কথাই বলত।
 *
 * সংখ্যা রাখলে বাক্যটা পর্দায় তৈরি হয়, পাঠকের ভাষায়। আর সংখ্যা
 * ছাঁকা যায়: "এই মাসে যেসব বিলে দর ১০%-এর বেশি নেমেছে" — বাক্য দিয়ে
 * ওই প্রশ্নের উত্তর দেওয়া যেত না।
 *
 * ── NULL-এর মানে ─────────────────────────────────────────────────────
 * **"কোনো ব্যতিক্রম হয়নি"**, আর সেটাই বেশিরভাগ সারির সত্যি। শূন্য
 * বসালে "মেপে দেখা হয়েছে, পার্থক্য শূন্য" আর "মাপাই হয়নি" — দুইটা
 * এক দেখাত, অথচ পুরনো সব সারি দ্বিতীয় দলের।
 */
return new class extends Migration
{
    /**
     * যেসব কাগজে হাতে লেখা ভ্যাট আসতে পারে।
     *
     * চারটাই [[CalculatesSalesLines::lineFigures()]] বা তার ক্রয়-জোড়া
     * ব্যবহার করে — অর্থাৎ ঠিক এই চারটাতেই ক্লায়েন্টের পাঠানো অঙ্ক
     * পণ্যের হারকে ঢেকে দিতে পারে।
     */
    private const TAX_LINES = [
        'sal_invoice_lines',
        'sal_order_lines',
        'pur_bill_lines',
        'pur_order_lines',
    ];

    /**
     * দরের নিয়ম আজ কেবল বিক্রয় চালানে চলে।
     *
     * বাকি কাগজে ঘরটা যোগ করা হয়নি ইচ্ছে করে: যে ঘর কেউ কোনোদিন ভরে
     * না, সেটা পরের জনকে ভুল বোঝায় — সে ধরে নেয় নিয়মটা ওখানেও আছে,
     * আর খালি দেখে ভাবে কিছু ভাঙা।
     */
    private const PRICE_LINES = ['sal_invoice_lines'];

    public function up(): void
    {
        foreach (self::TAX_LINES as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'tax_variance')) {
                continue;
            }

            Schema::table($table, function (Blueprint $t): void {
                // হাতে দেওয়া অঙ্ক − হারের অঙ্ক। ঋণাত্মকও হতে পারে,
                // আর সেটাই বেশি আগ্রহের: কম ভ্যাট নেওয়া হয়েছে।
                $t->decimal('tax_variance', 18, 4)->nullable()->after('tax');
            });
        }

        foreach (self::PRICE_LINES as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'price_variance')) {
                continue;
            }

            Schema::table($table, function (Blueprint $t): void {
                // মান দাম থেকে শতকরা কত সরে আছে। ঋণাত্মক = কমে বেচা।
                $t->decimal('price_variance', 9, 4)->nullable()->after('rate');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TAX_LINES as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'tax_variance')) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn('tax_variance'));
            }
        }

        foreach (self::PRICE_LINES as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'price_variance')) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn('price_variance'));
            }
        }
    }
};
