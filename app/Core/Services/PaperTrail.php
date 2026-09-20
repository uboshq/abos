<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Support\CompanyContext;
use App\Models\DocumentDelivery;
use App\Models\DocumentShare;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * কাগজ কোথায় গেল — ছাপা, নামানো, পাঠানো, খোলা।
 *
 * ── কেন কোরে, প্রতিটা মডিউলে নয় ─────────────────────────────────────
 * বিক্রয়ের নিজের একটা গোনা ছিল (`PrintJob`), কিন্তু ভাউচার বা বেতন-স্লিপের
 * ছিল না। ⓘ মালিক চেয়েছেন **সব কাগজের** হিসাব এক জায়গায়, তাই গোনাটা
 * কোরের — আর প্রতিটা প্রিন্ট কন্ট্রোলার কেবল একটা লাইন ডাকে।
 *
 * ⚠️ বিক্রয়ের পুরনো `PrintJob` তোলা হয়নি: ওটা আরও কিছু করে (দ্বিতীয়বার
 * ছাপলে কাগজে DUPLICATE, আর "এখনো ছাপা হয়নি" তালিকা)। ⓘ দুইটা একসাথে
 * চলে — এটা হিসাব রাখে, ওটা কাজ চালায়।
 */
final class PaperTrail
{
    /**
     * ⭐ নথির ধরন → যে রুট ঐ কাগজটা আঁকে।
     *
     * ── ⚠️ কেন এই মানচিত্রটা দরকার হলো ──────────────────────────────
     * "কে কখন ছেপেছে" পাতাটা লগইনের ভিতরে ছিল, কিন্তু **কোনো অনুমতি
     * চাইত না** — অর্থাৎ যেকোনো লগইন করা মানুষ ঠিকানা টাইপ করে দেখে
     * নিতে পারতেন কে কোন ভাউচার ছেপেছে, আর গ্রাহক সেটা খুলেছেন কি না।
     * ⓘ কাগজের **ভিতরের কথা** ওখানে নেই, কিন্তু ওটাও আমাদের ভিতরের কথা
     * (abos-d1 ধরেছে, abos-8b জানিয়েছে, ২০ সেপ্টেম্বর ২০২৬)।
     *
     * ⛔ নতুন কোনো "ইতিহাস দেখার ক্ষমতা" বানানো হয়নি — সেটা মিথ্যা
     * পাহারা হত। ⓘ পাহারাটা **ঐ কাগজের নিজের অনুমতি**: যিনি বিলটা
     * ছাপতে পারেন, তিনিই দেখবেন সেটা কে ছেপেছিল
     * ([[App\Http\Controllers\PaperShareController]]-এর মতোই নিয়ম)।
     *
     * ⚠️ নতুন কাগজ যোগ করলে এখানেও একটা সারি — নাহলে তার ইতিহাসের
     * পাতাটা ৪০৪ দেবে, আর সেটাই ইচ্ছাকৃত: অনুমতি না জানলে দেখানো নয়
     * ([[Tests\Feature\Core\TheBillWentOutAndNobodyKeptCountTest]] পাহারা দেয়)।
     *
     * @var array<string, string>
     */
    public const DOCUMENT_ROUTES = [
        'sales_invoice' => 'sales.print.invoice',
        'sales_challan' => 'sales.print.challan',
        'sales_order' => 'sales.print.order',
        'sales_collection' => 'sales.print.receipt',
        'purchase_bill' => 'purchase.print.bill',
        'purchase_order' => 'purchase.print.order',
        'purchase_receipt' => 'purchase.print.receipt',
        'purchase_return' => 'purchase.print.return',
        'accounts_voucher' => 'accounts.voucher.print',
        'accounts_transfer' => 'accounts.transfer.print',
        'hr_payslip' => 'hr.payslip.print',
        'inventory_transfer' => 'inventory.transfer.print',
    ];

