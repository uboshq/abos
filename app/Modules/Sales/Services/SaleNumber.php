<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Support\CompanyContext;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\GatePass;
use App\Modules\Sales\Models\SalesInvoice;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * একটা বিক্রির একটাই নম্বর — মালিকের সিদ্ধান্ত, ২৯ সেপ্টেম্বর ২০২৬।
 *
 * ⭐ নিয়ম: নম্বর জন্মায় DO বা সরাসরি বিক্রিতে — *"sales team er kaj zekhane ses, sekhan
 * thekei"* (উদ্ধৃতি আর আদেশের নিজের নম্বর থাকে)। তারপর চালান, গেট পাস, বিল, ফেরত — সবাই
 * একই S-নম্বর। ⓘ একই বিক্রিতে একই ধরনের দ্বিতীয় কাগজ হলে S-0012/2, তৃতীয়টা /3; প্রথমটা
 * লেজ ছাড়া, কারণ বেশিরভাগ বিক্রিতে দ্বিতীয় কাগজই হয় না, আর পরে "/1" বসালে আগে ছাপা
 * কাগজের সাথে মিলত না।
 *
 * ⚠️ প্রতিটা টেবিলে (`company_id`, `document_no`) অনন্য — চালান S-0012 আর বিল S-0012 আলাদা
 * টেবিলে, তাই সংঘাত নেই; শেষ পাহারা ডাটাবেসের ইনডেক্স।
 *
 * ── ⭐ কাগজের নিজের উপসর্গ — মালিক, ২ অক্টোবর ২০২৬ ([[docs/বিক্রয়ের কাজের ধারা — ২ অক্টোবর.md]] §৩) ──
 * *"ইনভয়েস আর চালান এক নম্বর, কেবল উপসর্গ আলাদা: INV-0154 ↔ CHA-0154"*। বিক্রির নম্বর (`sale_no`)
 * S-0154-ই থাকে — সেটাই সুতো (ফেরত, গেট পাস, খোঁজা); কাগজের `document_no` তার লেজ নিয়ে নিজের উপসর্গ
 * বসায়: বিল INV-0154, চালান CHA-0154, গেট পাস GP-0154। একই কাগজ দ্বিতীয়বার হলে CHA-0154-2, -3।
 * ⓘ উপসর্গ নম্বর সিরিজের পর্দা থেকে বদলানো যায় (INV, DC, GP ধরন)। ⛔ পুরনো কাগজের নম্বর ছোঁয়া হয় না।
 */
final class SaleNumber
{
    /** নম্বর-সারির কাগজের ধরন — কন্ট্রোল প্যানেলের নম্বর সিরিজে উপসর্গ বদলানো যায় */
    public const DOC_TYPE = 'S';

    /** খসড়ার নিজের ক্রম — DRF-0001; আসল নম্বর কেবল নিশ্চিতে ([[draft()]]) */
    public const DRAFT_TYPE = 'DRF';

    /** কোন কাগজ কোন সিরিজের উপসর্গ নেয় — গুনতি কেবল S-এর, এগুলোর কেবল উপসর্গ */
    public const PAPER_TYPES = [
        SalesInvoice::class => 'INV',
        DeliveryChallan::class => 'DC',
        GatePass::class => 'GP',
    ];

    public function __construct(private readonly NumberSeriesEngine $numbers) {}

    /**
     * নতুন বিক্রির মূল নম্বর — DO বা সরাসরি বিক্রির জন্মে।
     *
     * ⓘ হাতে লেখা থাকলে সেটাই (যদি অন্য কোনো বিক্রিতে না থাকে), সিরিজ না ছুঁয়ে; সিরিজের
     * আগে থেকে দেখানো নম্বর হাতে-লেখা নয় — সেটা সিরিজেরই।
     *
     * @param  class-string<Model>  $model  যে কাগজ বিক্রিটা শুরু করছে (সাধারণত চালান)
     */
    public function begin(string $model, string $given = ''): string
    {
        $given = trim($given);

        if ($given !== '' && ! $this->numbers->isNextNumber(self::DOC_TYPE, $given)) {
            // ⓘ নতুন কাগজে হাতের নম্বর থাকে `sale_no`-তে, কাগজে উপসর্গসহ — দুই ঘরই দেখতে হয়
            if ($this->taken($model, $given) || $this->saleTaken($model, $given)) {
                throw ValidationException::withMessages([
                    'challan_no' => __('sales::validation.challan_no_taken', ['no' => $given]),
                ]);
            }

            return $given;
        }

        for ($attempt = 0; $attempt < 50; $attempt++) {
            $candidate = $this->numbers->next(self::DOC_TYPE);

            /* ⓘ পুরনো কাগজে S-নম্বরটাই `document_no`; নতুন কাগজে সেটা `sale_no` — দুই জায়গাতেই খালি হতে হয় */
            if (! $this->taken($model, $candidate) && ! $this->saleTaken($model, $candidate)
                && ! $this->taken($model, $this->paperNumber($model, $candidate))) {
                return $candidate;
            }
        }

        return $this->numbers->next(self::DOC_TYPE);
    }

    /**
     * ⭐ খসড়ার নম্বর — মালিক, ২ অক্টোবর ২০২৬: *"খসড়ার নম্বর আলাদা (DRF-0001)। আসল INV/CHA নম্বর বসে কেবল
     * নিশ্চিতের মুহূর্তে — সরকারি ক্রমে কোনো ফাঁক থাকে না"*।
     *
     * ⓘ খসড়ায় `sale_no` খালি থাকে; নিশ্চিতের লেনদেনের ভিতরে আসল নম্বর বসে, আর লেনদেন উল্টালে নম্বরটাও
     * ফেরে ([[NumberSeriesEngine::next()]])। ⚠️ মোছা খসড়া DRF ক্রমে ফাঁক রাখে — সেটা সরকারি ক্রম নয়।
     */
    public function draft(): string
    {
        return $this->numbers->next(self::DRAFT_TYPE);
    }

