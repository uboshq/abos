<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Engines\Print\PaperSize;
use App\Core\Engines\Print\PrintEngine;
use App\Models\User;
use App\Modules\Executive\Notifications\DailySummaryMail;
use App\Modules\Executive\Services\CompanyLens;
use App\Modules\Executive\Services\DailySummary;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/**
 * ⭐ প্রতিদিনের সারাংশ রাতে মালিকের ইমেইলে — মালিক, ৭ নভেম্বর ২০২৬ ("email e daw", নমুনা "ok")।
 *
 * মালিক যতগুলো কোম্পানির মালিক, প্রতিটার আলাদা ইমেইল, সাথে A4 PDF ([[DailySummary]], [[DailySummaryMail]])।
 * ঠিকানা: ABOS_DAILY_SUMMARY_MAIL_TO, নয়তো ABOS_EXECUTIVE_MAIL_TO, নয়তো মালিকের লগইনের ইমেইল।
 * ⓘ কনসোলে কেউ লগইন নেই — শাখার দেয়াল আর অনুমোদনের তালিকা মালিকের চোখে দেখতে [[CompanyLens::within()]]-এর ভেতরে।
 * ⓘ সময় রাত ১০টা (ABOS_DAILY_SUMMARY_MAIL_AT; routes/console.php)।
 */
final class DailySummaryMailCommand extends Command
{
    protected $signature = 'abos:daily-summary-mail {--date= : কোন দিনের হিসাব (Y-m-d); না দিলে আজ}';

    protected $description = 'প্রতিদিনের সারাংশ (A4 PDF সহ) মালিকের ইমেইলে পাঠায় — প্রতিটা কোম্পানি আলাদা';

    public function handle(CompanyLens $lens, DailySummary $summary, PrintEngine $print): int
    {
        $owner = $this->owner();

        if ($owner === null) {
            $this->warn('কোনো সক্রিয় মালিক নেই — সারাংশ বানানো গেল না।');

            return self::SUCCESS;
        }

        $to = $this->recipients($owner);

        if ($to === []) {
            $this->info('কোনো ইমেইল ঠিকানা নেই — কিছু পাঠানো হলো না।');

            return self::SUCCESS;
        }

        $day = Carbon::parse((string) ($this->option('date') ?: Carbon::today(config('app.timezone'))->toDateString()))->startOfDay();
        $sent = 0;

        foreach ($lens->companies($owner) as $company) {
            [$data, $pdf] = $lens->within($owner, $company['id'], null, fn () => [
                $data = $summary->for($day),
                $print->render(template: 'executive::print.daily-summary', data: ['s' => $data], paper: PaperSize::A4_LANDSCAPE, scale: 100),
            ]);

            $url = route('executive.daily_summary', ['date' => $day->toDateString()]);

            foreach ($to as $address) {
                Notification::route('mail', $address)->notify(new DailySummaryMail($data, $pdf, $url));
            }
            $sent++;
        }

        $this->info(count($to).'টি ঠিকানায় '.$sent.'টি কোম্পানির প্রতিদিনের সারাংশ পাঠানো হলো।');

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function recipients(User $owner): array
    {
        foreach ([config('abos.executive.daily_mail_to'), config('abos.executive.mail_to'), $owner->email] as $list) {
            $to = array_values(array_filter(array_map('trim', explode(',', (string) $list))));

            if ($to !== []) {
                return $to;
            }
        }

        return [];
    }

    private function owner(): ?User
    {
        // ⓘ সকালের মেইলের একই মালিক — একই নিয়ম এক জায়গায় ([[OwnerMorningMail::ownerForMail()]])
        return app(OwnerMorningMail::class)->ownerForMail();
    }
}
