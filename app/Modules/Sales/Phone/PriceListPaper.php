<?php

declare(strict_types=1);

namespace App\Modules\Sales\Phone;

use App\Core\Phone\PaperSheet;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\MasterData\Models\Location;
use App\Modules\MasterData\Models\PartyType;
use App\Modules\MasterData\Models\PriceList;
use App\Modules\Sales\Models\PriceListItem;
use App\Modules\Sales\Services\PriceBookService;
use App\Modules\Sales\Services\SalesPrice;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * দর তালিকা — ওয়েবের `/sales/price-lists?target=…`-এর চার রকম: গ্রাহক, ধরন, এলাকা আর সবার ([[SalesPrice::targetOf()]])।
 *
 * <p>ⓘ তারিখ আর শাখা নেই — তালিকা কোনো দিনের বা শাখার নয় (দেয়াল ডিলারের, মডেলের নিজের)। অবস্থা চালু/বন্ধ; না বাছলে সব,
 * ওয়েবের মতো। পাতায় প্রতিটা দর, কবে থেকে কবে পর্যন্ত ([[PriceBookService::items()]]-এর একই সারি)।
 */
abstract class PriceListPaper extends SalesPhonePaper
{
    private const ACTIVE = 'active';

    private const INACTIVE = 'inactive';

    /** কাগজের পাতায় এক পাতায় কত দর — বাকিটা `?page=` দিয়ে (আগে ৫০০-তে কেটে যেত, কোনো খবর ছাড়া) */
    public const ITEMS_PER_PAGE = 200;

    /** @var array<int, string> তালিকার id → "কার জন্য", [[prepare()]]-এ একবারে */
    private array $aims = [];

    /** কোন রকম — [[SalesPrice]]-এর চার লক্ষ্যের একটা */
    abstract protected function target(): string;

    public function key(): string
    {
        return 'sales_price_list_'.$this->target();
    }

    public function permission(): string
    {
        return 'sales.price_list.view';
    }

    public function webRoute(): array
    {
        return ['sales.price_book.index', ['target' => $this->target()]];
    }

    public function query(): Builder
    {
        // ⓘ প্রতিটা তালিকার দরের সংখ্যা এক কোয়েরিতে — সারিপ্রতি নয় ([[PriceBookService::itemCounts()]]-এর একই গোনা)
        $query = PriceList::query()->select('mdm_price_lists.*')->addSelect(['items_count' => PriceListItem::query()
            ->selectRaw('COUNT(*)')->whereColumn('sal_price_list_items.price_list_id', 'mdm_price_lists.id')]);

        // ⓘ ওয়েবের তালিকার একই চার শর্ত ([[PriceBookService::lists()]])
        match ($this->target()) {
            SalesPrice::CUSTOMER => $query->whereNotNull('mdm_price_lists.customer_id'),
            SalesPrice::TIER => $query->whereNull('mdm_price_lists.customer_id')->whereNotNull('mdm_price_lists.party_type_id'),
            SalesPrice::TERRITORY => $query->whereNull('mdm_price_lists.customer_id')->whereNull('mdm_price_lists.party_type_id')
                ->whereNotNull('mdm_price_lists.location_id'),
            default => $query->whereNull('mdm_price_lists.customer_id')->whereNull('mdm_price_lists.party_type_id')
                ->whereNull('mdm_price_lists.location_id'),
        };

        return $query;
    }

    public function dateColumn(): ?string
    {
        return null;
    }

    public function branchColumn(): ?string
    {
        return null;
    }

    public function statuses(): array
    {
        return [self::ACTIVE => __('sales::price_book.active'), self::INACTIVE => __('sales::price_book.inactive')];
    }

    public function applyStatus(Builder $query, ?string $status): void
    {
        if ($status !== null) {
            $query->where('mdm_price_lists.is_active', $status === self::ACTIVE);
        }
    }

    public function search(Builder $query, string $term): void
    {
        $like = '%'.addcslashes($term, '%_\\').'%';
        $query->where(fn (Builder $q) => $q->where('mdm_price_lists.code', 'like', $like)
            ->orWhere('mdm_price_lists.name_en', 'like', $like)->orWhere('mdm_price_lists.name_bn', 'like', $like));
    }

