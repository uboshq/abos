<?php

declare(strict_types=1);

namespace App\Core\Services\Backup;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * ব্যাকআপ, যাচাই আর ফেরানো — একসাথে একটাই — চূড়ান্ত অডিট, ৩০ সেপ্টেম্বর ২০২৬ (⛔১৯)।
 *
 * ── ⛔ যা হতে পারত ──────────────────────────────────────────────
 * যাচাইয়ের ডাটাবেজের নাম স্থির (`{db}_verify`)। দুইজন একসাথে যাচাই
 * চাপলে একজনের `DROP DATABASE` অন্যজনের অর্ধেক-ঢালা টেবিল ফেলে দিত,
 * আর গোনার সময় ভুল ফাইলের টেবিল গুনে **ভুল "পাস"** লেখা হত। আর রাতের
 * ব্যাকআপ চলার মাঝখানে কেউ `abos:restore` চালালে ডাম্পটা অর্ধেক পুরনো,
 * অর্ধেক নতুন খাতার হত।
 *
 * ── ⓘ কেন নামটা বদলানো হয়নি ───────────────────────────────────
 * লাইভের শেয়ার্ড হোস্টিংয়ে ডাটাবেজ-ব্যবহারকারীর অনুমতি **কেবল**
 * `univerbd_abos_verify` নামে (৩০ সেপ্টেম্বর `SHOW GRANTS` দেখে মাপা)।
 * প্রতিবার আলাদা নাম দিলে লাইভের রাতের যাচাই "Access denied"-এ ভাঙত।
 * তাই নাম একই, আর একসাথে দুইজন ঢোকা আটকায় এই তালা।
 *
 * ── ⓘ কেন ডাটাবেজের নিজের তালা (GET_LOCK) ─────────────────────
 * - ফাইল-তালা দুই মেশিনে কাজ করে না; ডাটাবেজ সার্ভার একটাই।
 * - প্রসেস মরে গেলে সংযোগ বন্ধ হয়, আর তালাটা **নিজেই খুলে যায়** —
 *   আটকে-থাকা তালা হাতে খুলতে কাউকে সার্ভারে ঢুকতে হয় না।
 * - `DROP DATABASE`-এর পরেও টিকে থাকে, কারণ তালাটা সংযোগের, খাতার নয়।
 *
 * ⓘ একই প্রসেসের ভেতরে আবার ঢোকা যায় (`abos:restore` আগে যাচাই করে,
 * তারপর ব্যাকআপ নেয়, তারপর ফেরায়) — গভীরতা গুনে কেবল বাইরেরটা তালা নেয়।
 */
final class BackupLock
{
    /** ⓘ এই প্রসেসে কত স্তর ভেতরে আছি — ০ মানে তালা হাতে নেই */
    private static int $depth = 0;

    /**
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public static function hold(callable $work): mixed
    {
        if (self::$depth === 0) {
            $got = DB::selectOne('SELECT GET_LOCK(?, 0) AS got', [self::name()]);

            if ((int) ($got->got ?? 0) !== 1) {
                throw new RuntimeException(__('core.backup_busy'));
            }
        }

        self::$depth++;

        try {
            return $work();
        } finally {
            self::$depth--;

            if (self::$depth === 0) {
                try {
                    DB::selectOne('SELECT RELEASE_LOCK(?) AS released', [self::name()]);
                } catch (Throwable) {
                    /* ⓘ সংযোগ গেলে তালাও গেছে — ছাড়ার কিছু বাকি নেই */
                }
            }
        }
    }

    /**
     * তালার নাম — খাতা ধরে, তাই এক সার্ভারে দুইটা ABOS একে অন্যকে আটকায় না।
     *
     * ⓘ GET_LOCK-এর নাম ৬৪ অক্ষরের বেশি হতে পারে না, তাই হ্যাশ।
     */
    public static function name(): string
    {
        return 'abos_backup_'.substr(md5((string) config('database.connections.mysql.database')), 0, 20);
    }
}
