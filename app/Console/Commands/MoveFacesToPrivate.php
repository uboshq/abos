<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Engines\Audit\AuditEngine;
use App\Core\Services\AvatarService;
use App\Models\Company;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * ⭐ পুরনো public মুখগুলো ব্যক্তিগত ডিস্কে — একবারের কাজ (fe-র হ্যাঁ, a6, ২০ অক্টোবর ২০২৬)।
 *
 * ── কেন ──────────────────────────────────────────────────────────────
 * ২০ অক্টোবর থেকে নতুন ছবি ব্যক্তিগত ডিস্কে, দেখা যায় কেবল সহকর্মীর চোখে ([[UserPhotoController]])। ⚠️ কিন্তু আগের
 * ছবিগুলো `public`-এ রয়ে গিয়েছিল, আর তাদের `/storage/avatars/…` ঠিকানা লগইন ছাড়াই খোলে। এই কমান্ড সেগুলো সরায়।
 *
 * ── ⛔ নিয়ম ──────────────────────────────────────────────────────────
 * - **আগে `--dry-run`**: কে সরবে, কার ফাইল নেই, কে আগেই ব্যক্তিগত — গুনে আর নাম ধরে দেখায়, কিছু লেখে না।
 * - ডিস্কের নিয়ম আর প্রতিটা সরানোর ধাপ [[AvatarService::moveToPrivate()]]-এর: লেখা → ফিরে পড়ে sha256 মেলানো →
 *   সারি বদল ট্রানজ্যাকশনে → তারপরই public মোছা। ⓘ মাঝপথে ভাঙলেও প্রত্যেকের ছবি পড়া যায়; দুইবার চালানো ক্ষতিহীন।
 * - ফাইল নেই: সারি ছোঁয়া হয় না, কেবল গোনা আর জানানো।
 * - ⛔ সময়সূচিতে নয় — হাতে, একবার।
 *
 * ── নিরীক্ষা ─────────────────────────────────────────────────────────
 * শেষে একটা সারাংশ সারি, প্রতিটা কোম্পানির খাতায় একটা — গোনাগুলো, কারও পথ বা নাম নয়। ⓘ ধরনটা ধার করা
 * [[App\Core\Services\Backup\RestoreRecord::everyCompany()]] থেকে: ব্যবস্থা-জোড়া কাজের খাতা কোম্পানি ধরে
 * (`recordAction($company, …)`), কারণ [[AuditEngine::record()]] কোম্পানি ছাড়া কিছু লেখে না, আর মানুষেরা কয়েক
 * কোম্পানিতে ছড়ানো — যে কোম্পানিরই নিরীক্ষক দেখুন, সারিটা পাবেন।
 */
final class MoveFacesToPrivate extends Command
{
    public const ACTION = 'faces_moved_private';

    protected $signature = 'abos:faces-private
                            {--dry-run : কেবল গুনে দেখান — কিছু সরানো, লেখা বা মোছা হবে না}';

    protected $description = 'Move the old public profile photos to the private disk (one-off; run --dry-run first)';

    public function handle(AvatarService $avatars, AuditEngine $audit): int
    {
        $dry = (bool) $this->option('dry-run');

        $counts = [
            AvatarService::MOVE_MOVED => 0,
            'already_private' => 0,
            AvatarService::MOVE_SKIPPED => 0,
            AvatarService::MOVE_MISSING => 0,
            AvatarService::MOVE_FAILED => 0,
        ];

        // ⓘ নরম-মোছা মানুষও — চলে যাওয়া কর্মীর মুখও লগইন ছাড়া খোলা থাকার কথা নয়
        User::query()->withTrashed()
            ->whereNotNull('avatar_path')->where('avatar_path', '!=', '')
            ->chunkById(200, function ($users) use ($avatars, $dry, &$counts): void {
                foreach ($users as $user) {
                    if ($avatars->onPrivateDisk($user->getRawOriginal('avatar_path'))) {
                        $counts['already_private']++;

                        continue;
                    }

                    $outcome = $avatars->moveToPrivate($user, $dry);
                    $counts[$outcome]++;

                    // ⓘ কনসোলে মানুষটার id আর public_id — ফাইলের পথ নয়
                    $who = '#'.$user->getKey().' ('.$user->public_id.')';

                    match ($outcome) {
                        AvatarService::MOVE_MOVED => $this->line(($dry ? 'would move ' : 'moved      ').$who),
                        AvatarService::MOVE_MISSING => $this->warn('missing    '.$who.' — public file gone; row left as it is'),
                        AvatarService::MOVE_FAILED => $this->error('failed     '.$who.' — not an image or the copy did not verify; public file kept'),
                        default => $this->line('skipped    '.$who),
                    };
                }
            });

        $summary = sprintf(
            'moved=%d already_private=%d skipped=%d missing=%d failed=%d',
            $counts[AvatarService::MOVE_MOVED],
            $counts['already_private'],
            $counts[AvatarService::MOVE_SKIPPED],
            $counts[AvatarService::MOVE_MISSING],
            $counts[AvatarService::MOVE_FAILED],
        );

        if ($dry) {
            $this->info('DRY RUN — nothing written. '.str_replace('moved=', 'would_move=', $summary));

            return self::SUCCESS;
        }

        // ⭐ সারাংশ — কোম্পানি প্রতি একটা সারি, কেবল গোনা ([[RestoreRecord::everyCompany()]]-এর ধরন)
        foreach (Company::query()->get() as $company) {
            $audit->recordAction($company, self::ACTION, 'console: '.$summary);
        }

        $this->info($summary);

        return $counts[AvatarService::MOVE_FAILED] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
