<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Models\AuditFieldChange;
use App\Models\AuditTrail;
use App\Modules\Customer\Models\Customer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\PackConversion;
use App\Modules\MasterData\Models\Location;
use App\Modules\MasterData\Models\PartyType;
use App\Modules\MasterData\Models\PriceList;
use App\Modules\Sales\Models\PriceListItem;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * দর তালিকার খাতা — কার জন্য তালিকা, আর তার সারিতে পণ্য → দর (মালিক, ৫ অক্টোবর ২০২৬; কোনটা খাটবে তা [[SalesPrice]])।
 *
 * ⛔ এক তালিকা ঠিক একজনের জন্য: একজন গ্রাহক, এক ধরন, এক এলাকা, নাহলে সবার — দুইটা লক্ষ্য একসাথে নয়।
 * ⓘ দর বদলালে সারিটাই বদলায় আর অডিট-খাতায় আগের-পরের দর বসে ([[IsAudited]]); ইতিহাস ওখান থেকেই ([[history()]]),
 * [[SalePriceBook::history()]]-এর একই কারণে — আলাদা ইতিহাসের টেবিল মানে একই ঘটনার দুই খাতা।
 */
final class PriceBookService
{
    /** তালিকার লক্ষ্যের চারটা স্তর — পর্দার ট্যাব ও মেনুর সারি */
    public const TARGETS = [SalesPrice::CUSTOMER, SalesPrice::TIER, SalesPrice::TERRITORY, SalesPrice::ALL];

    public function __construct(private readonly PackConversion $packs) {}

