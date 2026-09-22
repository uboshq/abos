<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * লাভ ভাগ হলো, অথচ কার কত পাওনা কেউ বলতে পারত না।
 *
 * ── ⭐ মালিকের সিদ্ধান্ত, ২২ সেপ্টেম্বর ২০২৬ ─────────────────────────
 * প্রশ্ন ছিল: বণ্টন করা লাভ ব্যবসায় থাকবে, নাকি তাঁরা তুলে নেবেন?
 * উত্তর: *"টাকাটা তুলে নেবেন"*, আর *"র থাকলে বছর শেষে capital-এ যোগ
 * হবে বা invest-এ"*।
 *
 * ── ⛔ কেন খাতা একা যথেষ্ট নয় ───────────────────────────────────────
 * ঘোষণার দাখিলাটা সঞ্চিত মুনাফা থেকে [[StandardChart::PROFIT_PAYABLE]]-এ
 * নামে, আর ওটুকু খতিয়ানেই থাকে। ⚠️ কিন্তু খতিয়ান বলে **কত**, বলে না
 * **কার অংশ কীসের ভিত্তিতে** — কে ৪০%, কে ৩৫%, আর সংখ্যাটা কোন
 * মুনাফার উপর বসানো হয়েছিল।
 *
 * ⓘ ছয় মাস পরে অংশীদার জিজ্ঞেস করলে *"আমার ভাগ কত ছিল"*, খতিয়ানের
 * একটা যোগফল ঐ প্রশ্নের উত্তর নয়। ⛔ আর অনুপাত পরে বদলালে পুরনো
 * ঘোষণার ভাগও বদলে যেত — অনুমোদিত কাগজ নিজে থেকে বদলায় না।
 *
 * ── ⓘ ছাঁচটা এই রিপোরই ─────────────────────────────────────────────
 * মূলধন ([[CapitalEntry]]) আর উত্তোলন ([[Withdrawal]]) — দুইটাই
 * ব্যক্তি-ভিত্তিক টাকা নিজের টেবিলে রাখে, `person_id` ধরে। ⭐ এটাও
 * তা-ই: এক ঘোষণা মানে এক `document_no`, আর যতজন ভাগ পাবেন ততটা সারি।
 *
 * ⚠️ পক্ষ ধরা হয় `person_id`-তে, নামে নয় — নামে ধরলে একই মানুষ দুই
 * বানানে দুইজন হয়ে যেতেন, আর ভাগটা তখন সরাসরি ভুল টাকায় দাঁড়াত।
 * ⓘ ঠিক এই ভুলটা এই রিপোতে একবার সারানো হয়েছে (পাঁচটা পর্দা, পাঁচ
 * রকম নামের ঘর) — সেই মাইগ্রেশনের ব্যাখ্যাটা পড়ার মতো।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acc_profit_shares', function (Blueprint $table) {
            $table->id();
            $table->publicId();

            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();

            /*
             * ⓘ এক ঘোষণার সব সারিতে একই নম্বর — কাগজটা একটাই, ভাগ কয়টা।
             * ⚠️ অনন্য নয় ইচ্ছাকৃতভাবে, নাহলে দ্বিতীয় অংশীদারের সারিই
             * বসত না।
             */
            $table->string('document_no', 32);

            $table->date('trx_date');

            $table->foreignId('person_id')->constrained('mdm_people')->restrictOnDelete();

            /*
             * ⭐ ভাগটা কীসের ভিত্তিতে — শতাংশ, আর কোন মুনাফার উপর।
             *
             * ⓘ দুইটাই সারিতে লেখা থাকে, হিসাব করে বের করা হয় না।
             * ⛔ পরে অনুপাত বদলালে বা বছরের মুনাফা সংশোধিত হলে এই
             * ঘোষণার ভাগ বদলে যেত — আর অনুমোদিত কাগজ নিজে থেকে বদলায়
             * না। ⚠️ ঠিক এই কারণেই খরচ ভাউচারের ভাগেও অনুপাতটা সারিতে
             * লেখা থাকে।
             */
            $table->decimal('share_percent', 9, 4)->nullable();
            $table->decimal('profit_base', 18, 4);

            $table->decimal('amount', 18, 4);

            /*
             * খসড়া না পোস্ট।
             *
             * ⓘ খসড়া অবস্থায় সংখ্যা বদলানো যায়; পোস্ট হলে খাতায় বসে
             * যায়, আর তখন বদলাতে হলে উল্টো দাখিলা লাগে।
             */
            $table->string('status', 16)->default('draft');

            $table->foreignId('voucher_id')->nullable()
                ->constrained('vouchers')->nullOnDelete();

            $table->string('narration', 500)->nullable();
            $table->timestamp('posted_at')->nullable();

            $table->foreignId('created_by')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            /*
             * ⚠️ সূচির নাম হাতে দেওয়া — Laravel-এর নিজে বানানো নাম
             * এখানে ৬৪ অক্ষর ছাড়িয়ে যায়, আর তখন `migrate:fresh`
             * **সব সেশনে** ভাঙে।
             */
            $table->index(['company_id', 'trx_date'], 'ps_company_date_idx');
            $table->index(['company_id', 'person_id'], 'ps_company_person_idx');
            $table->index(['company_id', 'document_no'], 'ps_company_doc_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acc_profit_shares');
    }
};
