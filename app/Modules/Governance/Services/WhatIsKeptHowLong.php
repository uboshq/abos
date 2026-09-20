<?php

declare(strict_types=1);

namespace App\Modules\Governance\Services;

use App\Core\Support\CompanyContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/**
 * কোন কাগজ কতদিন থাকে — আর কে সেটা মানায়।
 *
 * ── কেন, ২০ সেপ্টেম্বর ২০২৬ ─────────────────────────────────────────
 * ফিন্যান্স মানচিত্রের §২৮ "কাগজ সংরক্ষণ নীতি"। ⓘ প্রশ্নটা কর অফিস বা
 * নিরীক্ষক এলে ওঠে: *"তিন বছর আগের বিলটা দেখান"*। উত্তর না জানা থাকলে
 * তখন খোঁজাখুঁজি শুরু হয়, আর ভুল উত্তরটা ("সব তো আছেই") সবচেয়ে বিপজ্জনক।
 *
 * ── কেন এটা কেবল পড়ে, নিয়ম বসায় না ─────────────────────────────────
 * ⛔ একটা "নীতি" পর্দা বানিয়ে সেখানে "৬ বছর" লিখে রাখা যেত, আর সেটাই হত
 * সবচেয়ে খারাপ কাজ: লেখা থাকত এক, ঘটত আরেক। ⭐ তাই এই পর্দা **যা সত্যিই
 * ঘটে** তাই দেখায় — কোন সারি কে মোছে, কোন সেটিং সেটা ঠিক করে, আর কোনটা
 * কেউ কখনো মোছে না।
 *
 * ⚠️ বাংলাদেশে আয়কর ও ভ্যাটের কাগজ **৬ বছর** রাখতে হয়। খাতা চিরকাল থাকে
 * বলে ঐ শর্ত মেটে; ⛔ কিন্তু ব্যাকআপ ৩০ দিনের — অর্থাৎ ডাটাবেস হারালে
 * ছয় বছরের খাতা নয়, ত্রিশ দিনের পিছন পর্যন্তই ফেরানো যায়।
 */
final class WhatIsKeptHowLong
{
    /** চিরকাল — কোনো কাজ এই সারিগুলো মোছে না। */
    public const FOREVER = 'forever';

    /** দিনের হিসাবে — সংখ্যাটা `days`-এ। */
    public const DAYS = 'days';

    /**
     * @return list<array{key: string, kept: string, days: ?int, rows: ?int, by: string, route: ?string}>
     */
    public function all(): array
    {
        return [
            $this->row('ledger', self::FOREVER, null, 'ledger_entries', 'accounts.report.show:ledger'),
            $this->row('vouchers', self::FOREVER, null, 'vouchers', 'accounts.voucher.list'),
            $this->row('audit', self::FOREVER, null, 'audit_trails', 'governance.audit.index'),
            $this->row('exports', self::FOREVER, null, 'export_log', 'governance.export.index'),
            $this->row('logins', self::FOREVER, null, 'login_history', 'governance.login.index'),
            $this->row('errors', self::FOREVER, null, 'error_events', 'governance.error.index'),
            $this->row('notifications', self::FOREVER, null, 'notifications', 'notifications.settings'),

            /*
             * ⭐ এই তিনটাই সত্যিই মোছা হয়, আর তিনটার কারণও আলাদা।
             *
             * ⓘ ব্যাকআপ জায়গা খায়, তাই পুরনোগুলো যায়। ⛔ আর ফর্ম জমার
             * চিহ্নগুলো কেবল "একই ফর্ম দুইবার গেল কি না" ধরার জন্য —
             * ওগুলো ব্যবসার তথ্য নয়, তাই রাখার কারণও নেই।
             *
             * ⛔ তৈরি রিপোর্টগুলো ২০ সেপ্টেম্বর ২০২৬ পর্যন্ত এই তালিকায়
             * **ছিল না** — অথচ [[ScheduledReportRunner::prune]] ওগুলো
             * ৯০ দিনে মুছে দেয়, সব কোম্পানি জুড়ে। ⚠️ অর্থাৎ পর্দাটা যে
             * প্রশ্নের উত্তর দিতে বানানো, ঠিক সেই প্রশ্নে সে চুপ ছিল —
             * আর ওতে বিক্রয়মূল্যসহ রিপোর্ট থাকে।
             */
            $this->row('backups', self::DAYS, (int) config('abos.backup.keep_days'), 'bak_runs', 'backup.index'),
            $this->row('reports', self::DAYS, (int) config('abos.reports.retention_days', 90), 'report_runs', 'system_admin.reports.schedule.index'),
            $this->row('form_marks', self::DAYS, 1, 'submitted_forms', null),
        ];
    }

