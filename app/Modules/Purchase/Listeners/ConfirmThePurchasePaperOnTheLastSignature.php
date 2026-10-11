<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Listeners;

use App\Core\Engines\Approval\SignedPaperFinisher;
use App\Core\Events\ApprovalDecided;
use App\Core\Support\DocumentStatus;
use App\Models\Approval;
use App\Models\User;
use App\Modules\Purchase\Models\Payment;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Models\PurchaseOrder;
use App\Modules\Purchase\Models\PurchaseReceipt;
use App\Modules\Purchase\Models\PurchaseReturn;
use App\Modules\Purchase\Services\PaymentService;
use App\Modules\Purchase\Services\PurchaseBillService;
use App\Modules\Purchase\Services\PurchaseOrderService;
use App\Modules\Purchase\Services\PurchaseReceiptService;
use App\Modules\Purchase\Services\PurchaseReturnService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * ⭐ ক্রয়ের কাগজের শেষ সই পড়লে কাগজ নিজেই পাকা — মালিক, ১১ অক্টোবর ২০২৬ (ক): *"Approval er pore … & others post korte hoy
 * tookhon khuje ber korle somoy lage"*।
 *
 * ⓘ লাইভে ADI-র ৮টা ক্রয়-বিলের প্রতিটা সইয়ের পরে হাতে নিশ্চিত হয়েছে (PBL-0005: সই ১৩:৩৫, নিশ্চিত ১৫:৪৮)।
 * ⭐ প্রতিটা কাগজ হাতের "নিশ্চিত"-এর একই দরজায় যায় (সেবার `confirm()`: সই যাচাই, তালা, মাল, খাতা) — দ্বিতীয় কোনো পথ নয়।
 * ⛔ আটকালে খসড়াই থাকে, লেখক আর সইকারী খবর পান ([[SignedPaperFinisher]])।
 * ⓘ চাহিদাপত্র (requisition) এখানে নেই — ওর "অনুমোদন" খাতায় কিছু বসায় না, সইটাই তার শেষ।
 */
final class ConfirmThePurchasePaperOnTheLastSignature
{
    /** কাগজ → [সেবা, দেখার পাতার রুট] */
    private const PAPERS = [
        PurchaseBill::class => [PurchaseBillService::class, 'purchase.bill.show'],
        Payment::class => [PaymentService::class, 'purchase.payment.show'],
        PurchaseReceipt::class => [PurchaseReceiptService::class, 'purchase.receipt.show'],
        PurchaseReturn::class => [PurchaseReturnService::class, 'purchase.return.show'],
        PurchaseOrder::class => [PurchaseOrderService::class, 'purchase.order.show'],
    ];

    public function __construct(private readonly SignedPaperFinisher $finisher) {}

    public function handle(ApprovalDecided $event): void
    {
        if (($event->payload['status'] ?? null) !== Approval::APPROVED) {
            return;
        }

        $type = (string) ($event->payload['approvable_type'] ?? '');
        $class = Relation::getMorphedModel($type) ?? $type;

        if (! isset(self::PAPERS[$class])) {
            return;
        }

        [$service, $route] = self::PAPERS[$class];

        // ⓘ সইদাতার শাখার দেয়াল ছাড়া — হেডারে অন্য শাখা বাছা মালিক শেষ সই দিলেও কাগজটা পাওয়া যায় (কোম্পানির দেয়াল থাকে)
        $paper = $class::query()->withoutGlobalScope('user-branch')->find((int) ($event->payload['approvable_id'] ?? 0));

        // ⓘ খসড়া থাকলেই — একই সই দুইবার এলে বা কেউ হাতে আগেই পাকা করলে কিছুই নয়
        if ($paper === null || $paper->getAttribute('status') !== DocumentStatus::DRAFT) {
            return;
        }

        $signer = $event->actorId === null ? null : User::query()->find($event->actorId);

        $this->finisher->finish(
            $paper,
            $signer,
            route($route, $paper),
            fn (Model $p) => app($service)->confirm($class::query()->withoutGlobalScope('user-branch')->findOrFail($p->getKey())),
        );
    }
}
