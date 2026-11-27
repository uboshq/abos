<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Support\CompanyContext;
use App\Modules\Mail\Models\MailAccount;
use App\Modules\Mail\Models\MailAddress;
use App\Modules\Mail\Services\MailAddresses;
use Illuminate\Console\Command;

/**
 * ⭐ মেইলের দিনের কাজ (মালিক, ২৬ নভেম্বর ২০২৬, নমুনা v4):
 *   · মেয়াদ শেষের ব্যক্তিগত মেইল নিজে বন্ধ (expired);
 *   · বন্ধ ঠিকানা নিজে আবার চালু — যেদিন বলা ছিল;
 *   · মোছার 30 দিন পেরোলে — আগে ব্যাকআপ কপি (সার্ভারের মেইল ফোল্ডার tar), তারপর সার্ভার থেকে মোছা।
 * ⓘ সব বদল অডিটে — ঠিকানা আর মেইলবক্সের সারি নিজেরাই অডিটেড।
 */
final class MailDaily extends Command
{
    protected $signature = 'abos:mail-daily';

    protected $description = 'Mail: expire personal mailboxes, resume paused addresses, delete addresses after the 30-day keep (backup first)';

    public function handle(MailAddresses $addresses): int
    {
        $expired = 0;

        foreach (MailAccount::query()->withoutGlobalScopes()->where('kind', MailAccount::PERSONAL)->where('status', MailAccount::ACTIVE)
            ->whereNotNull('expires_on')->whereDate('expires_on', '<', now()->toDateString())->get() as $box) {
            CompanyContext::forCompany((int) $box->company_id, fn () => $box->forceFill(['status' => MailAccount::EXPIRED])->save());
            $expired++;
        }

        $done = $addresses->tidy(function (MailAddress $a) {
            $this->backup($a);
        });

        $asked = $this->askForMoonDates();

        $this->info("expired {$expired}, resumed {$done['resumed']}, deleted {$done['deleted']}, moon dates asked {$asked}");

        return self::SUCCESS;
    }

    /**
     * ⭐ চাঁদের তারিখের উৎসব (ঈদ, রমজান, পূজা…) — তারিখ না বসালে কিছুই যায় না, তাই প্রশাসককে কাজ হিসেবে মনে করানো
     *   (NEXUS-এর পর্যালোচনা S2, ২৭ নভেম্বর)। চাঁদের বছর ≈ 354 দিন: আগের বছরের তারিখ থেকে আন্দাজ, তার 30 দিন আগে থেকে;
     *   কখনো তারিখ না বসলে মাসে একবার। ⓘ একই উৎসবের খবর একই মাসে একবারই (`key`)।
     */
    private function askForMoonDates(): int
    {
        $moon = array_column(array_filter(\App\Modules\Mail\Services\MailDefaults::FESTIVALS, fn ($f) => $f[8] === null), 1, 0);
        $asked = 0;

        foreach (\App\Models\Company::query()->where('is_active', true)->pluck('id') as $companyId) {
            CompanyContext::forCompany((int) $companyId, function () use ($moon, &$asked) {
                $due = \App\Modules\Mail\Models\MailFestival::query()->where('active', true)->whereIn('key', array_keys($moon))->get()
                    ->filter(function ($f) {
                        if ($f->on_date !== null && ! $f->on_date->isPast()) {
                            return false;
                        }

                        return $f->on_date === null || now()->gte($f->on_date->copy()->addDays(354 - 30));
                    });

                if ($due->isEmpty()) {
                    return;
                }

                $admins = \App\Models\User::query()->where('is_active', true)->get()->filter(fn ($u) => $u->can('mail.manage'));

                foreach ($due as $f) {
                    foreach ($admins as $u) {
                        app(\App\Core\Services\NotificationService::class)->send(
                            $u,
                            'mail.festival_date',
                            __('mail::message.festival_date', ['name' => $f->name, 'when' => $f->when]),
                            null,
                            \Illuminate\Support\Facades\Route::has('mail.templates') ? route('mail.templates') : null,
                            key: 'mail:festival-date:'.$f->key.':'.now()->format('Y-m'),
                        );
                    }

                    $asked++;
                }
            });
        }

        return $asked;
    }

    /**
     * ⓘ cPanel-এর মেইল ফোল্ডার ~/mail/<domain>/<user> — থাকলে tar.gz করে storage/app/mail-backups-এ।
     * ⛔ হোস্টিংয়ে exec/proc_open বন্ধ — তাই PHP-র নিজের PharData (কোনো কমান্ড নয়)। ব্যাকআপ না হলে মোছা থামে।
     * ⓘ ফোল্ডারই না থাকলে (কখনো মেইল আসেনি) রাখার কিছু নেই — কারণ লিখে মোছা চলে।
     */
    private function backup(MailAddress $a): void
    {
        $home = (string) (getenv('HOME') ?: (function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['dir'] ?? '') : ''));
        $dir = $home.'/mail/'.$a->domain.'/'.$a->localPart();
        $target = storage_path('app/mail-backups');

        if ($home === '' || ! is_dir($dir)) {
            $this->warn("no maildir for {$a->email}");

            return;
        }

        @mkdir($target, 0750, true);
        $tar = $target.'/'.$a->email.'-'.now()->format('Ymd-His').'.tar';

        try {
            $archive = new \PharData($tar);
            $archive->buildFromDirectory($dir);
            $archive->compress(\Phar::GZ);
            unset($archive);
            @unlink($tar);
        } catch (\Throwable $e) {
            throw new \RuntimeException("backup failed for {$a->email} — not deleting ({$e->getMessage()})");
        }

        if (! is_file($tar.'.gz') || filesize($tar.'.gz') === 0) {
            throw new \RuntimeException("backup failed for {$a->email} — not deleting");
        }
    }
}
