<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ব্যাংক সীমা দিল, আর শর্তগুলো কেউ কোথাও লিখল না।
 *
 * ── ⛔ কী ছিল না, ১৬ সেপ্টেম্বর ২০২৬ ─────────────────────────────────
 * অর্থের পাঁচটা খাতা গুনে দেখা গেল চারটার কোড আছে — পুঁজি, রাখা টাকা,
 * ধার, ভাড়া। ⚠️ **ব্যাংক ঋণের একটাও মডেল নেই**, অথচ ডিপো ব্যবসায় ঐটাই
 * সবচেয়ে বড় দায়।
 *
 * ── কেন এটা ধারের সাথে মেলানো গেল না ──────────────────────────────────
 * হাতধারে যা **একটাও নেই**: মঞ্জুরিপত্র · জামানত · ড্রয়িং পাওয়ার ·
 * বার্ষিক নবায়ন · শর্ত ভাঙলে জরিমানা। ⓘ আর ব্যাংকের সুবিধা পাঁচ রকম,
 * প্রতিটার আচরণ আলাদা — একটা টেবিলে পাঁচটা ধরন, কিন্তু ঘরগুলো নিজের
 * নামে।
 *
 * ── ⭐ সবচেয়ে জরুরি সিদ্ধান্ত: CC-র ব্যালান্স এখানে নেই ───────────────
 * **CC একটা হিসাব, দলিল নয়।** আপনি "৫০ লাখ CC নিলাম" বলে সারি লেখেন
 * না — একটা **সীমা** পান, তারপর রোজ তোলেন আর জমা দেন।
 *
 * ⛔ তাই "আজ কত তোলা" নামে কোনো কলাম **নেই**। ওটা `1102`-এর সন্তান
 * হিসাবের ব্যালান্স, আর ব্যালান্স ঋণাত্মক হলেই সেটা আমাদের দেনা।
 * ⚠️ কলাম রাখলে ওটা খতিয়ানের **দ্বিতীয় কপি** হত, আর দুই কপি একদিন
 * আলাদা হয়ই — সাধারণত যেদিন কিছু বাতিল হয়।
 *
 * ⓘ একই যুক্তিতে "কত শোধ হয়েছে" কলামও নেই — ভাউচারের সারি যোগ করে
 * বের হয় ([[the_advance_on_the_godown]]-এর একই নিয়ম)।
 *
 * ── ⚠️ ব্যাংক গ্যারান্টি একটা দায় নয়, যতক্ষণ না কেউ ভাঙায় ────────────
 * তাই `limit_amount` ওখানে গ্যারান্টির অঙ্ক, কিন্তু খতিয়ানে সেটা
 * **দায় হিসেবে বসে না** — বসে কেবল মার্জিন ও কমিশন। ⛔ ঋণ ধরে বসালে
 * ব্যবসাটা নিজের চেয়ে বেশি ঋণগ্রস্ত দেখাত, আর ব্যাংক পরের সুবিধা দিতে
 * দ্বিধা করত।
 *
 * ── ⛔ কেন ধরন-প্রতি আলাদা নামের ঘর, সাধারণ নাম নয় ───────────────────
 * নকশার নমুনায় তিনটা সাধারণ ঘর (`a` · `b` · `c`) ব্যবহার করা হয়েছিল,
 * আর ধরন বদলালে ঐ ঘরের **অর্থ** বদলাত কিন্তু **সংখ্যা** থেকে যেত।
 * ⚠️ ফল: গ্যারান্টির হিসাব দাঁড়াল ৩ লক্ষ ৬০ হাজার কোটি টাকা — আর
 * দাখিলা তবুও **মিলে যেত**, কারণ ভুল সংখ্যাটা দুই পাশেই বসত।
 *
 * ⭐ তাই এখানে প্রতিটা ঘরের নিজের নাম, আর যেটা ঐ ধরনে অর্থহীন সেটা
 * `null` থাকে — অর্থহীন সংখ্যা নয়।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_bank_facilities', function (Blueprint $table) {
            $table->id();
            $table->publicId();

            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();

            $table->string('document_no', 40)->nullable();

            /*
             * পাঁচ রকম সুবিধা — তালিকাটা মডেলের ধ্রুবকে, enum কলামে নয়।
             * ⓘ নতুন একটা ধরন যোগ করতে ডাটাবেজ ছুঁতে হয় না।
             */
            $table->string('kind', 16);

            /* ── মঞ্জুরি ─────────────────────────────────────────── */
            $table->string('bank', 191);
            $table->string('branch_name', 191)->nullable();
            $table->string('sanction_no', 64)->nullable();
            $table->date('sanctioned_on');

            /*
             * সীমা বা অঙ্ক — ধরন অনুযায়ী অর্থ বদলায়, আর সেটা ইচ্ছাকৃত:
             * CC-তে মঞ্জুরিকৃত সীমা · মেয়াদিতে ঋণের অঙ্ক · LTR-এ এলসির
             * মূল্য · লিজে সম্পদের দাম · গ্যারান্টিতে গ্যারান্টির অঙ্ক।
             *
             * ⓘ পাঁচটাতেই কথাটা এক — **ব্যাংক কত টাকার দায়িত্ব নিল**।
             */
            $table->decimal('limit_amount', 18, 4);

            $table->decimal('interest_rate', 8, 4)->default(0);
            $table->unsignedSmallInteger('term_months')->nullable();

            /*
             * ⚠️ CC ও LTR **বার্ষিক নবায়ন** হয়। তারিখটা না থাকলে সুবিধাটা
             * নীরবে ফুরিয়ে যায়, আর টের পাওয়া যায় চেক ফেরত এলে।
             */
            $table->date('renews_on')->nullable();

            /* ── ধরন-প্রতি ঘর, প্রতিটার নিজের নামে ───────────────── */

            /** CC — হাইপোথিকেশনে দেওয়া স্টক ও পাওনার মূল্য */
            $table->decimal('stock_value', 18, 4)->nullable();

            /** CC · LTR · গ্যারান্টি — আমাদের নিজের অংশ, শতাংশে */
            $table->decimal('margin_percent', 5, 2)->nullable();

            /** মেয়াদি · লিজ — কিস্তির সংখ্যা ও মাসিক অঙ্ক */
            $table->unsignedSmallInteger('instalments')->nullable();
            $table->decimal('instalment_amount', 18, 4)->nullable();

            /** লিজ — ডাউন পেমেন্ট */
            $table->decimal('down_payment', 18, 4)->nullable();

            /** মেয়াদি — প্রক্রিয়াকরণ ফি · LTR ও গ্যারান্টি — কমিশন */
            $table->decimal('charges', 18, 4)->default(0);

            /* ── জামানত ও শর্ত ───────────────────────────────────── */
            $table->string('security_type', 24)->default('unsecured');
            $table->decimal('security_value', 18, 4)->nullable();
            $table->string('guarantors', 500)->nullable();

            /*
             * ⛔ বিশেষ শর্ত — যেমন *"মাসিক স্টক স্টেটমেন্ট ১০ তারিখের
             * মধ্যে"*। ⚠️ শর্ত ভাঙলে ব্যাংক সুদ বাড়ায় বা সীমা কমায়,
             * প্রায়ই কেউ খেয়াল করার আগেই।
             */
            $table->string('covenant', 500)->nullable();
            $table->date('last_statement_on')->nullable();

            /* ── খতিয়ানের সাথে জোড়া ──────────────────────────────── */

            /**
             * দায় কোন খাতে বসবে — `2211` মেয়াদি · `2212` লিজ · `2170` LTR।
             *
             * ⓘ nullable, কারণ **CC-তে দায়ের খাত লাগে না** — ওটা ব্যাংক
             * হিসাবের ঋণাত্মক ব্যালান্স। আর গ্যারান্টিও দায় নয়।
             */
            $table->foreignId('liability_account_id')->nullable()->constrained('accounts');

            /**
             * CC-র নিজের ব্যাংক হিসাব — `1102`-এর সন্তান।
             *
             * ⭐ এই একটা ঘরই CC-কে বাকি চারটা থেকে আলাদা করে: ওর টাকা
             * এখানে নয়, ঐ হিসাবে চলে।
             */
            $table->foreignId('money_account_id')->nullable()->constrained('accounts');

            $table->string('status', 16)->default('draft');
            $table->date('closed_on')->nullable();
            $table->string('note', 500)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'kind']);

            /* ⓘ নবায়নের তারিখ ধরে খোঁজা হয় — "এই মাসে কোনগুলো ফুরাচ্ছে" */
            $table->index(['company_id', 'renews_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_bank_facilities');
    }
};