    /**
     * ঐ ধরনের কাগজ দেখতে যে অনুমতিগুলো লাগে।
     *
     * ⓘ রুটের নিজের `can:` শর্তগুলো — আলাদা করে কোথাও লেখা নেই, তাই
     * রুটের পাহারা বদলালে এটাও নিজে থেকেই বদলায়।
     *
     * @return list<string>
     */
    public static function abilitiesFor(string $documentType): array
    {
        $name = self::DOCUMENT_ROUTES[$documentType] ?? null;

        if ($name === null) {
            return [];
        }

        $route = Route::getRoutes()->getByName($name);

        if ($route === null) {
            return [];
        }

        $out = [];

        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'can:')) {
                $out[] = explode(',', substr($middleware, 4))[0];
            }
        }

        return $out;
    }

    /**
     * একটা কাগজ বেরোল।
     *
     * ⓘ ছাপা আর নামানো দুইটাই "বেরোনো", কিন্তু আলাদা সারি — মালিক
     * জানতে চাইবেন কোনটা কতবার।
     */
    public function record(
        string $documentType,
        int $documentId,
        string $paper,
        string $how,
        ?string $documentNo = null,
        ?DocumentShare $share = null,
        ?string $fromIp = null,
    ): DocumentDelivery {
        return DocumentDelivery::query()->create([
            'company_id' => $share?->company_id ?? CompanyContext::id(),
            'branch_id' => $share?->branch_id ?? CompanyContext::branchId(),
            'document_type' => $documentType,
            'document_id' => $documentId,
            'document_no' => $documentNo,
            'paper' => $paper,
            'how' => $how,
            'share_id' => $share?->id,
            'from_ip' => $fromIp,
            'created_by' => auth()->id(),
        ]);
    }

    /**
     * গ্রাহককে পাঠানোর লিংক — একটা কাগজের, ৩০ দিনের।
     *
     * ── ⚠️ একই কাগজের জন্য নতুন লিংক বারবার নয় ──────────────────────
     * বেঁচে থাকা লিংক থাকলে সেটাই ফেরে। ⓘ নাহলে একজন গ্রাহকের হাতে
     * তিনটা লিংক থাকত আর কোনটা কাজ করছে তা বলার উপায় থাকত না; আর
     * "কয়টা পাঠানো হলো" সংখ্যাটাও ফুলে যেত।
     *
     * @param  array<string, mixed>  $routeParams
     */
    public function share(
        string $routeName,
        array $routeParams,
        string $documentType,
        int $documentId,
        string $paper,
        ?string $documentNo = null,
    ): DocumentShare {
        return DB::transaction(function () use ($routeName, $routeParams, $documentType, $documentId, $paper, $documentNo) {
            $alive = DocumentShare::query()
                ->alive()
                ->where('document_type', $documentType)
                ->where('document_id', $documentId)
                ->where('paper', $paper)
                ->latest('id')
                ->first();

            if ($alive !== null) {
                return $alive;
            }

            $share = DocumentShare::query()->create([
                'company_id' => CompanyContext::id(),
                'branch_id' => CompanyContext::branchId(),
                'route_name' => $routeName,
                'route_params' => $routeParams,
                'document_type' => $documentType,
                'document_id' => $documentId,
                'document_no' => $documentNo,
                'paper' => $paper,

                /*
                 * ⛔ `Str::random()` নয় — ওটা `random_bytes` ব্যবহার করলেও
                 * উদ্দেশ্যটা বলে না। ⓘ এখানে ৬৪ অক্ষর মানে আন্দাজ করার
                 * কোনো বাস্তব পথ নেই, আর সেটাই এই লিংকের একমাত্র পাহারা।
                 */
                'token' => bin2hex(random_bytes(32)),
                'expires_at' => Carbon::now()->addDays(DocumentShare::LIVES_DAYS),
                'created_by' => auth()->id(),
            ]);

            $this->record($documentType, $documentId, $paper, DocumentDelivery::SHARED, $documentNo, $share);

            return $share;
        });
    }

    /**
     * লিংকটা খোলা হলো — সারিটা ফেরত দেয়, আর প্রসঙ্গটা বসিয়ে দেয়।
     *
     * ⚠️ মরা বা বাতিল লিংকে `null` — ডাকনেওয়ালা তখন ৪০৪ দেখায়, "মেয়াদ
     * শেষ" নয়। ⓘ পার্থক্যটা ইচ্ছাকৃত: কোন চাবি একদিন সত্যি ছিল, সেটা
     * বাইরের কাউকে জানানোর দরকার নেই।
     */
    public function open(string $token, Request $request): ?DocumentShare
    {
        $share = DocumentShare::byToken($token);

        if ($share === null || ! $share->isAlive()) {
            return null;
        }

        /*
         * ⓘ প্রসঙ্গটা লিংকের নিজের কোম্পানি থেকে, লগইন থেকে নয় — কারণ
         * এখানে কেউ লগ-ইন নেই। ⚠️ এটা না বসালে কাগজটা আঁকার সময় প্রতিটা
         * কোয়েরি "কোন কোম্পানি" প্রশ্নে ফাঁকা পেত, আর সারিগুলোই পাওয়া যেত না।
         */
        CompanyContext::set($share->company_id, $share->branch_id);

        $share->forceFill([
            'opened_count' => $share->opened_count + 1,
            'last_opened_at' => Carbon::now(),
        ])->saveQuietly();

        $this->record(
            $share->document_type,
            (int) $share->document_id,
            $share->paper,
            DocumentDelivery::OPENED,
            $share->document_no,
            $share,
            $request->ip(),
        );

        return $share;
    }

    /**
     * এই কাগজটা কতবার বেরিয়েছে — কোন পথে কতবার।
     *
     * @return array<string, int>
     */
    public function countsFor(string $documentType, int $documentId): array
    {
        $rows = DocumentDelivery::query()
            ->where('document_type', $documentType)
            ->where('document_id', $documentId)
            ->selectRaw('how, COUNT(*) as n')
            ->groupBy('how')
            ->pluck('n', 'how');

        return collect(DocumentDelivery::WAYS)
            ->mapWithKeys(fn (string $way) => [$way => (int) ($rows[$way] ?? 0)])
            ->all();
    }
}
