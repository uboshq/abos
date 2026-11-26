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

    /** ⓘ cPanel-এর মেইল ফোল্ডার ~/mail/<domain>/<user> — থাকলে tar করে storage/app/mail-backups-এ; না থাকলে কারণ লিখে মোছা থামে না */
    private function backup(MailAddress $a): void
    {
        $home = (string) (getenv('HOME') ?: '');
        $dir = $home.'/mail/'.$a->domain.'/'.$a->localPart();
        $target = storage_path('app/mail-backups');

        if ($home === '' || ! is_dir($dir)) {
            $this->warn("no maildir for {$a->email}");

            return;
        }

        @mkdir($target, 0750, true);
        $file = $target.'/'.$a->email.'-'.now()->format('Ymd-His').'.tar.gz';
        $cmd = 'tar -czf '.escapeshellarg($file).' -C '.escapeshellarg(dirname($dir)).' '.escapeshellarg(basename($dir));
        @exec($cmd, $out, $rc);

        if ($rc !== 0 || ! is_file($file)) {
            throw new \RuntimeException("backup failed for {$a->email} — not deleting");
        }
    }
}