    /**
     * সত্যিই হাতে লেখা নম্বর কি — পর্দার আগে থেকে ভরা S-নম্বর হাতে লেখা নয়, সেটা সিরিজের।
     *
     * ⓘ হাতে লেখা নম্বর (পুরনো কাগজের বই থেকে) জন্মেই চূড়ান্ত — খসড়া নম্বর পায় না।
     */
    public function isHandWritten(string $given): bool
    {
        $given = trim($given);

        return $given !== '' && ! $this->numbers->isNextNumber(self::DOC_TYPE, $given);
    }

    /**
     * এই বিক্রির এই ধরনের কাগজের নম্বর — প্রথমটা CHA-0012, পরেরগুলো CHA-0012-2, -3 …
     *
     * ⓘ প্রথমটা লেজ ছাড়া: বেশিরভাগ বিক্রিতে দ্বিতীয় কাগজ হয়ই না, আর প্রথমটা ততক্ষণে ছাপা হয়ে গেছে —
     * পরে "-1" বসালে ছাপা কাগজের সাথে মিলত না। ⚠️ ২ অক্টোবরের আগে লেজ ছিল "/2"; পুরনোগুলো তেমনই থাকে।
     *
     * @param  class-string<Model>  $model
     */
    public function forPaper(string $model, string $saleNo): string
    {
        $base = $this->paperNumber($model, $saleNo);

        if (! $this->taken($model, $base)) {
            return $base;
        }

        for ($n = 2; $n < 1000; $n++) {
            $candidate = $base.'-'.$n;

            if (! $this->taken($model, $candidate)) {
                return $candidate;
            }
        }

        throw ValidationException::withMessages(['sale_no' => __('sales::validation.challan_no_taken', ['no' => $saleNo])]);
    }

    /**
     * ২৯ সেপ্টেম্বরের নিয়মে কাগজের নম্বর — কাগজ বিক্রির নম্বরই নেয়, দ্বিতীয়টা /2।
     *
     * ⓘ কেবল এককালীন পুরনো-বিক্রি নামকরণের জন্য ([[GiveOldSalesOneNumber]]) — ঐ আদেশ ২৯ সেপ্টেম্বরের নিয়মে লাইভে
     * চলে গেছে; আবার চালালে একই নিয়মে চলতে হয়, নইলে "পুরনো নম্বর যেমন আছে" কথাটা ভাঙত।
     * ⛔ নতুন কাগজে নয় — নতুন কাগজ নিজের উপসর্গ পায় ([[forPaper()]])।
     *
     * @param  class-string<Model>  $model
     */
    public function asOnTheTwentyNinth(string $model, string $saleNo): string
    {
        if (! $this->taken($model, $saleNo)) {
            return $saleNo;
        }

        for ($n = 2; $n < 1000; $n++) {
            $candidate = $saleNo.'/'.$n;

            if (! $this->taken($model, $candidate)) {
                return $candidate;
            }
        }

        throw ValidationException::withMessages(['sale_no' => __('sales::validation.challan_no_taken', ['no' => $saleNo])]);
    }

    /**
     * বিক্রির নম্বর থেকে কাগজের নম্বর — S-এর উপসর্গটা কাগজের উপসর্গে বদলায়, লেজ একই।
     *
     * ⓘ ছকে বছর বা শাখা থাকলেও চলে (S/2026/0154 → INV/2026/0154)। ⭐ হাতে লেখা নম্বর (পুরনো কাগজের বই থেকে,
     * S দিয়ে শুরু নয়) যেমন লেখা তেমনই সব কাগজে — যিনি "HAND-77" লিখলেন তিনি চালানে সেটাই চান, "CHA-HAND-77" নয়।
     * তালিকার বাইরের কাগজ বিক্রির নম্বরটাই নেয়।
     *
     * @param  class-string<Model>  $model
     */
    public function paperNumber(string $model, string $saleNo): string
    {
        $type = self::PAPER_TYPES[$model] ?? null;

        if ($type === null) {
            return $saleNo;
        }

        $salePrefix = $this->numbers->prefixOf(self::DOC_TYPE);
        $paperPrefix = $this->numbers->prefixOf($type);

        if ($salePrefix !== '' && str_starts_with($saleNo, $salePrefix)) {
            return $paperPrefix.substr($saleNo, strlen($salePrefix));
        }

        return $saleNo;
    }

    /**
     * এই বিক্রির নম্বর কি আগেই অন্য কোনো বিক্রিতে — `sale_no` ঘরে।
     *
     * @param  class-string<Model>  $model
     */
    private function saleTaken(string $model, string $saleNo): bool
    {
        return $model::query()->withoutGlobalScopes()
            ->where('company_id', CompanyContext::id())
            ->where('sale_no', $saleNo)
            ->exists();
    }

    /**
     * ⚠️ কোম্পানির নিজের কাগজেই — স্কোপ ছাড়া (শাখার স্কোপ অন্য শাখার একই নম্বর লুকাত, অথচ
     * ইনডেক্স কোম্পানি-জোড়া)।
     *
     * @param  class-string<Model>  $model
     */
    private function taken(string $model, string $documentNo): bool
    {
        return $model::query()->withoutGlobalScopes()
            ->where('company_id', CompanyContext::id())
            ->where('document_no', $documentNo)
            ->exists();
    }
}
