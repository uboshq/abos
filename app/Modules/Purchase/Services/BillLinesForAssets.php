<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Services;

use App\Core\Contracts\CapitalisesABillLine;
use App\Core\Support\DocumentStatus;
use App\Models\LedgerEntry;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Inventory\Services\StockAdjustmentService;
use App\Modules\Inventory\Services\StockService;
use App\Modules\MasterData\Models\ReasonCode;
use App\Modules\Purchase\Models\PurchaseBillLine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ ক্রয় বিলের সারি স্থায়ী সম্পদে — ক্রয়ের দিক থেকে ([[CapitalisesABillLine]]; স্থায়ী সম্পদ ধাপ ১, ১০ অক্টোবর ২০২৬)।
 *
 * ⓘ কেবল পাকা বিল। মালটা বিলের গুদাম থেকে মজুদের নিজের "বের করে দেওয়া" পথে বেরোয় ([[StockAdjustmentService::issue()]]),
 * কারণের খাত সম্পদের খাত — তাই মজুদের দাখিলাই হয় সম্পদ ডেবিট / মজুদ ক্রেডিট, মজুদের নিজের দামে (স্তর ধরে)। ⛔ বিক্রেতার
 * পাওনা, বিল, কিছুই নড়ে না — কেনাটা দুইবার খাতায় ওঠে না।
 * ⓘ কারণটা প্রতি সম্পদ-খাতে একটাই (`FA-<খাতের কোড>`), প্রথমবার বসে — ছয় মাস পরে মজুদের পাতায় "কেন বেরোল" পড়া যায়।
 */
final class BillLinesForAssets implements CapitalisesABillLine
{
    public function __construct(private readonly StockAdjustmentService $adjustments) {}

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

    public function capitalise(int $lineId, string $qty, int $assetAccountId, Carbon $on, string $narration): string
    {
        $line = $this->query()->whereKey($lineId)->first();
        $account = Account::query()->postable()->whereKey($assetAccountId)->first();

        if ($line === null || $account === null || $line->bill?->warehouse === null || $line->product === null) {
            throw ValidationException::withMessages(['purchase_bill_line_id' => __('accounts::asset.bill_line_missing')]);
        }

        $reason = ReasonCode::query()->firstOrCreate(
            ['code' => 'FA-'.$account->code],
            [
                'name_en' => 'Capitalised as a fixed asset ('.$account->code.')',
                'name_bn' => 'স্থায়ী সম্পদে তোলা ('.$account->code.')',
                'context' => ReasonCode::STOCK_ISSUE,
                'account_id' => $account->id,
                'returns_to_stock' => false,
                'is_active' => true,
            ],
        );

        $movement = $this->adjustments->issue(
            product: $line->product,
            warehouse: $line->bill->warehouse,
            qty: $qty,
            reason: $reason,
            date: $on,
            narration: $narration.' — '.$line->bill->document_no,
        );

        // ⓘ যত টাকার মাল সরল — মজুদের নিজের দাখিলার মজুদ-ক্রেডিট, স্তরের দামে
        $inventory = StandardChart::find(StandardChart::INVENTORY);

        return $movement === null || $inventory === null ? '0' : (string) bcadd((string) LedgerEntry::query()
            ->where('source_type', StockService::ADJUSTMENT)
            ->where('source_id', $movement->id)
            ->where('account_id', $inventory->id)
            ->sum('credit'), '0', 4);
    }

    /** @return Builder<PurchaseBillLine> */
    private function query()
    {
        return PurchaseBillLine::query()
            ->with(['bill.supplier', 'bill.warehouse', 'product'])
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
