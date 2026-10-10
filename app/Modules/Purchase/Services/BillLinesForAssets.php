<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Services;

use App\Core\Contracts\CapitalisesABillLine;
use App\Core\Engines\Posting\PostingEngine;
use App\Core\Support\DocumentStatus;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Services\CostLayerService;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Models\PurchaseBillLine;
use App\Modules\Purchase\Models\PurchaseReceipt;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ ক্রয় বিলের সারি স্থায়ী সম্পদে — ক্রয়ের দিক থেকে ([[CapitalisesABillLine]]; স্থায়ী সম্পদ ধাপ ১, ১০ অক্টোবর ২০২৬)।
 *
 * ⓘ কেবল পাকা বিল। তিন ধাপ, ক্রয় ফেরতের হুবহু নিয়মে ([[PurchaseReturnService::takeCostFromLayers()]]):
 *   ১. মালটা বিলের গুদামের তাক থেকে বেরোয় (মজুদের চলাচল, উৎস `asset_capitalise` আর সম্পদের আইডি);
 *   ২. দাম আসে **ঐ বিলেরই** স্তর থেকে — না কুলালে FIFO ([[CostLayerService::issueFromSource()]])। ⛔ সাধারণ FIFO হলে
 *      ১০০ টাকায় কেনা ফ্রিজ তাকের পুরনো দামি মালের দরে সম্পদ হত — IAS 16 বলে দাম তার নিজের কেনা দাম;
 *   ৩. দাখিলা: সম্পদ ডেবিট / মজুদ ক্রেডিট, ঠিক ঐ দামে।
 * ⛔ বিক্রেতার পাওনা, বিল, কিছুই নড়ে না — কেনাটা বিলের দিনই খাতায় উঠেছে, দুইবার নয়।
 */
final class BillLinesForAssets implements CapitalisesABillLine
{
    public const SOURCE = 'asset_capitalise';

    public function __construct(
        private readonly StockService $stock,
        private readonly CostLayerService $costs,
        private readonly PostingEngine $posting,
    ) {}

    public function lines(?string $term = null, int $limit = 50): array
    {
        $term = trim((string) $term);

        return $this->query()
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w
                ->whereHas('bill', fn ($b) => $b->where('document_no', 'like', "%{$term}%")
                    ->orWhere('supplier_bill_no', 'like', "%{$term}%"))
                ->orWhereHas('product', fn ($p) => $p->where('name_en', 'like', "%{$term}%")
                    ->orWhere('name_bn', 'like', "%{$term}%")->orWhere('code', 'like', "%{$term}%"))))
            ->orderByDesc('purchase_bill_id')->orderBy('id')
            ->limit($limit)
            ->get()
            ->map(fn (PurchaseBillLine $line) => $this->shape($line))
            ->all();
    }

    public function line(int $lineId): ?array
    {
        $line = $this->query()->whereKey($lineId)->first();

        return $line === null ? null : $this->shape($line);
    }

    public function capitalise(int $lineId, string $qty, int $assetAccountId, int $assetId, string $documentNo, Carbon $on, string $narration): string
    {
        $line = $this->query()->whereKey($lineId)->first();
        $account = Account::query()->postable()->whereKey($assetAccountId)->first();
        $inventory = StandardChart::find(StandardChart::INVENTORY);
        $warehouse = $line?->bill?->warehouse;

        if ($line === null || $account === null || $inventory === null || $warehouse === null || $line->product === null) {
            throw ValidationException::withMessages(['purchase_bill_line_id' => __('accounts::asset.bill_line_missing')]);
        }

        // ⛔ তাকে যা নেই তা সম্পদ হয় না — বিক্রি হয়ে গেলে তোলার কিছু নেই
        if (bccomp($qty, $this->stock->floorQty($line->product, $warehouse), 4) > 0) {
            throw ValidationException::withMessages(['capitalised_qty' => __('accounts::asset.bill_stock_short')]);
        }

        $this->stock->move(
            product: $line->product,
            warehouse: $warehouse,
            sourceType: self::SOURCE,
            sourceId: $assetId,
            floor: bcmul($qty, '-1', 4),
            date: $on,
            documentNo: $documentNo,
            narration: $narration,
        );

        $taken = $this->costs->issueFromSource(
            product: $line->product,
            qty: $qty,
            fromSourceType: $line->purchase_receipt_line_id !== null ? PurchaseReceipt::STOCK_SOURCE : PurchaseBill::STOCK_SOURCE,
            fromSourceId: $line->purchase_receipt_line_id !== null ? (int) ($line->receiptLine?->purchase_receipt_id ?? 0) : (int) $line->purchase_bill_id,
            sourceType: self::SOURCE,
            sourceId: $assetId,
            documentNo: $documentNo,
            date: $on,
        );

        $cost = bcadd((string) $taken['cost'], '0', 4);

        if (bccomp($cost, '0', 4) > 0) {
            $this->posting->post(
                sourceType: self::SOURCE,
                sourceId: $assetId,
                trxDate: $on,
                lines: [
                    ['account_id' => (int) $account->id, 'debit' => $cost, 'narration' => $narration],
                    ['account_id' => (int) $inventory->id, 'credit' => $cost, 'narration' => $narration],
                ],
                documentNo: $documentNo,
                branchId: $line->bill->branch_id === null ? null : (int) $line->bill->branch_id,
            );
        }

        return $cost;
    }

    /** @return Builder<PurchaseBillLine> */
    private function query()
    {
        return PurchaseBillLine::query()
            ->with(['bill.supplier', 'bill.warehouse', 'product', 'receiptLine'])
            ->whereHas('bill', fn ($b) => $b->whereIn('status', DocumentStatus::POSTED))
            ->where('qty', '>', 0);
    }

    /** @return array{id: int, bill_id: int, bill_no: string, date: string, supplier_id: ?int, supplier: string, product: string, qty: string, unit_cost: string, branch_id: ?int} */
    private function shape(PurchaseBillLine $line): array
    {
        $qty = (string) $line->qty;

        return [
            'id' => (int) $line->id,
            'bill_id' => (int) $line->purchase_bill_id,
            'bill_no' => (string) $line->bill?->document_no,
            'date' => (string) $line->bill?->trx_date?->toDateString(),
            'supplier_id' => $line->bill?->supplier_id === null ? null : (int) $line->bill->supplier_id,
            'supplier' => (string) ($line->bill?->supplier?->name() ?? ''),
            'product' => (string) ($line->product?->name() ?? ''),
            'qty' => $qty,
            'unit_cost' => bccomp($qty, '0', 4) > 0 ? bcdiv((string) $line->amount, $qty, 4) : '0',
            'branch_id' => $line->bill?->branch_id === null ? null : (int) $line->bill->branch_id,
        ];
    }
}
