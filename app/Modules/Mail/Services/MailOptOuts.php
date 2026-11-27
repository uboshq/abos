<?php

declare(strict_types=1);

namespace App\Modules\Mail\Services;

use App\Core\Support\CompanyContext;
use App\Modules\Mail\Models\MailOptOut;
use App\Modules\Mail\Models\MailTemplate;
use Illuminate\Support\Facades\URL;

/**
 * ⭐ অফারের মেইল বন্ধ করার এক জায়গা (NEXUS-এর পর্যালোচনা R2, ২৭ নভেম্বর ২০২৬).
 *
 * ⓘ Gmail আর Yahoo-র ২০২৪-এর নিয়ম: বিজ্ঞাপন বা অফারের মেইলে দুটো জিনিস লাগে —
 *   1. মেইলের নিচে চোখে পড়ার মতো লিংক;
 *   2. RFC 8058-এর এক চাপের "Unsubscribe" header (`List-Unsubscribe` আর `List-Unsubscribe-Post`)।
 *   বন্ধ করা ঠিকানায় এরপর এমন মেইল যায় না।
 * ⓘ লেনদেনের মেইল এই নিয়মের বাইরে: বিল, রসিদ, হিসাব, চালান, ক্রয়াদেশ, বকেয়ার স্মরণ।
 * ⛔ লিংকটা সই করা (signed URL), তাই কেউ অন্যের ঠিকানা বানিয়ে বন্ধ করতে পারে না। এর মেয়াদ নেই, পুরনো মেইলের লিংকও চলে।
 */
final class MailOptOuts
{
    /** ⓘ বিজ্ঞাপন বা অফারের মেইল: উৎসব, অফার, নতুন দামের তালিকা */
    public static function isMarketing(?MailTemplate $t): bool
    {
        return $t !== null && (in_array((string) $t->category, ['offer', 'festival'], true) || $t->key === 'price_list');
    }

    public function stopped(string $email, ?int $companyId = null): bool
    {
        $companyId ??= CompanyContext::id();

        return $companyId !== null && MailOptOut::query()->withoutGlobalScopes()->where('company_id', $companyId)
            ->where('email', mb_strtolower(trim($email)))->exists();
    }

    public function stop(int $companyId, string $email, ?string $source, string $via): void
    {
        CompanyContext::forCompany($companyId, function () use ($email, $source, $via) {
            MailOptOut::query()->firstOrCreate(['email' => mb_strtolower(trim($email))], ['source' => $source, 'via' => $via]);
        });
    }

    public function link(string $email, ?string $source = null, ?int $companyId = null): string
    {
        return URL::signedRoute('mail.unsubscribe', array_filter([
            'c' => $companyId ?? CompanyContext::id(), 'e' => mb_strtolower(trim($email)), 's' => $source,
        ], fn ($v) => $v !== null && $v !== ''));
    }

    /** @return array<string, string> RFC 8058 */
    public function headers(string $link): array
    {
        return ['List-Unsubscribe' => '<'.$link.'>', 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click'];
    }

    public function footer(string $link): string
    {
        return '<p style="margin:14px 0 0;font:12px Segoe UI,Arial,Nirmala UI,Noto Sans Bengali,Vrinda,sans-serif;color:#5b6576">'
            .e(__('mail::field.unsubscribe.footer')).' <a href="'.e($link).'" style="color:#1a3fd0">'.e(__('mail::field.unsubscribe.stop')).'</a></p>';
    }
}
