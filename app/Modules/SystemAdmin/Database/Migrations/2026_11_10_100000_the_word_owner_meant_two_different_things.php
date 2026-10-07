<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `owner` → `super_admin` — ডাটাবেজের সারিটাও, কেবল কোডের ধ্রুবক নয়।
 *
 * ── কেন নাম বদল, ১৩ সেপ্টেম্বর ২০২৬ ──────────────────────────────────
 * ⓘ ERP-র জগতে কেউ "owner" বলে না — Odoo, Tally, NetSuite, QuickBooks
 * সবাই বলে Administrator। কিন্তু আসল কারণটা শব্দের রুচি নয়: এই
 * ব্যবস্থায় **"মালিক" দুইটা আলাদা জিনিস বোঝাত** —
 *
 *   এখানে (রোল)        কে সিস্টেম চালায়, কার হাতে সব অনুমতি
 *   অর্থ মডিউলে        কার টাকা ব্যবসায় খাটছে (`finance::who.owner`)
 *
 * ⛔ একই শব্দ দুই অর্থে বসে থাকায় প্রশ্নটা বারবার উঠত: *"বিনিয়োগকারীকে
 * কী রোল দেব?"* — অথচ যিনি কেবল টাকা দেন তাঁর কোনো অ্যাকাউন্টই লাগে না।
 * নাম আলাদা হওয়ার পর প্রশ্নটাই আর ওঠে না।
 *
 * ⚠️ তাই `finance::who.owner` **ছোঁয়া হয়নি**। দুইটা একসাথে বদলালে পুরো
 * লাভটাই হারাত — তখন আবার একই শব্দ, শুধু নতুন বানানে।
 *
 * ── ⛔ কেন মাইগ্রেশনটা ছাড়া কোড বদলানো বিপজ্জনক ───────────────────────
 * চালু সার্ভারে (`os.adi.com.bd`) `roles` টেবিলে সারিটা `owner` নামেই
 * বসে আছে। কেবল ধ্রুবক বদলালে কোড এমন একটা রোল খুঁজত যা ডাটাবেজে নেই।
 *
 * ⚠️ আর ব্যর্থতাটা হত **নীরব**: ব্যবহারকারী দিব্যি লগইন করতেন, কিন্তু
 * একটাও অনুমতি পেতেন না, আর কোথাও কোনো ত্রুটি উঠত না — কেবল প্রতিটা
 * মেনু খালি। ⛔ তার চেয়েও খারাপ: `PermissionSyncer::keepOwnerComplete()`
 * রোলটা না পেলে **নতুন করে বানায়**, তাই পাশাপাশি দুইটা সারি থাকত —
 * পুরনো `owner` (যাতে সব মানুষ বসা) আর নতুন খালি `super_admin` (যাতে
 * সব অনুমতি)। দুইটাই "ঠিক" দেখাত, আর কেউ কিছু করতে পারতেন না।
 */
return new class extends Migration
{
    /**
     * ⚠️ তারিখটা `2026_11_10`, আজকের `2026_09_13` নয় — আর এটা ভুল নয়।
     *
     * এই রিপোর মাইগ্রেশনের তারিখ বাস্তব দিনের চেয়ে এগিয়ে (শেষটা
     * `2026_11_08`)। ⛔ আজকের তারিখ দিলে এই ফাইলটা `roles` টেবিল
     * জন্মানোর **আগে** চলত, `migrate` দিব্যি "DONE" লিখত, আর কিছুই
     * বদলাত না। ⓘ আর দ্বিতীয়বার চালিয়েও লাভ হত না, কারণ Laravel
     * তখন ওটাকে "চলে গেছে" ধরে নেয়।
     */
    public function up(): void
    {
        $this->rename('owner', 'super_admin');
    }

    public function down(): void
    {
        $this->rename('super_admin', 'owner');
    }

    /**
     * প্রতিটা কোম্পানির সারি আলাদা করে — একটা `UPDATE` নয়।
     *
     * ── কেন লুপ, কেন সরাসরি একটা কোয়েরি নয় ───────────────────────────
     * ⓘ রোল এখন কোম্পানির ভেতরে বাঁধা, আর ইউনিক কী
     * `(company_id, name, guard_name)`। অর্থাৎ প্রতি কোম্পানিতে একটা
     * করে `owner` সারি — দুই কোম্পানিতে দুইটা।
     *
     * ⚠️ সরল `UPDATE roles SET name='super_admin' WHERE name='owner'`
     * সাধারণত চলত, কিন্তু **একটা অবস্থায় ভেঙে পড়ত**: যদি কোনো কোম্পানিতে
     * ইতিমধ্যেই একটা `super_admin` সারি থেকে থাকে (যেমন কেউ মাইগ্রেশনের
     * আগে নতুন কোড দিয়ে `sync()` চালিয়ে ফেললে), তখন ইউনিক কী ভেঙে
     * পুরো মাইগ্রেশন থেমে যেত — অর্ধেক কোম্পানি বদলে, বাকিটা না বদলে।
     *
     * ⭐ তাই প্রতিটা সারি আলাদা: জায়গা খালি থাকলে নাম বদলাও, দখল করা
     * থাকলে **জুড়ে দাও** — মানুষ ও অনুমতি নতুন সারিতে সরিয়ে পুরনোটা
     * মুছে ফেলো। ⓘ দুইটা পথেই ফল এক: কোম্পানিপ্রতি একটা সারি, নতুন
     * নামে, আর যাঁরা আগে মালিক ছিলেন তাঁরা মালিকই থাকেন।
     */
    private function rename(string $from, string $to): void
    {
        $rows = DB::table('roles')->where('name', $from)->get();

        foreach ($rows as $row) {
            $taken = DB::table('roles')
                ->where('name', $to)
                ->where('guard_name', $row->guard_name)
                ->where('company_id', $row->company_id)
                ->value('id');

            if ($taken === null) {
                DB::table('roles')->where('id', $row->id)->update(['name' => $to]);

                continue;
            }

            $this->merge((int) $row->id, (int) $taken);
        }
    }

    /**
     * পুরনো সারির সবকিছু নতুন সারিতে, তারপর পুরনোটা বিদায়।
     *
     * ⚠️ আগে **ডুপ্লিকেট হত এমন সারিগুলো মুছে**, তারপর বাকিগুলো সরানো।
     * উল্টো ক্রমে করলে দুইটা পিভটেরই যৌগিক প্রাথমিক কী ভেঙে পড়ত —
     * একই মানুষ একই রোলে দুইবার বসতে পারেন না।
     */
    private function merge(int $oldId, int $newId): void
    {
        DB::table('model_has_roles as old')
            ->where('old.role_id', $oldId)
            ->whereExists(fn ($q) => $q->select(DB::raw(1))
                ->from('model_has_roles as keep')
                ->where('keep.role_id', $newId)
                ->whereColumn('keep.model_id', 'old.model_id')
                ->whereColumn('keep.model_type', 'old.model_type')
                ->whereColumn('keep.company_id', 'old.company_id'))
            ->delete();

        DB::table('model_has_roles')->where('role_id', $oldId)->update(['role_id' => $newId]);

        DB::table('role_has_permissions as old')
            ->where('old.role_id', $oldId)
            ->whereExists(fn ($q) => $q->select(DB::raw(1))
                ->from('role_has_permissions as keep')
                ->where('keep.role_id', $newId)
                ->whereColumn('keep.permission_id', 'old.permission_id'))
            ->delete();

        DB::table('role_has_permissions')->where('role_id', $oldId)->update(['role_id' => $newId]);

        DB::table('roles')->where('id', $oldId)->delete();
    }
};
