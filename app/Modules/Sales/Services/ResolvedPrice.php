<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

/**
 * একটা দর আর সেটা কোথা থেকে এল — [[SalesPrice]]-এর উত্তর।
 *
 * ⓘ `source` পর্দার জন্য: "গ্রাহকের দাম", "ধরনের দাম", "এলাকার দাম", "তালিকার দাম", নাহলে "পণ্যের দাম"।
 */
final class ResolvedPrice
{
    public function __construct(
        public readonly string $price,
        public readonly string $source,
        public readonly ?int $priceListId = null,
        public readonly ?string $listName = null,
    ) {}

    /** পর্দার লেখা — "গ্রাহকের দাম" ইত্যাদি। */
    public function label(): string
    {
        return __('sales::price_source.'.$this->source);
    }

    /** তালিকা থেকে এসেছে কি না — পণ্যের নিজের দাম হলে না। */
    public function fromList(): bool
    {
        return $this->source !== SalesPrice::STANDARD;
    }

    /** @return array{rate: string, source: string, label: string, list: ?string} */
    public function toArray(): array
    {
        return [
            'rate' => $this->price,
            'source' => $this->source,
            'label' => $this->label(),
            'list' => $this->listName,
        ];
    }
}
