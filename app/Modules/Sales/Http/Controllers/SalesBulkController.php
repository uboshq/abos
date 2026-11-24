<?php

declare(strict_types=1);

namespace App\Modules\Sales\Http\Controllers;

use App\Core\Bulk\BulkOutcome;
use App\Core\Bulk\BulkRun;
use App\Core\Engines\Approval\HeldForApproval;
use App\Core\Support\DocumentStatus;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Services\DeliveryChallanService;
use App\Modules\Sales\Services\DirectSaleService;
use App\Modules\Sales\Services\SalesInvoiceService;
use App\Modules\Sales\Services\SalesOrderService;
use App\Modules\Sales\Services\SalesReturnService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * ⭐ বিক্রয়ের চার তালিকা থেকে একসাথে কাজ — আদেশ, চালান, বিল, ফেরত (তালিকার একসাথে কাজ, ধাপ ২; মালিক, ১৯ নভেম্বর ২০২৬,
 *   নমুনা Q2W6mM3S v3)। "অনুমোদনে পাঠান" (খসড়া → কাগজের নিজের "নিশ্চিত" দরজা), "খসড়া বাতিল" (কারণসহ), আর যে কাগজ
 *   অনুমোদনের ইঞ্জিন দিয়ে যায় তাতে "অনুমোদন দিন" ([[BulkRun::whyNotApprove()]])।
 *
 * ⓘ চাবি — একটা একটা কাগজের দরজার হুবহু (প্রতিটা কন্ট্রোলারের `middleware()`):
 *   · আদেশ: নিশ্চিত = `sales.order.update`, বাতিল = `sales.order.cancel` ([[SalesOrderController]])
 *   · চালান: নিশ্চিত = `sales.challan.create`, বাতিল = `sales.challan.cancel` ([[DeliveryChallanController]])
 *   · বিল: নিশ্চিত = `sales.invoice.create`, বাতিল = `sales.invoice.cancel` ([[SalesInvoiceController]])
 *   · ফেরত: নিশ্চিত = `sales.return.create`, বাতিল = `sales.return.cancel` ([[SalesReturnController]])
 *   · অনুমোদন = `approval.decide`; সাথে সবসময় তালিকা দেখার চাবি (`sales.<কাগজ>.view`)। ⛔ চাবি না থাকলে পুরো অনুরোধ ৪০৩।
 * ⓘ সারি তোলা তালিকার নিজের প্রশ্নে — মডেলের দেয়াল (কোম্পানি, দেখা শাখা, বিক্রয়কর্মীর ডিলার), আর চালান/বিলে কাউন্টারের খোলা
 *   খসড়া বাদ ([[DeliveryChallanController::listed()]], [[SalesInvoiceController::listed()]]); যা ওঠে না তা "পাওয়া গেল না"।
 * ⛔ বাতিল কেবল খসড়ার — পাকা কাগজের বাতিল উল্টো খাতা লেখে, সেটা একটা একটা, কাগজ দেখে।
 */
class SalesBulkController extends Controller
{
    /** ⓘ রুটের `{paper}` — রুটে `whereIn` এই তালিকা দিয়েই */
    public const PAPERS = ['order', 'challan', 'invoice', 'return'];

    /** @var array<string, array{view: string, submit: string, cancel: string, list: string}> */
    private const KEYS = [
        'order' => ['view' => 'sales.order.view', 'submit' => 'sales.order.update', 'cancel' => 'sales.order.cancel', 'list' => 'sales.order.index'],
        'challan' => ['view' => 'sales.challan.view', 'submit' => 'sales.challan.create', 'cancel' => 'sales.challan.cancel', 'list' => 'sales.challan.index'],
        'invoice' => ['view' => 'sales.invoice.view', 'submit' => 'sales.invoice.create', 'cancel' => 'sales.invoice.cancel', 'list' => 'sales.invoice.index'],
        'return' => ['view' => 'sales.return.view', 'submit' => 'sales.return.create', 'cancel' => 'sales.return.cancel', 'list' => 'sales.return.index'],
    ];

    /** ⓘ এই কাজের চাবি — তালিকা তার বোতাম দেখায় কেবল এটা থাকলে ([[actionsFor()]]) */
    public static function keyFor(string $paper, string $action): string
    {
        return $action === BulkRun::APPROVE ? 'approval.decide' : self::KEYS[$paper][$action];
    }

    /**
     * ⭐ তালিকার বারের বোতাম — যে কাজের চাবি এই মানুষের আছে কেবল সেগুলো ([[x-ui.bulk-form]])।
     *
     * @return list<array{action: string, url: string}>
     */
    public static function actionsFor(string $paper, ?User $user): array
    {
        if ($user === null || ! $user->can(self::KEYS[$paper]['view'])) {
            return [];
        }

        $actions = [];

        foreach ([BulkRun::SUBMIT, BulkRun::CANCEL, BulkRun::APPROVE] as $action) {
            if ($user->can(self::keyFor($paper, $action))) {
                $actions[] = ['action' => $action, 'url' => $action === BulkRun::APPROVE
                    ? route('sales.bulk.approve', ['paper' => $paper])
                    : route('sales.bulk', ['paper' => $paper, 'action' => $action])];
            }
        }

        return $actions;
    }

