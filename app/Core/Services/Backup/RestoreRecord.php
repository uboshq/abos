<?php

declare(strict_types=1);

namespace App\Core\Services\Backup;

use App\Core\Engines\Audit\AuditEngine;
use App\Models\Company;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ফেরানোর দাগ — কে, কখন, কোন ফাইল থেকে — চূড়ান্ত অডিট, ৩০ সেপ্টেম্বর ২০২৬ (⛔১৯)।
 *
 * ── ⛔ যা ছিল ─────────────────────────────────────────────────────
 * `abos:restore` গোটা খাতা মুছে একটা পুরনো দিনে ফিরিয়ে নেয় — ABOS-এর
 * সবচেয়ে ধ্বংসাত্মক কাজ — অথচ কোথাও একটা দাগও রাখত না। পরদিন কেউ
 * দেখত গতকালের বিল নেই, আর "কেউ ফিরিয়েছিল কি না" প্রশ্নের উত্তর নেই।
 *
 * ── ⓘ দাগ দুই জায়গায়, আর কেন ─────────────────────────────────
 * - **লগ ফাইলে** — শুরুর আগে, শেষে, আর ব্যর্থ হলে। ⚠️ খাতার ভেতরে
 *   শুরুর দাগ লিখে লাভ নেই: ফেরানো ঠিক ওই খাতাটাই মুছে দেয়। লগ ফাইল
 *   ডাটাবেজের বাইরে, তাই টিকে থাকে।
 * - **প্রতিটা কোম্পানির অডিট খাতায়** — ফেরানোর **পরে**, ফেরানো খাতার
 *   ভেতরে। ডাম্প গোটা ডাটাবেজের, তাই প্রতিটা কোম্পানির মালিক নিজের
 *   পর্দায় দেখেন তাঁর খাতা কবে কোন দিনে ফিরেছিল।
 */
final class RestoreRecord
{
    public const RESTORED = 'db_restored';

    public const FAILED = 'db_restore_failed';

    public function __construct(private readonly AuditEngine $audit) {}

    /** ফেরানো শুরু — কেবল লগে, কারণ খাতাটা এখনই মুছবে */
    public function starting(string $file, ?string $safety): void
    {
        Log::warning('abos:restore starting', $this->context($file, $safety));
    }

    /** ফেরানো শেষ — লগে, আর ফেরানো খাতার প্রতিটা কোম্পানিতে */
    public function restored(string $file, ?string $safety): int
    {
        Log::warning('abos:restore finished', $this->context($file, $safety));

        return $this->everyCompany(self::RESTORED, $this->reason($file, $safety));
    }

    /**
     * ফেরানো ব্যর্থ — লগে সবসময়; খাতায় যদি খাতাটা তখনো পড়া যায়।
     *
     * ⚠️ ব্যর্থতা খাতাটাকে অর্ধেক অবস্থায় ফেলে যেতে পারে, তাই অডিট লিখতে
     * গিয়ে আরেকটা ভাঙন আসল কারণটাকে ঢেকে দিলে চলবে না।
     */
    public function failed(string $file, ?string $safety, Throwable $why): int
    {
        Log::critical('abos:restore failed', $this->context($file, $safety) + ['error' => $why->getMessage()]);

        try {
            return $this->everyCompany(self::FAILED, $this->reason($file, $safety).' · '.$why->getMessage());
        } catch (Throwable) {
            return 0;
        }
    }

    private function everyCompany(string $action, string $reason): int
    {
        $written = 0;

        foreach (Company::query()->get() as $company) {
            if ($this->audit->recordAction($company, $action, mb_substr($reason, 0, 1000)) !== null) {
                $written++;
            }
        }

        return $written;
    }

    private function reason(string $file, ?string $safety): string
    {
        $context = $this->context($file, $safety);

        return __('core.restore_reason', [
            'file' => $context['file'],
            'safety' => $context['safety'] ?? '—',
            'by' => $context['by'],
        ]);
    }

    /**
     * @return array{file: string, safety: ?string, by: string}
     */
    private function context(string $file, ?string $safety): array
    {
        return [
            'file' => basename($file),
            'safety' => $safety !== null ? basename($safety) : null,
            /* ⓘ কমান্ড লাইনে লগইন করা মানুষ নেই — তাই সার্ভারের ব্যবহারকারী আর মেশিন */
            'by' => get_current_user().'@'.(gethostname() ?: '?'),
        ];
    }
}
