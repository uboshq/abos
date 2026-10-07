<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * `migrate:fresh --seed` চালালে যা বসে।
 *
 * এতদিন এখানে ফ্রেমওয়ার্কের নমুনা কোডটাই ছিল — একটা "Test User" বানাত
 * আর থেমে যেত। ফলে migrate:fresh --seed চালিয়ে লগইন করলে কোনো কোম্পানি
 * নেই, কোনো অর্থবছর নেই, কোনো হিসাবের ছক নেই; নতুন কেউ ধরে নিত সিস্টেমটা
 * ভাঙা। দ্বিতীয়বার চালালে ওই একই ইমেইলে ইউনিক কী ভেঙে ক্র্যাশ করত।
 *
 * প্রোডাকশনে সিডার চলে না — ওখানে কোম্পানি তৈরি হয় System Management
 * থেকে, আর তখন হিসাবের ছক ও মাস্টার তালিকা নিজে থেকেই বসে।
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(DemoSeeder::class);
    }
}