    public function numberOf(Model $paper): string
    {
        return (string) $paper->getAttribute('code');
    }

    public function statusOf(Model $paper): string
    {
        return $paper->getAttribute('is_active') ? self::ACTIVE : self::INACTIVE;
    }

    public function row(Model $paper, User $user): array
    {
        /** @var PriceList $paper */
        return [
            'party' => self::nameOf($paper),
            'note' => trim(implode(' · ', array_filter([
                $this->aimOf($paper),
                __('sales::price_book.rows').': '.(int) $paper->getAttribute('items_count'),
            ]))),
        ];
    }

    public function prepare(Collection $papers): void
    {
        $column = match ($this->target()) {
            SalesPrice::CUSTOMER => 'customer_id',
            SalesPrice::TIER => 'party_type_id',
            SalesPrice::TERRITORY => 'location_id',
            default => null,
        };
        $ids = $column === null ? [] : $papers->pluck($column)->filter()->unique()->values()->all();

        // ⓘ রকমপ্রতি এক কোয়েরি — আগে সারিপ্রতি একটা (a5-এর পর্যালোচনা, ১৭ অক্টোবর ২০২৬); গ্রাহকের দেয়াল আগের মতোই দেখার শাখা
        $names = match ($this->target()) {
            SalesPrice::CUSTOMER => Customer::query()->inViewedBranch()->whereIn('id', $ids)->get()->mapWithKeys(fn (Customer $c) => [$c->id => self::nameOf($c)]),
            SalesPrice::TIER => PartyType::query()->whereIn('id', $ids)->get()->mapWithKeys(fn (PartyType $t) => [$t->id => self::nameOf($t)]),
            SalesPrice::TERRITORY => Location::query()->whereIn('id', $ids)->get()->mapWithKeys(fn (Location $l) => [$l->id => $l->path()]),
            default => collect(),
        };

        $this->aims = [];

        foreach ($papers as $list) {
            $this->aims[(int) $list->getKey()] = $column === null
                ? __('sales::price_book.everyone')
                : (string) ($names[$list->getAttribute($column)] ?? '—');
        }
    }

    public function detail(Model $paper, User $user): array
    {
        /** @var PriceList $paper */
        // ⓘ পাতা ভাগ, কাটা নয় — `rows_count` আর `rows_next_page` ফোনকে বলে আরও আছে কি না
        $items = PriceListItem::query()->where('price_list_id', $paper->id)->with(['product.unit', 'unit'])
            ->orderBy('product_id')->orderByDesc('valid_from')->orderBy('id')->paginate(self::ITEMS_PER_PAGE);

        return [
            'rows_count' => $items->total(),
            'rows_next_page' => $items->hasMorePages() ? $items->currentPage() + 1 : null,
        ] + PaperSheet::make(
            facts: [
                __('sales::price_book.code') => $paper->code,
                __('sales::price_book.for') => $this->aimOf($paper),
                __('sales::price_book.is_active') => $paper->is_active ? __('sales::price_book.active') : __('sales::price_book.inactive'),
            ],
            columns: [
                'product' => [__('sales::price_book.product')],
                'unit' => [__('sales::price_book.unit')],
                'price' => [__('sales::price_book.price'), true],
                'valid' => [__('sales::price_book.valid_from')],
            ],
            rows: $items->map(fn (PriceListItem $i) => [
                'product' => self::productOf($i),
                'unit' => self::nameOf($i->unit ?? $i->product?->unit),
                'price' => self::money($i->price),
                'valid' => trim((self::day($i->valid_from) ?? '').' – '.(self::day($i->valid_to) ?? __('sales::price_book.open_ended')), ' –'),
            ])->values()->all(),
        );
    }

    /** কার জন্য — ওয়েবের তালিকার "কার জন্য" ঘরের একই উত্তর ([[PriceBookController]]-এর aims), [[prepare()]] থেকে */
    private function aimOf(PriceList $list): string
    {
        if (! array_key_exists((int) $list->getKey(), $this->aims)) {
            $this->prepare(new Collection([$list]));
        }

        return $this->aims[(int) $list->getKey()];
    }
}
