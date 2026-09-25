<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * কেউ জিজ্ঞেস করত না বিভাগের হাতে টাকা আছে কি না।
 *
 * ── ⛔ যা ছিল ────────────────────────────────────────────────────────
 * চাহিদায় বিভাগের নাম লেখা যেত, কিন্তু ওটা **মুক্ত হরফ** — শুধু একটা
 * লেবেল। ⓘ বাজেটের সারিগুলো বসে খাত × মাস × **cost centre** ধরে
 * ([[Budget]]), আর ক্রয়ের কোনো কাগজে cost centre ছিলই না।
 *
 * ⚠️ ফল: বাজেট বলে একটা জিনিস ছিল, আর ক্রয় বলে আরেকটা — দুইটার মধ্যে
 * কোনো সেতু নেই, তাই *"এই বিভাগ এই মাসে কত খরচ করতে পারবে"* প্রশ্নটা
 * ব্যবস্থাটা কোনোদিন জিজ্ঞেসই করত না।
 *
 * ── ⓘ কেন `department` ঘরটা রয়ে গেল ──────────────────────────────────
 * মুছে দিলে পুরনো প্রতিটা চাহিদা থেকে তথ্য হারাত, আর সেগুলোর কোনো
 * cost centre নেই। ⚠️ দুইটা ঘর পাশাপাশি থাকে: একটা মানুষের লেখা নাম,
 * অন্যটা হিসাবের সাথে জোড়া। ⓘ নামটা থেকে আপনাআপনি জোড়া লাগানো হয় না —
 * ⛔ "Sales" আর "sales dept" এক নয়, আর আন্দাজে মেলালে টাকা ভুল
 * বিভাগের হিসাবে বসত।
 *
 * ── ⚠️ ঘরটা `nullable`, আর সেটাই সীমাটাকে ঐচ্ছিক রাখে ────────────────
 * ⓘ cost centre না বসালে কোনো বাজেট খোঁজা হয় না, আর চাহিদা আগের মতোই
 * মঞ্জুর হয়। ⛔ বাধ্যতামূলক করলে যে প্রতিষ্ঠান বাজেট ব্যবহার করে না
 * তার প্রতিটা চাহিদা আজ থেকে আটকে যেত — একটা সুবিধা যোগ করে অন্যের
 * কাজ থামানোর চেয়ে খারাপ কিছু নেই।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pur_requisitions', function (Blueprint $table) {
            /*
             * ⓘ `restrictOnDelete` — ⚠️ যে cost centre-এর নামে চাহিদা
             * মঞ্জুর হয়েছে সেটা মুছে ফেলা মানে ইতিহাস থেকে খরচের মালিক
             * হারানো। ⛔ `nullOnDelete` হলে সারিটা থাকত, অথচ কার খরচ
             * সেটা আর বলা যেত না।
             */
            $table->foreignId('cost_center_id')
                ->nullable()
                ->after('department')
                ->constrained('acc_cost_centers')
                ->restrictOnDelete();

            /*
             * ⓘ *"এই বিভাগের এই মাসে কত মঞ্জুর হয়েছে"* — প্রতিটা
             * অনুমোদনে এই প্রশ্নটা করা হয়, তাই ছাঁকনিটার নিজের সূচি।
             */
            $table->index(['company_id', 'cost_center_id', 'trx_date'], 'pur_req_budget');
        });
    }

    public function down(): void
    {
        Schema::table('pur_requisitions', function (Blueprint $table) {
            $table->dropIndex('pur_req_budget');
            $table->dropForeign(['cost_center_id']);
            $table->dropColumn('cost_center_id');
        });
    }
};
