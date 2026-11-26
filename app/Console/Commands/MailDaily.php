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

        $this->info("expired {$expired}, resumed {$done['resumed']}, deleted {$done['deleted']}");

        return self::SUCCESS;
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
