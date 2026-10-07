<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * একটা নিয়ম, যাকে নাম ধরে ডাকা যেত না আর কারণও জানা যেত না।
 *
 * ── ⓘ মালিকের নির্দেশ, ২২ সেপ্টেম্বর ২০২৬ ───────────────────────────
 * Univer-এর অনুমোদনের পর্দা দেখিয়ে তিনটা ঘর চেয়েছেন, আর তিনটাই একই
 * অভাবের তিন দিক: **অনুমোদনের ছকটা দেখে কিছু বোঝা যায় না।**
 *
 * ── ⛔ ধাপের নাম ────────────────────────────────────────────────────
 * আজ ধাপে কেবল নম্বর। ⚠️ অনুমোদনের অনুরোধ খুলে *"ধাপ ২"* দেখে কেউ
 * বলতে পারে না ওটা সুপারভাইজার না সিইও — অথচ যিনি সই করবেন তাঁর
 * কাছে ঐ প্রশ্নটাই প্রথম।
 *
 * ── ⛔ কারণ (remarks) ───────────────────────────────────────────────
 * ছয় মাস পরে *"ক্রয়ের পরিশোধে দুইজনের সই"* দেখে কেউ মনে করতে পারবে
 * না কেন বসানো হয়েছিল। ⓘ তখন হয় নিয়মটা ভয়ে রয়ে যায়, নয় কেউ
 * কারণ না জেনেই তুলে দেয় — দুইটাই খারাপ।
 *
 * ── ⛔ সংকেত (code) ─────────────────────────────────────────────────
 * ⓘ `public_id` আছে, কিন্তু ওটা UUID — মুখে বলার মতো নয়। ⚠️ একটা
 * নিয়মকে কথায় বা রিপোর্টে নাম ধরে ডাকতে `AN007` ধরনের ছোট স্থির
 * সংকেত লাগে।
 *
 * ── ⭐ কেন নম্বর সিরিজ থেকে নয় ──────────────────────────────────────
 * বাকি সব কোড [[NumberSeriesEngine]] থেকে আসে, আর মালিকের নিয়ম
 * *"সব জায়গায় কোড অটো বসবে"*। ⛔ তবু এখানে নয়, দুইটা কারণে:
 * ① সিরিজ চলে **আর্থিক বছর ও শাখা ধরে**, আর একটা নিয়ম দলিল নয় — তার
 *   কোনো তারিখ নেই, কোনো শাখা নেই।
 * ② `number_series.reset_yearly` চালু থাকলে বছর বদলে সংকেত **ফিরে
 *   আসতে পারত**, আর তখন কাগজে লেখা `AN007` দুইটা আলাদা নিয়ম বোঝাত।
 *
 * ── ⚠️ অনন্যতা কোম্পানি ধরে, গোটা টেবিলে নয় ─────────────────────────
 * ⓘ একই ছক তিন কোম্পানিতে আলাদা সারি হয়ে বসে (লাইভে ৭২টা সারি, তিন
 * কোম্পানিতে ২৪টা করে)। ⛔ গোটা টেবিলে অনন্য করলে TCL-এ `AN001`
 * বসানোর পর DEM আর ADI ঐ সংকেতটা আর পেত না — অথচ **ওদের নিয়মটা হুবহু
 * একই জিনিস**।
 *
 * ── ⭐ আর পুরনো সারিতে সংকেত বসে সাজানো ক্রমে, কাকতালীয়ভাবে নয় ──────
 * ব্যাকফিলটা `module, action, document_type` ধরে সাজিয়ে করা। ⓘ তাই
 * যে কোম্পানিগুলোর ছক **হুবহু এক**, তাদের সংকেতও এক হয় —
 * `accounts.transfer` তিন জায়গাতেই একই `AN00x`।
 *
 * ⚠️ তবে সেটা **নিশ্চয়তা নয়, পরিণতি**: কোনো কোম্পানিতে একটা বাড়তি ছক
 * বসলে তার পরের সংকেতগুলো সরে যাবে। ⓘ সংকেতটা সবসময় **সেই কোম্পানির**
 * নিয়মের নাম, গোটা প্রতিষ্ঠানের নয়।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approval_flow_steps', function (Blueprint $table): void {
            // ⓘ ঐচ্ছিক — পুরনো ধাপগুলোর নাম নেই, আর নাম ছাড়া ধাপ এখনো বৈধ
            $table->string('step_name', 64)->nullable()->after('level');
        });

        Schema::table('approval_flows', function (Blueprint $table): void {
            $table->string('code', 16)->nullable()->after('company_id');
            $table->string('remarks', 500)->nullable()->after('threshold_amount');
        });

        $this->stampCodesOnWhatIsAlreadyThere();

        Schema::table('approval_flows', function (Blueprint $table): void {
            /*
             * ⚠️ সূচকের নামটা হাতে দেওয়া, আর সেটা ইচ্ছাকৃত।
             *
             * ⓘ Laravel নিজে নাম বানালে হত
             * `approval_flows_company_id_code_unique` — ৪১ অক্ষর, এবার
             * নিরাপদ। ⛔ কিন্তু এই রিপোতে ৬৪ অক্ষরের সীমা দুইবার
             * ভেঙেছে, আর ভাঙলে **সবার** `migrate:fresh` মরে।
             * ([[NoIndexNameStandsAtTheEdgeTest]])
             */
            $table->unique(['company_id', 'code'], 'approval_flow_code');
        });
    }

    /**
     * পুরনো ছকগুলোতে সংকেত বসানো — কোম্পানি ধরে, সাজানো ক্রমে।
     *
     * ⚠️ `orderBy` তিনটা ঘরেই, আর সেটাই ব্যাকফিলটাকে **নির্ধারিত** করে।
     * ⓘ `id` ধরে সাজালে ক্রমটা নির্ভর করত কে আগে সেভ করেছে তার উপর,
     * আর তখন দুই কোম্পানির একই ছক দুই সংকেত পেত।
     */
    private function stampCodesOnWhatIsAlreadyThere(): void
    {
        $companies = DB::table('approval_flows')->distinct()->pluck('company_id');

        foreach ($companies as $companyId) {
            $rows = DB::table('approval_flows')
                ->where('company_id', $companyId)
                ->orderBy('module')
                ->orderBy('action')
                ->orderBy('document_type')
                ->pluck('id');

            foreach ($rows as $i => $id) {
                DB::table('approval_flows')
                    ->where('id', $id)
                    ->update(['code' => 'AN'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT)]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('approval_flows', function (Blueprint $table): void {
            $table->dropUnique('approval_flow_code');
            $table->dropColumn(['code', 'remarks']);
        });

        Schema::table('approval_flow_steps', function (Blueprint $table): void {
            $table->dropColumn('step_name');
        });
    }
};
