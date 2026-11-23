<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Core\Contracts\CreditWall;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\Support\CreditPaper;
use Illuminate\Database\Eloquent\Model;

/**
 * ⭐ স্টক ছাড়া বিক্রয় ভাউচারের (SV) বাকির দেয়াল — বিক্রয়ের বিলের হুবহু দেয়াল ([[CreditExposure]]; নকশা v5, ২৩ নভেম্বর ২০২৬)।
 *
 * ⓘ বাইরে একবার, লেনদেনের ভিতরে গ্রাহকের সারিতে তালা দিয়ে আবার — বিলের [[SalesInvoiceService]] যেভাবে করে।
 * ⛔ সীমা পরম, কোনো চাবিতে পার হয় না (মালিক, ২৬ সেপ্টেম্বর ২০২৬)।
 */
final class SalesCreditWall implements CreditWall
{
    public function __construct(private readonly CreditExposure $credit) {}

    public function assertRoom(int $customerId, string $adding, Model $paper, bool $locked = false): void
    {
        $customer = Customer::query()->find($customerId);

        if ($customer === null) {
            return;
        }

        if ($locked) {
            $this->credit->assertRoomLocked(customer: $customer, adding: $adding, paper: CreditPaper::of('invoice', $paper));

            return;
        }

        $this->credit->assertRoom(customer: $customer, adding: $adding, paper: CreditPaper::of('invoice', $paper));
    }
}
