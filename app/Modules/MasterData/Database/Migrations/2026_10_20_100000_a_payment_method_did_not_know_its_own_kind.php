<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * পেমেন্ট মেথড জানত না সে কোন ধরনের — নগদ, ব্যাংক, MFS, না চেক।
 *
 * জমার পর্দায় Method বাছলে "যে খাতে টাকা বসবে" তালিকাটা ছাঁকতে হয়: নগদে
 * কেবল নগদের খাত, ব্যাংকে ব্যাংক হিসাব, MFS-এ মোবাইল মানি। সারিটা নিজের
 * ধরন না জানলে ওই ছাঁকা সম্ভবই ছিল না। ধরনগুলো Accounts-এর instrument ও
 * StandardChart-এর তিন মায়ের সাথে মেলে (১১০১ · ১১০২ · ১১০৫; চেক ১১০৪)।
 *
 * ── কেন কেবল seed বদলানো যথেষ্ট নয় ─────────────────────────────────
 * চলমান কোম্পানিগুলোর সারিগুলো ইতিমধ্যে বসে গেছে। শুধু seed-এ ধরন যোগ
 * করলে আজকের সারিগুলো ধরনহীন থেকে যেত, আর ছাঁকনিটা তাদের কাছে কোনোদিন
 * কাজ করত না। তাই এখানেই backfill: প্রতিটা পুরনো সারির ধরন তার খাতের
 * পূর্বপুরুষ ধরে বের করা হয়।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mdm_payment_methods', function (Blueprint $table): void {
            // nullable — backfill-এর পর কিছু পুরনো সারির ধরন অজানা থাকতে পারে
            // (কোনো মায়ের নিচে পড়ে না); পর্দা তখন সব টাকার খাত দেখাবে
            $table->string('kind', 8)->nullable()->after('name_bn');
        });

        $this->backfill();
    }

    /**
     * প্রতিটা সারির ধরন — সবচেয়ে নিশ্চিত সংকেত আগে, তিন ধাপে।
     *
     * ১· code=CHQ → cheque। চেকের টাকা ব্যাংকেই বসে, তাই খাত দেখে চেক আর
     *    ব্যাংক আলাদা করা যায় না — কিন্তু কোডটা বলে দেয়। খাতের আগেই ধরতে
     *    হয়, নইলে খাত হেঁটে ভুল করে 'bank' বসত।
     * ২· খাতের পূর্বপুরুষ হেঁটে: ১১০১→cash · ১১০৫→mfs · ১১০৪→cheque · ১১০২→bank।
     * ৩· খাত না বসানো থাকলে (seed-এ `account_id` ইচ্ছাকৃত খালি — কোম্পানি
     *    পরে বসায়) প্রমিত seed-কোড ধরে: CASH→cash · BANK→bank · MFS→mfs।
     *    এটা না থাকলে চলমান কোম্পানির খাত-না-বসানো নগদ/ব্যাংক/MFS সারিগুলো
     *    ধরনহীন থেকে যেত — অথচ কোডেই তাদের ধরন লেখা।
     *
     * কোনোটাই না মিললে `null` — পর্দা তখন সব টাকার খাত দেখায় (নিরাপদ)।
     *
     * ── public কেন ─────────────────────────────────────────────────────
     * মাইগ্রেশন-কালে টেবিল খালি (কোনো কোম্পানি তখনো seed হয়নি), তাই এই
     * তিন শাখা মাইগ্রেশনেই যাচাই করা যায় না। টেস্ট ([[PaymentMethodKindBackfillTest]])
     * legacy-আকারের সারি বানিয়ে এই পদ্ধতিটা সরাসরি ডাকে ও মেলায় — নইলে
     * গার্ডটা সবুজ অথচ অন্ধ থাকত। মডেলে সরানো হয়নি ইচ্ছে করেই: মাইগ্রেশন
     * self-contained থাকুক, নাহলে ভবিষ্যতে কেউ পদ্ধতিটা সরালে fresh
     * install-এ `migrate` ভাঙত।
     */
    public function backfill(): void
    {
        $mothers = ['1101' => 'cash', '1105' => 'mfs', '1104' => 'cheque', '1102' => 'bank'];
        $seedCodes = ['CASH' => 'cash', 'BANK' => 'bank', 'MFS' => 'mfs'];

        // company scope ছাড়া — সব কোম্পানির সারি
        foreach (DB::table('mdm_payment_methods')->get(['id', 'code', 'account_id']) as $method) {
            $code = strtoupper((string) $method->code);
            $kind = null;

            if ($code === 'CHQ') {
                $kind = 'cheque';
            }

            // ২· খাতের পূর্বপুরুষ
            if ($kind === null) {
                $accountId = $method->account_id;
                $depth = 0;

                while ($accountId !== null && $depth++ < 20) {
                    $account = DB::table('accounts')->where('id', $accountId)->first(['code', 'parent_id']);

                    if ($account === null) {
                        break;
                    }

                    if (isset($mothers[$account->code])) {
                        $kind = $mothers[$account->code];
                        break;
                    }

                    $accountId = $account->parent_id;
                }
            }

            // ৩· খাত নেই — প্রমিত seed-কোড ধরে
            if ($kind === null && isset($seedCodes[$code])) {
                $kind = $seedCodes[$code];
            }

            DB::table('mdm_payment_methods')->where('id', $method->id)->update(['kind' => $kind]);
        }
    }

    public function down(): void
    {
        Schema::table('mdm_payment_methods', function (Blueprint $table): void {
            $table->dropColumn('kind');
        });
    }
};