    /** বাংলাদেশে কর ও ভ্যাটের কাগজ যত বছর রাখতে হয়। */
    public const LAW_YEARS = 6;

    /**
     * ⚠️ সবচেয়ে দুর্বল জায়গাটা — খাতা চিরকাল থাকে, কিন্তু ব্যাকআপ নয়।
     * ⓘ পর্দায় এটা আলাদা করে বলা হয়, কারণ দুইটা এক জিনিস মনে হয়।
     */
    public function backupDays(): int
    {
        return (int) config('abos.backup.keep_days');
    }

    /**
     * যে সারিগুলো কোনো নির্ধারিত কাজ মোছে না।
     *
     * ⛔ আগে এটা `$kept === FOREVER ? 'nobody' : 'schedule'` দিয়ে **একই
     * লাইনে** বানানো হত, আর পরীক্ষাটা ঠিক ওটাই মিলিয়ে দেখত — একটা বৃত্ত।
     * ⚠️ কোনোদিন কেউ খতিয়ান ছাঁটার কাজ বসালে দাবিটা তবু সবুজ থাকত, কারণ
     * উত্তরটা কাজের দিকে তাকিয়ে আসত না, দাবিটার দিকে তাকিয়ে আসত।
     *
     * ⭐ তাই তালিকাটা এখন আলাদা করে লেখা: এখানে নাম থাকা মানে কেউ একজন
     * বসে দেখেছেন যে ঐ টেবিল ছাঁটার কোনো কাজ নেই।
     */
    private const NOBODY_PRUNES = [
        'ledger', 'vouchers', 'audit', 'exports', 'logins', 'errors', 'notifications',
    ];

    private function row(string $key, string $kept, ?int $days, string $table, ?string $route): array
    {
        return [
            'key' => $key,
            'kept' => $kept,
            'days' => $days,
            'rows' => $this->rowsOf($table),
            'by' => in_array($key, self::NOBODY_PRUNES, true) ? 'nobody' : 'schedule',
            'route' => $route !== null && Route::has(explode(':', $route)[0]) ? $route : null,
        ];
    }

    /**
     * এই কোম্পানির সারি কতগুলো।
     *
     * ⛔ আগে এটা `DB::table($table)->count()` ছিল — কাঁচা কোয়েরি গ্লোবাল
     * স্কোপ মানে না, তাই সংখ্যাটা ছিল **সব কোম্পানি মিলিয়ে**। ⚠️ অর্থাৎ
     * যাঁর `governance.audit.view` আছে, তিনি অন্য কোম্পানির ব্যবসার আকার
     * পড়ে ফেলতেন — কত ভাউচার, কত খতিয়ান সারি। তথ্য নয়, কিন্তু মাপ।
     *
     * ⓘ `submitted_forms`-এ কোম্পানির কলামই নেই (ওটা ফর্ম দুইবার জমা
     * ঠেকানোর চিহ্ন, ব্যবসার তথ্য নয়), তাই ওখানে ছাঁকনি বসে না — আর
     * সেজন্যই কলামটা আছে কি না আগে দেখা হয়।
     */
    private function rowsOf(string $table): ?int
    {
        if (! Schema::hasTable($table)) {
            return null;
        }

        $query = DB::table($table);

        if (Schema::hasColumn($table, 'company_id')) {
            $query->where('company_id', CompanyContext::id());
        }

        return (int) $query->count();
    }
}
