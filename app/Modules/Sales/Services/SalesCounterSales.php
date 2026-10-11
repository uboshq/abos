<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Core\Contracts\CounterSales;
use App\Models\Approval;
use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\Metrics\SalesMetrics;
use App\Modules\Sales\Models\Collection;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesReturn;
use Illuminate\Database\Eloquent\Model;

/**
 * [[CounterSales]]-এর বিক্রয়ের বাস্তবায়ন — কাউন্টার যা চায়, বিক্রয়ের আগের সেবাগুলো ঠিক তাই করে
 * (পিওএস ধাপ ২, ১১ অক্টোবর ২০২৬)।
 *
 * ⛔ এখানে কোনো যুক্তি নেই, কেবল পৌঁছে দেওয়া — নিয়ম যেখানে ছিল সেখানেই থাকে, তাই কাউন্টার আর অফিসের
 * পর্দা কখনো দুই উত্তর দেয় না।
 */
final class SalesCounterSales implements CounterSales
{
    public function __construct(
        private readonly SalesInvoiceService $invoices,
        private readonly CollectionService $collections,
        private readonly SalesReturnService $returns,
        private readonly CounterApproval $counter,
        private readonly CreditExposure $credit,
    ) {}

    public function draftInvoice(array $data, array $lines): SalesInvoice
    {
        return $this->invoices->create($data, $lines);
    }

    public function redraftInvoice(Model $invoice, array $data, array $lines): SalesInvoice
    {
        assert($invoice instanceof SalesInvoice);

        return $this->invoices->update($invoice, $data, $lines);
    }

    public function assertDiscountSigned(Model $invoice): void
    {
        assert($invoice instanceof SalesInvoice);

        $this->invoices->assertDiscountApproved($invoice);
    }

    public function confirmInvoice(Model $invoice, string $payingNow = '0'): SalesInvoice
    {
        assert($invoice instanceof SalesInvoice);

        return $this->invoices->confirm($invoice, $payingNow);
    }

    public function lockCustomer(Model|int $customer): Customer
    {
        assert(is_int($customer) || $customer instanceof Customer);

        return $this->credit->lockCustomer($customer);
    }

    public function draftCollection(array $data, array $lines): Collection
    {
        return $this->collections->create($data, $lines);
    }

    public function confirmCollection(Model $collection): Collection
    {
        assert($collection instanceof Collection);

        return $this->collections->confirm($collection);
    }

    public function draftReturn(array $data, array $lines): SalesReturn
    {
        return $this->returns->create($data, $lines);
    }

    public function confirmReturn(Model $return): SalesReturn
    {
        assert($return instanceof SalesReturn);

        return $this->returns->confirm($return);
    }

    public function pendingSignature(Model $document, string $action): ?Approval
    {
        return $this->counter->pending($document, $action);
    }

    public function signAtCounter(Model $approval, string $email, string $password, ?string $remarks = null): User
    {
        assert($approval instanceof Approval);

        return $this->counter->decide($approval, $email, $password, $remarks);
    }

    public function salesTodayAtMyCounter(): string
    {
        return SalesMetrics::salesTodayAtMyCounter()->value();
    }
}
