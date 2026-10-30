<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Support\CompanyContext;
use App\Models\Company;
use App\Modules\MasterData\Models\TransferMode;
use Illuminate\Console\Command;

/**
 * ট্রান্সফার মোডের বাংলা নাম — "এনপিএসবি" নয়, "NPSB" (fe, ২৪ অক্টোবর ২০২৬; আদায়ের পর্দা লাইভে যাওয়ার আগে)।
 *
 * ── কেন ───────────────────────────────────────────────────────────────
 * সেটআপের তালিকার seed ([[MasterListService]]) NPSB, BEFTN আর RTGS-এর বাংলা নাম লিপ্যন্তরে বসিয়েছিল। এগুলো ব্যাংকিং
 * পরিভাষা — ব্যাংকের স্লিপে, স্টেটমেন্টে আর মালিকের নকশায় ইংরেজিতেই। seed ঠিক হলো, কিন্তু চলমান কোম্পানির পুরনো সারি
 * নিজে বদলায় না — এই কমান্ড সেটাই করে।
 *
 * ── কী ছোঁয়, কী ছোঁয় না ──────────────────────────────────────────────
 * কেবল যে সারির কোড NPSB/BEFTN/RTGS **আর** বাংলা নাম ঠিক seed-এর পুরনো লিপ্যন্তর। কেউ নিজে নাম বসিয়ে থাকলে (যেকোনো
 * অন্য লেখা) সেটা তাঁর সিদ্ধান্ত — ছোঁয়া হয় না, কেবল দেখানো হয়। মোছা সারিও নয়।
 * বদল মডেলের `save()` দিয়ে, তাই [[IsAudited]] নিরীক্ষায় পুরনো আর নতুন নাম লেখে।
 *
 * চালানো: আগে `--dry` (কী বদলাত দেখায়, কিছু লেখে না), তারপর ছাড়া। শুধু-পড়ার স্ক্রিপ্ট: E:\ABOS\transfer-mode-names.php।
 */
class FixTransferModeNames extends Command
{
    protected $signature = 'abos:fix-transfer-mode-names {--dry : কী বদলাত তা দেখায়, কিছু লেখে না}';

    protected $description = 'NPSB/BEFTN/RTGS-এর বাংলা নাম লিপ্যন্তর থেকে ব্যাংকিং পরিভাষায় — কেবল seed-এর পুরনো নাম, হাতে বসানো নয়';

    /** কোড → seed-এর পুরনো লিপ্যন্তর; নতুন নাম কোডটাই */
    public const OLD = ['NPSB' => 'এনপিএসবি', 'BEFTN' => 'বিইএফটিএন', 'RTGS' => 'আরটিজিএস'];

    public function handle(): int
    {
        $dry = (bool) $this->option('dry');
        $changed = 0;
        $kept = 0;

        foreach (Company::query()->orderBy('id')->get() as $company) {
            CompanyContext::forCompany($company->id, function () use ($company, $dry, &$changed, &$kept): void {
                foreach (TransferMode::query()->whereIn('code', array_keys(self::OLD))->orderBy('code')->get() as $mode) {
                    $code = (string) $mode->code;

                    if (trim((string) $mode->name_bn) !== self::OLD[$code]) {
                        $this->line("  {$company->code}: {$code} — বাংলা নাম \"{$mode->name_bn}\" হাতে বসানো, ছোঁয়া হলো না");
                        $kept++;

                        continue;
                    }

                    $this->line("  {$company->code}: {$code} — \"{$mode->name_bn}\" → \"{$code}\"".($dry ? ' (--dry: লেখা হয়নি)' : ''));
                    $changed++;

                    if (! $dry) {
                        $mode->name_bn = $code;
                        $mode->save();
                    }
                }
            });
        }

        $this->info(($dry ? "বদলানোর যোগ্য {$changed}টা" : "বদলানো হলো {$changed}টা")." · হাতে বসানো, ছোঁয়া হয়নি {$kept}টা");

        return self::SUCCESS;
    }
}