    public function run(Request $request, string $paper, string $action): RedirectResponse
    {
        abort_unless(isset(self::KEYS[$paper]) && in_array($action, [BulkRun::SUBMIT, BulkRun::CANCEL, BulkRun::APPROVE], true), 404);

        // ⛔ তালিকা দেখার চাবি আর একটা একটা দরজার চাবি — দুটোই; না থাকলে কিছুই নয় (৪০৩)
        $this->authorize(self::KEYS[$paper]['view']);
        $this->authorize(self::keyFor($paper, $action));

        $ids = BulkRun::ids($request);
        /** @var User $user */
        $user = $request->user();
        $back = BulkRun::back($request, route(self::KEYS[$paper]['list']));
        $query = $this->scoped($paper, $request);
        $found = $query->whereKey($ids)->orderBy($query->getModel()->qualifyColumn('id'))->get();
        $label = fn (Model $p): string => (string) $p->getAttribute('document_no');

        if ($action === BulkRun::CANCEL) {
            $reason = trim((string) ($request->validate(['reason' => ['nullable', 'string', 'max:500']])['reason'] ?? ''));

            // ⛔ কারণ ছাড়া বাতিল নয় — একটা একটা বাতিলের একই নিয়ম (`reason` required)
            if ($reason === '') {
                return BulkRun::each($ids, $found, $label, fn () => 'no_reason', fn () => null)->redirect($back, $action);
            }

            return BulkRun::each($ids, $found, $label,
                fn (Model $p): ?string => $p->getAttribute('status') !== DocumentStatus::DRAFT ? 'not_draft' : null,
                fn (Model $p) => $this->cancel($paper, $p, $reason),
            )->redirect($back, $action);
        }

        if ($action === BulkRun::APPROVE) {
            return BulkRun::each($ids, $found, $label,
                fn (Model $p): ?string => BulkRun::whyNotApprove($p, $user),
                fn (Model $p) => BulkRun::approve($p, $user),
            )->redirect($back, $action);
        }

        return $this->submitAll($ids, $found, $label, $paper)->redirect($back, $action);
    }

    /**
     * ⭐ "অনুমোদনে পাঠান" — কাগজের নিজের "নিশ্চিত" বোতামের দরজা: অনুমোদন না লাগলে পাকা, লাগলে অনুমোদনে।
     *
     * ⓘ অনুমোদনে যাওয়া দরজাটা "ধরা পড়ল" ব্যতিক্রমে জানায় ([[HeldForApproval]]) — একটা একটা পর্দায় সেটা বার্তা; এখানে সেটা "হলো",
     *   যদি সত্যিই একটা অপেক্ষমাণ অনুমোদন বসে থাকে। ⛔ না থাকলে (আগে "না" বলা, অনুরোধ লেখা হয়নি) বার্তাটাই বাদের কারণ।
     *
     * @param  list<int>  $ids
     * @param  iterable<Model>  $found
     */
    private function submitAll(array $ids, iterable $found, \Closure $label, string $paper): BulkOutcome
    {
        return BulkRun::each($ids, $found, $label,
            fn (Model $p): ?string => match (true) {
                $p->getAttribute('status') !== DocumentStatus::DRAFT => 'not_draft',
                // ⓘ আগেই অনুমোদনের অপেক্ষায় — দ্বিতীয়বার পাঠানো নয়
                BulkRun::approvalFor($p) !== null => 'already_waiting',
                default => null,
            },
            function (Model $p) use ($paper): void {
                try {
                    $this->submit($paper, $p);
                } catch (HeldForApproval $held) {
                    if (BulkRun::approvalFor($p) === null) {
                        throw $held;
                    }
                }
            },
        );
    }

    /** ⓘ একটা একটা "নিশ্চিত"-এর হুবহু সেবা */
    private function submit(string $paper, Model $p): void
    {
        match ($paper) {
            'order' => app(SalesOrderService::class)->confirm($p),
            'challan' => app(DeliveryChallanService::class)->confirm($p),
            // ⓘ কাউন্টারে আটকে থাকা বিক্রি — বিলের "নিশ্চিত" বোতামের একই শাখা ([[SalesInvoiceController::confirm()]])
            'invoice' => $p instanceof SalesInvoice && $p->waitsAtTheCounter()
                ? app(DirectSaleService::class)->finishHeld($p)
                : app(SalesInvoiceService::class)->confirm($p),
            'return' => app(SalesReturnService::class)->confirm($p),
        };
    }

    /** ⓘ একটা একটা "বাতিল"-এর হুবহু সেবা, কারণসহ */
    private function cancel(string $paper, Model $p, string $reason): void
    {
        match ($paper) {
            'order' => app(SalesOrderService::class)->cancel($p, $reason),
            'challan' => app(DeliveryChallanService::class)->cancel($p, $reason),
            'invoice' => app(SalesInvoiceService::class)->cancel($p, $reason),
            'return' => app(SalesReturnService::class)->cancel($p, $reason),
        };
    }

    /**
     * ⭐ তালিকার নিজের প্রশ্ন — মডেলের দেয়াল (কোম্পানি, দেখা শাখা, বিক্রয়কর্মীর ডিলার) সহ।
     *
     * @return Builder<Model>
     */
    private function scoped(string $paper, Request $request): Builder
    {
        // ⓘ তালিকার খোঁজা আর গ্রাহকের ছাঁকনি এখানে নয় — বাছা id-ই ছাঁকনি; কেবল দেয়াল আর "কাউন্টারের খোলা খসড়া বাদ"
        $plain = Request::create('/', 'GET');
        $plain->setUserResolver($request->getUserResolver());

        return match ($paper) {
            'order' => SalesOrder::query(),
            'challan' => DeliveryChallanController::listed($plain),
            'invoice' => SalesInvoiceController::listed($plain),
            'return' => SalesReturn::query(),
        };
    }
}
