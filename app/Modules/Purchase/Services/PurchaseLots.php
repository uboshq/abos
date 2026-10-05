<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Services;

use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Models\IssuedNumber;
use App\Models\NumberSeries;
use App\Modules\Inventory\Models\Batch;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * ⭐ লটের নম্বর নিজে থেকে — মালিকের আদেশ, ৫ অক্টোবর ২০২৬: *"এখন থেকে লটে নিজে থেকে প্রস্তাব দেবে, DDMMYY/XX-LOT; মানে
 * আজ কেনা হলে 051026/01-LOT"*।
 *
 * ── কী হয় ─────────────────────────────────────────────────────────────────
 * ক্রয় বিল, মাল গ্রহণ আর সরাসরি ক্রয় (যেটা বিলের পথেই লেখে) — লট ধরা পণ্যের সারিতে লট খালি রেখে সংরক্ষণ করলে নম্বর-ক্রমের
 * 'LOT' থেকে একটা নম্বর বসে, **কাগজের তারিখে** (আজকের নয়)। পর্দা নম্বরটা আগে থেকে কেবল দেখায় ([[upcoming()]]) — খরচ হয়
 * শুধু সংরক্ষণে।
 *
 * ── ⭐ একটা কাগজ, একটা লট ─────────────────────────────────────────────────
 * কাগজের যত সারিতে লট খালি, সবগুলো **একই** নম্বর পায় (০১); পরের কাগজ পায় ০২। লট পণ্য ধরে অনন্য
 * (`inv_batches` — company, product, batch_no), তাই তিনটা আলাদা পণ্যে একই 051026/01-LOT তিনটা আলাদা লট। খসড়া আবার খুলে
 * সারি যোগ করলে কাগজের আগের নম্বরটাই ফেরে, নতুন নম্বর কাটা হয় না ([[forPaper()]])।
 *
 * ── হাতে লেখা লট ──────────────────────────────────────────────────────────
 * যা লেখা, তা-ই থাকে — সিরিজ ছোঁয় না। ⓘ আগের নিয়ম অক্ষত: একই পণ্যে আগে থেকে থাকা লট নম্বর লিখলে মালটা **সেই পুরনো
 * লটেই** ঢোকে, পুরনো মেয়াদ আর ছাপা দাম নিয়ে ([[BatchService::receive()]] firstOrCreate)।
 * ⚠️ কিন্তু নিজে থেকে বসানো নম্বর কখনো পুরনো লটে মেশে না — কাগজের কোনো পণ্যে নম্বরটা আগেই থাকলে (কেউ হাতে লিখেছিলেন)
 * পরেরটা নেওয়া হয়।
 */
final class PurchaseLots
{
    public const DOC_TYPE = 'LOT';

    public function __construct(private readonly NumberSeriesEngine $numbers) {}

    /**
     * এই কাগজের লট নম্বর — কাগজ আগে একটা পেয়ে থাকলে সেটাই, নইলে নতুন।
     *
     * @param  list<int>  $productIds  কাগজের লট ধরা পণ্যগুলো — ধাক্কা দেখতে
     */
    public function forPaper(string $sourceType, int $sourceId, Carbon $date, ?int $branchId, array $productIds): string
    {
        $kept = IssuedNumber::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('is_voided', false)
            ->whereIn('number_series_id', NumberSeries::query()->where('doc_type', self::DOC_TYPE)->select('id'))
            ->latest('id')
            ->value('document_no');

        if ($kept !== null) {
            return (string) $kept;
        }

        for ($try = 0; $try < 100; $try++) {
            $number = $this->numbers->next(self::DOC_TYPE, $branchId, $date, $sourceType, $sourceId);

            if (! Batch::query()->whereIn('product_id', $productIds)->where('batch_no', $number)->exists()) {
                return $number;
            }
        }

        throw new RuntimeException('No free lot number for this paper after 100 tries.');
    }

    /** পরের লট নম্বরটা দেখতে কেমন — কিছু খরচ না করে (পর্দার প্রস্তাব) */
    public function upcoming(Carbon $date, ?int $branchId = null): ?string
    {
        return $this->numbers->upcoming(self::DOC_TYPE, $branchId, $date);
    }
}