    /**
     * এক স্তরের তালিকাগুলো — নাম বা কোডে খোঁজা যায়।
     *
     * @return LengthAwarePaginator<PriceList>
     */
    public function lists(string $target, ?string $q): LengthAwarePaginator
    {
        $query = PriceList::query();

        match ($target) {
            SalesPrice::CUSTOMER => $query->whereNotNull('customer_id'),
            SalesPrice::TIER => $query->whereNull('customer_id')->whereNotNull('party_type_id'),
            SalesPrice::TERRITORY => $query->whereNull('customer_id')->whereNull('party_type_id')->whereNotNull('location_id'),
            default => $query->whereNull('customer_id')->whereNull('party_type_id')->whereNull('location_id'),
        };

        return $query
            ->when(filled($q), function ($w) use ($q) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], trim((string) $q)).'%';
                $w->where(fn ($x) => $x->where('code', 'like', $like)->orWhere('name_en', 'like', $like)->orWhere('name_bn', 'like', $like));
            })
            ->orderBy('code')
            ->paginate(50)
            ->withQueryString();
    }

    /**
     * প্রতিটা তালিকায় কয়টা দর — এক কোয়েরিতে (MasterData-র তালিকা বিক্রয়ের সারি চেনে না, তাই সম্পর্ক নয়)।
     *
     * @param  iterable<PriceList>  $lists
     * @return array<int, int>
     */
    public function itemCounts(iterable $lists): array
    {
        $ids = collect($lists)->map(fn (PriceList $l) => (int) $l->id)->all();

        return $ids === [] ? [] : PriceListItem::query()
            ->whereIn('price_list_id', $ids)
            ->groupBy('price_list_id')
            ->selectRaw('price_list_id, COUNT(*) as n')
            ->pluck('n', 'price_list_id')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    /**
     * তালিকা বানানো বা বদলানো — লক্ষ্য ঠিক একটা।
     *
     * @param  array{code: string, name_en: string, name_bn?: ?string, target: string, target_id?: mixed, is_active?: bool}  $data
     */
    public function saveList(?PriceList $list, array $data): PriceList
    {
        $target = (string) $data['target'];

        if (! in_array($target, self::TARGETS, true)) {
            throw ValidationException::withMessages(['target' => __('sales::price_book.bad_target')]);
        }

        $aim = ['customer_id' => null, 'party_type_id' => null, 'location_id' => null];
        $id = is_numeric($data['target_id'] ?? null) ? (int) $data['target_id'] : null;

        // ⛔ লক্ষ্যের সারিটা এই কোম্পানির আর চালু — অন্য কোম্পানির গ্রাহক বা এলাকা নয় (মডেলের কোম্পানি-স্কোপ)
        $found = match ($target) {
            SalesPrice::CUSTOMER => $id !== null ? Customer::query()->find($id) : null,
            SalesPrice::TIER => $id !== null ? PartyType::query()->find($id) : null,
            SalesPrice::TERRITORY => $id !== null ? Location::query()->find($id) : null,
            default => true,
        };

        if ($found === null) {
            throw ValidationException::withMessages(['target_id' => __('sales::price_book.target_missing')]);
        }

        if ($target !== SalesPrice::ALL) {
            $aim[match ($target) {
                SalesPrice::CUSTOMER => 'customer_id',
                SalesPrice::TIER => 'party_type_id',
                default => 'location_id',
            }] = $id;
        }

        $code = strtoupper(trim((string) $data['code']));
        $taken = PriceList::query()->withTrashed()->where('code', $code)
            ->when($list !== null, fn ($q) => $q->whereKeyNot($list->id))->exists();

        if ($code === '' || $taken) {
            throw ValidationException::withMessages(['code' => __('sales::price_book.code_taken')]);
        }

        $fields = [
            'code' => $code,
            'name_en' => trim((string) $data['name_en']),
            'name_bn' => filled($data['name_bn'] ?? null) ? trim((string) $data['name_bn']) : null,
            'is_active' => (bool) ($data['is_active'] ?? true),
        ] + $aim;

        if ($list === null) {
            return PriceList::query()->create($fields + ['created_by' => auth()->id()]);
        }

        $list->update($fields);

        return $list->fresh();
    }

    /**
     * অনেক পণ্যের দর একবারে — গ্রিডের সারি (মালিক: Excel ছাড়া একসাথে বসানো)।
     *
     * ⓘ একই পণ্য-একক-শুরুর তারিখের সারি থাকলে সেটারই দর বদলায় (অডিটে আগে-পরে), নাহলে নতুন সারি। ফাঁকা দরের সারি
     * বাদ — গ্রিডে সব পণ্য থাকে, যার দর লেখা হয়নি তার কিছু হয় না। ⛔ একটাও ভুল থাকলে কিছুই লেখা হয় না।
     *
     * @param  list<array{product_id?: mixed, unit_id?: mixed, price?: mixed}>  $rows
     * @return int কয়টা সারি বসল বা বদলাল
     */
    public function saveItems(PriceList $list, array $rows, mixed $from, mixed $to = null): int
    {
        $from = $this->day($from, 'valid_from');
        $to = filled($to) ? $this->day($to, 'valid_to') : null;

        if ($to !== null && $to < $from) {
            throw ValidationException::withMessages(['valid_to' => __('sales::price_book.ends_before_start')]);
        }

        $clean = [];

        foreach ($rows as $i => $row) {
            $price = trim((string) ($row['price'] ?? ''));

            if ($price === '') {
                continue;
            }

            if (! is_numeric($price) || bccomp($price, '0', 4) < 0) {
                throw ValidationException::withMessages(["rows.{$i}.price" => __('sales::price_list.not_a_price')]);
            }

            $product = Product::query()->find((int) ($row['product_id'] ?? 0))
                ?? throw ValidationException::withMessages(["rows.{$i}.product_id" => __('sales::validation.unknown_product')]);

            $unitId = is_numeric($row['unit_id'] ?? null) ? (int) $row['unit_id'] : null;
            $unitId = $unitId === (int) $product->unit_id ? null : $unitId;

            if ($unitId !== null) {
                // ⛔ পণ্যের সিঁড়িতে নেই এমন একক নয় — নাহলে দরটা কখনো কোনো এককে নামত না
                $this->packs->factorFor($product, $unitId);
            }

            $clean[] = ['product_id' => (int) $product->id, 'unit_id' => $unitId, 'price' => bcadd($price, '0', 4)];
        }

        if ($clean === []) {
            throw ValidationException::withMessages(['rows' => __('sales::price_book.no_rows')]);
        }

        return DB::transaction(function () use ($list, $clean, $from, $to) {
            $locked = PriceList::query()->whereKey($list->id)->lockForUpdate()->firstOrFail();
            $count = 0;

            foreach ($clean as $row) {
                $item = PriceListItem::query()
                    ->where('price_list_id', $locked->id)
                    ->where('product_id', $row['product_id'])
                    ->when($row['unit_id'] === null, fn ($q) => $q->whereNull('unit_id'), fn ($q) => $q->where('unit_id', $row['unit_id']))
                    ->whereDate('valid_from', $from)
                    ->lockForUpdate()
                    ->first();

                if ($item === null) {
                    PriceListItem::query()->create($row + [
                        'price_list_id' => $locked->id,
                        'valid_from' => $from,
                        'valid_to' => $to,
                        'created_by' => auth()->id(),
                    ]);
                    $count++;

                    continue;
                }

                $item->fill(['price' => $row['price'], 'valid_to' => $to]);

                if ($item->isDirty()) {
                    $item->save();
                    $count++;
                }
            }

            return $count;
        });
    }

    /** একটা সারি তোলা — অডিটে থাকে, দরে আর খাটে না। */
    public function removeItem(PriceList $list, PriceListItem $item): void
    {
        abort_unless((int) $item->price_list_id === (int) $list->id, 404);

        $item->delete();
    }

    /**
     * তালিকার সারি — পণ্য ধরে, নতুন মেয়াদ আগে।
     *
     * @return LengthAwarePaginator<PriceListItem>
     */
    public function items(PriceList $list): LengthAwarePaginator
    {
        return PriceListItem::query()
            ->where('price_list_id', $list->id)
            ->with(['product.unit', 'unit'])
            ->orderBy('product_id')
            ->orderByDesc('valid_from')
            ->paginate(100)
            ->withQueryString();
    }

    /**
     * এই তালিকায় এই পণ্যের দরের ইতিহাস — অডিট-খাতা থেকে, নতুন আগে।
     *
     * @return Collection<int, object{when: mixed, user: ?string, field: string, old: ?string, new: ?string, from: ?string}>
     */
    public function history(PriceList $list, Product $product): Collection
    {
        $items = PriceListItem::query()->withTrashed()
            ->where('price_list_id', $list->id)->where('product_id', $product->id)
            ->get(['id', 'valid_from'])->keyBy('id');

        if ($items->isEmpty()) {
            return collect();
        }

        $trails = AuditTrail::query()
            ->where('auditable_type', PriceListItem::class)
            ->whereIn('auditable_id', $items->keys())
            ->select('id');

        return AuditFieldChange::query()
            ->whereIn('field', ['price', 'valid_to'])
            ->whereIn('audit_trail_id', $trails)
            ->with('trail.user')
            ->orderByDesc('id')
            ->get()
            ->map(fn (AuditFieldChange $c) => (object) [
                'when' => $c->trail?->created_at,
                'user' => $c->trail?->user?->name,
                'field' => (string) $c->field,
                'old' => $c->old_value,
                'new' => $c->new_value,
                'from' => $items->get((int) $c->trail?->auditable_id)?->valid_from?->format('d-m-Y'),
            ]);
    }

    private function day(mixed $value, string $field): string
    {
        try {
            return Carbon::parse((string) $value)->toDateString();
        } catch (\Throwable) {
            throw ValidationException::withMessages([$field => __('sales::price_book.bad_date')]);
        }
    }
}
