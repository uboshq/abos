<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FD ও DPS, আর ঋণের বিপরীতে বাঁধা FD।
 *
 * ── কেন এগুলোও `acc_loans`-এ ────────────────────────────────────────
 * FD মানে আমরা ব্যাংককে টাকা ধার দিয়েছি নির্দিষ্ট মেয়াদে, নির্দিষ্ট
 * সুদে। DPS মানে একই জিনিস, কেবল টাকাটা মাসে মাসে যায়। অর্থাৎ দুইটাই
 * `direction = given` — আগের কমিটে যে দিকটা বসানো হয়েছে।
 *
 * আলাদা টেবিল বানালে সুদ বসানো, খতিয়ানে যাওয়া, বকেয়া গোনা — সবই আবার
 * লিখতে হত, আর "আমাদের মোট টাকা কোথায় খাটছে" প্রশ্নের উত্তর তিন জায়গা
 * থেকে জোড়া লাগাতে হত।
 *
 * ── ঋণের বিপরীতে FD — কেন এটা আলাদা করে ধরা দরকার ───────────────────
 * ব্যাংক প্রায়ই ঋণ দেয় FD বন্ধক রেখে। কাগজে দুইটা আলাদা জিনিস: একটা
 * সম্পদ, একটা দায়। কিন্তু বাস্তবে ওই FD-র টাকাটা **আমাদের হাতে নেই** —
 * ঋণ শোধ না হওয়া পর্যন্ত ভাঙানো যায় না।
 *
 * এই সম্পর্কটা না রাখলে নগদ-প্রবাহের হিসাব মিথ্যা বলত: তালিকায় FD-টা
 * "আছে" দেখাত, আর কেউ ধরে নিত দরকারে ওটা ভাঙিয়ে ফেলা যাবে। বাঁধা
 * FD-র উপর ভরসা করে নেওয়া সিদ্ধান্তই সবচেয়ে দামি ভুল।
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('acc_loans', function (Blueprint $table) {
            /*
             * মেয়াদ শেষের তারিখ।
             *
             * `due_on` (হাতধারের কথা দেওয়া তারিখ) থেকে আলাদা রাখা
             * হয়েছে ইচ্ছা করে: একটা প্রতিশ্রুতি, অন্যটা চুক্তি। হাতধারের
             * তারিখ পেরোলে সেটা দেরি; FD-র তারিখ পেরোলে সেটা প্রাপ্য।
             * দুইটাকে এক ঘরে রাখলে একই সংখ্যা দুইরকম মানে বইত।
             */
            $table->date('matures_on')->nullable()->after('due_on');

            /*
             * এই FD কোন ঋণের বিপরীতে বাঁধা।
             *
             * নিজের টেবিলেই নির্দেশ করে, কারণ FD আর ঋণ দুইটাই এখানকার
             * সারি। nullSafe: ঋণ মুছে গেলে FD থেকে যায়, শুধু বাঁধনটা
             * খোলে — টাকাটা তো থেকেই যায়।
             */
            $table->foreignId('pledged_against_id')->nullable()->after('matures_on')
                ->constrained('acc_loans')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('acc_loans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pledged_against_id');
            $table->dropColumn('matures_on');
        });
    }
};
