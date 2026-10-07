<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * কম্বো খোলে কয়েকটা পণ্য **একসাথে** থাকলে — অথচ ইঞ্জিন বিলটা একবারে একটা সারি দেখত।
 *
 * ── ⭐ স্পেক §৭-ছ ও §৭-জ ─────────────────────────────────────────────
 * কম্বো (*"ক + খ একসাথে নিলে ১০% ছাড়"*) আর বান্ডল (*"একাধিক পণ্য
 * একসাথে"*)। ⓘ দুইটার শর্তই একটা **পণ্যের তালিকা**, আর প্রতিটা পণ্যের
 * একটা ন্যূনতম পরিমাণ।
 *
 * ── ⚠️ কেন `promotion_conditions`-এ নয় ──────────────────────────────
 * ⓘ ওখানে `kind = product` আর `target_id` আছে, তাই প্রথমে মনে হয় ওখানেই
 * বসানো যায়। ⛔ কিন্তু ঐ টেবিলের সারিগুলো **বিকল্প** — স্ল্যাবের ধাপ,
 * যার **একটা** মিললেই হয়। ⚠️ কম্বোর সারিগুলো উল্টো: **সবগুলো** একসাথে
 * মিলতে হয়। একই টেবিলে দুই রকম মানে রাখলে ইঞ্জিনকে প্রতিটা সারি পড়ার
 * আগে অফারের ধরন দেখে ঠিক করতে হত *"এটা অথবা, না এবং"* — আর একদিন
 * একটা পথ ভুলটা পড়ত, নীরবে।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotion_combo_items', function (Blueprint $table) {
            $table->id();

            /* ⭐ বাইরের কী — [[PublicIdTest]] প্রতিটা টেবিলে এটা চায় */
            $table->publicId();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('promotion_id')->constrained()->cascadeOnDelete();

            /*
             * ⛔ `restrictOnDelete`, `cascade` নয় — আর এটাই এই টেবিলের সবচেয়ে দামি লাইন।
             *
             * ⚠️ পণ্যটা মুছলে সারিটাও মুছে গেলে *"ক + খ + গ"* কম্বোটা চুপচাপ
             * *"ক + খ"* হয়ে যেত — সহজে খোলে, বড় ছাড়, আর অনুমোদনকারী যা
             * সই করেছিলেন তার সাথে আর মেলে না। ⓘ তাই কম্বোতে থাকা পণ্য
             * মোছা যায় না; আগে অফারটা থামাতে হয়।
             */
            $table->foreignId('product_id')->constrained('inv_products')->restrictOnDelete();

            /*
             * ⓘ কম্বোর **একটা সেটে** এই পণ্যের কতটা লাগে — পণ্যের মূল এককে,
             * বিলের সারির `qty`-র মতোই।
             *
             * ⚠️ দশমিক, পূর্ণসংখ্যা নয়: ⓘ কেজি বা লিটারে বিক্রি হওয়া পণ্যে
             * *"২.৫ কেজি"* একটা বৈধ শর্ত।
             */
            $table->decimal('min_qty', 18, 4);

            $table->timestamps();

            /*
             * ⛔ একই পণ্য একই কম্বোতে দুইবার নয়।
             *
             * ⚠️ দুইবার থাকলে ইঞ্জিন দুইটা সারিকেই একই বিলের সারি দিয়ে
             * মেটাত — *"ক×১ + ক×১"* লিখে আসলে *"ক×১"* বোঝাত, আর মানুষ
             * ভাবতেন দুইটা লাগে।
             */
            $table->unique(['promotion_id', 'product_id'], 'pcb_item_uq');

            /* ⓘ *"এই পণ্যটা কোন কোন কম্বোতে আছে"* — পণ্য মোছার আগের প্রশ্ন */
            $table->index(['company_id', 'product_id'], 'pcb_product_ix');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_combo_items');
    }
};
