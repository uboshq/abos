<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Services;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Approval;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Purchase\Models\Payment;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Models\PurchaseReturn;
use App\Modules\Sales\Models\Collection;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesReturn;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * যে কাগজ খাতায় ওঠার কথা, অথচ ওঠেনি।
 *
 * ── কেন, ২০ সেপ্টেম্বর ২০২৬ ─────────────────────────────────────────
 * মালিক: *"Accounts e post pending hoye thak le bujazay na tar jonno ki kono
 * porda lage?"* ⓘ পোস্টিং মনিটরের "আটকে আছে" ট্যাব ছিল, কিন্তু সেটা কেবল
 * **ভাউচার** দেখত। অথচ সবচেয়ে বিপজ্জনক অবস্থাটা ভাউচারের নয়: একটা বিক্রয়
 * বিল নিশ্চিত হয়ে গেছে, কাগজে কাজ শেষ মনে হচ্ছে, অথচ খাতায় সেটা নেই।
 * তখন লাভ-ক্ষতি, বকেয়া আর মজুদের মূল্য — তিনটাই চুপচাপ ভুল।
 *
 * ── কীভাবে ধরা হয় ───────────────────────────────────────────────────
 * খতিয়ানের প্রতিটা সারিতে লেখা থাকে সেটা কোন কাগজ থেকে এসেছে
 * (`source_type`/`source_id`)। ⭐ তাই প্রশ্নটা সোজা: নিশ্চিত হওয়া কাগজটার
 * নামে খতিয়ানে একটাও সারি আছে কি না।
 *
 * ⚠️ তালিকাটা হাতে লেখা, স্বয়ংক্রিয় নয়। কারণ সব কাগজ খাতায় ওঠে না —
 * আদেশ, চালান বা গ্রহণ ওঠে না, আর ওগুলোকে "আটকে আছে" বললে তালিকাটা
 * রোজ মিথ্যা বলত, আর তিন দিনে কেউ আর ওটা দেখত না।
 */
final class PostingBacklog
{
    /**
     * যে কাগজগুলো নিশ্চিত হলেই খাতায় ওঠার কথা।
     *
     * ⓘ চাবিটা `source_type` — খতিয়ানে ঠিক এই নামেই লেখা থাকে।
     *
     * @var array<string, class-string<Model>>
     */
    public const MUST_REACH_THE_BOOKS = [
        'sales_invoice' => SalesInvoice::class,
        'sales_return' => SalesReturn::class,
        'collection' => Collection::class,
        'purchase_bill' => PurchaseBill::class,
        'purchase_return' => PurchaseReturn::class,
        'purchase_payment' => Payment::class,
    ];

    /**
     * কত দিন পিছনে দেখা হয়।
     *
     * ⚠️ সীমা দরকার, কারণ পুরনো আমদানি করা কাগজ কখনোই খাতায় ওঠেনি আর
     * উঠবেও না — ওগুলো তালিকায় এলে আসল সমস্যাটা ঐ ভিড়ে হারিয়ে যেত।
     */
    public const LOOK_BACK_DAYS = 120;

    /**
     * @return list<array{source: string, document_no: ?string, trx_date: ?string, amount: ?string, id: int}>
     */
    public function notInTheBooks(): array
    {
        $since = CarbonImmutable::today()->subDays(self::LOOK_BACK_DAYS)->toDateString();
        $rows = [];

        foreach (self::MUST_REACH_THE_BOOKS as $source => $class) {
            $model = new $class;

            $stuck = $class::query()
                ->where('status', DocumentStatus::CONFIRMED)
                ->whereDate('trx_date', '>=', $since)
                /*
                 * ⓘ খতিয়ানের সারি আছে কি না — সেটাই একমাত্র প্রশ্ন। উল্টো
                 * এন্ট্রিও সারি, তাই বাতিল করা কাগজও "খাতায় আছে" গোনা হয়,
                 * আর সেটাই ঠিক: ওটার হিসাব খাতায় মিটে গেছে।
                 */
                ->whereNotExists(function ($query) use ($source, $model): void {
                    $query->selectRaw('1')
                        ->from('ledger_entries')
                        ->where('ledger_entries.source_type', $source)
                        ->whereColumn('ledger_entries.source_id', $model->getTable().'.id');
                })
                ->orderBy('trx_date')
                ->limit(100)
                ->get();

            foreach ($stuck as $one) {
                $rows[] = [
                    'source' => $source,
                    'id' => (int) $one->getKey(),
                    'document_no' => $one->document_no ?? null,
                    'trx_date' => $one->trx_date?->toDateString(),
                    'amount' => isset($one->total) ? (string) $one->total : (isset($one->amount) ? (string) $one->amount : null),
                ];
            }
        }

        usort($rows, fn (array $a, array $b) => [$a['trx_date'], $a['source']] <=> [$b['trx_date'], $b['source']]);

        return $rows;
    }

    /**
     * অন্য মডিউলের যে কাগজগুলো সইয়ের অপেক্ষায় — টাকার কাগজ হলে।
     *
     * ⓘ ভাউচারগুলো পোস্টিং মনিটরের নিজের তালিকায় আছে, তাই এখানে বাকিরা:
     * জমা, কাউন্টারের পরিশোধ, খরচের দাবি — যেগুলো সই না হলে খাতায় ওঠে না।
     *
     * @return array<string, int> কাজের নাম → কয়টা
     */
    public function awaitingSignature(): array
    {
        return Approval::query()
            ->pending()
            ->where('approvable_type', '!=', Voucher::class)
            ->selectRaw('module, COUNT(*) as tally')
            ->groupBy('module')
            ->pluck('tally', 'module')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    /** ড্যাশবোর্ডের কার্ডের জন্য — কেবল সংখ্যা, সস্তা প্রশ্ন। */
    public function count(): int
    {
        $since = CarbonImmutable::today()->subDays(self::LOOK_BACK_DAYS)->toDateString();
        $total = 0;

        foreach (self::MUST_REACH_THE_BOOKS as $source => $class) {
            $model = new $class;

            $total += DB::table($model->getTable())
                ->where('company_id', CompanyContext::id())
                ->whereNull('deleted_at')
                ->where('status', DocumentStatus::CONFIRMED)
                ->whereDate('trx_date', '>=', $since)
                ->whereNotExists(function ($query) use ($source, $model): void {
                    $query->selectRaw('1')
                        ->from('ledger_entries')
                        ->where('ledger_entries.source_type', $source)
                        ->whereColumn('ledger_entries.source_id', $model->getTable().'.id');
                })
                ->count();
        }

        return $total;
    }
}
