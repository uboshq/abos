<?php

declare(strict_types=1);

namespace App\Modules\Purchase\Http\Controllers;

use App\Core\Bulk\BulkOutcome;
use App\Core\Bulk\BulkRun;
use App\Core\Engines\Approval\HeldForApproval;
use App\Core\Support\DocumentStatus;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Purchase\Models\PurchaseBill;
use App\Modules\Purchase\Models\PurchaseOrder;
use App\Modules\Purchase\Services\PurchaseBillService;
use App\Modules\Purchase\Services\PurchaseOrderService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * ⭐ ক্রয়ের তালিকা থেকে একসাথে কাজ — বিল আর ক্রয় আদেশ (মালিক, ১৯ নভেম্বর ২০২৬, নমুনা Q2W6mM3S v3; [[BulkRun]])।
 *
 * ⓘ তিন কাজ, প্রতিটা কাগজের নিজের বোতামের একই দরজা আর একই চাবি:
 *   · submit  — "নিশ্চিত করুন" ([[PurchaseBillService::confirm()]] · [[PurchaseOrderService::confirm()]]): ছক না থাকলে পাকা,
 *               থাকলে অনুমোদনে যায় ([[HeldForApproval]] — সেটাও "পাঠানো হলো")। ⛔ সরবরাহকারীর বাকির দেয়াল আর আনলোডিং খরচ
 *               সেবার ভেতরেই — আটকালে সেই সারি বাদ, কারণসহ; একসাথে পাঠানো কোনো নিয়ম এড়ায় না।
 *   · cancel  — কেবল খসড়া, কারণসহ ([[PurchaseBillService::cancel()]] · [[PurchaseOrderService::cancel()]])।
 *   · approve — কেবল ক্রয় আদেশ ([[PAPERS]]-এ কেন বিল নয়), অপেক্ষমাণ অনুমোদন ([[BulkRun::whyNotApprove()]]); শেষ সইয়ে কাগজ নিজেই পাকা হয়
 *               ([[ConfirmThePurchasePaperOnTheLastSignature]])।
 * ⓘ সারি তালিকার একই প্রশ্নে তোলা — কোম্পানি আর শাখার দেয়াল ([[ScopedToUserBranch]]); যা ওঠে না তা "পাওয়া গেল না"।
 * ⓘ ছাপা আর Excel আলাদা রুট নয় — তালিকার নিজের রপ্তানি ([[x-ui.bulk-form]])।
 */
class PurchaseBulkController extends Controller
{
    /**
     * কাগজ → মডেল, তালিকার রুট, আর প্রতিটা কাজের চাবি — একক দরজার `middleware()`-এর হুবহু
     * ([[PurchaseBillController]]: confirm = purchase.bill.create; [[PurchaseOrderController]]: confirm = purchase.order.update)।
     *
     * ⓘ `approve` — একসাথে অনুমোদন খোলা কি না। ⛔ বিলে নয়: বিল টাকার কাজ (module.php `moves_money` = payment, bill), আর টাকার
     *   কাগজ একটা একটা অনুমোদন হয় ([[BulkApproval::movesMoney()]]) — বোতাম থাকলে প্রতিটা সারি কেবল "টাকা নড়ে" বলে বাদ পড়ত।
     *
     * @var array<string, array{model: class-string<Model>, list: string, submit: string, cancel: string, approve: bool}>
     */
    public const PAPERS = [
        'bill' => ['model' => PurchaseBill::class, 'list' => 'purchase.bill.index',
            'submit' => 'purchase.bill.create', 'cancel' => 'purchase.bill.cancel', 'approve' => false],
        'order' => ['model' => PurchaseOrder::class, 'list' => 'purchase.order.index',
            'submit' => 'purchase.order.update', 'cancel' => 'purchase.order.cancel', 'approve' => true],
    ];

    public function __construct(
        private readonly PurchaseBillService $bills,
        private readonly PurchaseOrderService $orders,
    ) {}

    public function run(Request $request, string $paper, string $action): RedirectResponse
    {
        $spec = self::PAPERS[$paper] ?? abort(404);

        // ⛔ তালিকা দেখার চাবি আগে ([[PurchaseBillPolicy::viewAny()]] · [[PurchaseOrderPolicy::viewAny()]]), তারপর কাজের চাবি
        $this->authorize('viewAny', $spec['model']);

        /** @var User $user */
        $user = $request->user();
        $key = match ($action) {
            BulkRun::SUBMIT => $spec['submit'],
            BulkRun::CANCEL => $spec['cancel'],
            BulkRun::APPROVE => $spec['approve'] ? 'approval.decide' : abort(404),
            default => abort(404),
        };
        abort_unless($user->can($key), 403);

        $ids = BulkRun::ids($request);
        $back = BulkRun::back($request, route($spec['list']));

        // ⓘ তালিকার একই প্রশ্ন — মডেলের নিজের দেয়াল (কোম্পানি, শাখা); অন্য শাখার id ওঠে না
        $found = $spec['model']::query()->whereKey($ids)->orderBy('id')->get();
        $label = fn (Model $row): string => (string) $row->getAttribute('document_no');

        if ($action === BulkRun::CANCEL) {
            $reason = trim((string) $request->input('reason', ''));

            // ⛔ কারণ ছাড়া বাতিল নয় — একক দরজার একই নিয়ম (required|string|max:500)
            if ($reason === '' || mb_strlen($reason) > 500) {
                return BulkRun::each($ids, $found, $label, fn () => 'no_reason', fn () => null)->redirect($back, $action);
            }

            return BulkRun::each($ids, $found, $label,
                fn (Model $row): ?string => $row->getAttribute('status') !== DocumentStatus::DRAFT ? 'not_draft' : null,
                fn (Model $row) => $row instanceof PurchaseBill
                    ? $this->bills->cancel($row, $reason)
                    : $this->orders->cancel($row, $reason),
            )->redirect($back, $action);
        }

        if ($action === BulkRun::APPROVE) {
            return BulkRun::each($ids, $found, $label,
                fn (Model $row): ?string => BulkRun::whyNotApprove($row, $user),
                fn (Model $row) => BulkRun::approve($row, $user),
            )->redirect($back, $action);
        }

        return BulkRun::each($ids, $found, $label,
            fn (Model $row): ?string => match (true) {
                $row->getAttribute('status') !== DocumentStatus::DRAFT => 'not_draft',
                // ⓘ আগেই অনুমোদনের অপেক্ষায় — দুবার পাঠানো নয়
                BulkRun::approvalFor($row) !== null => 'already_waiting',
                default => null,
            },
            fn (Model $row) => $this->submit($row),
        )->redirect($back, $action);
    }

    /**
     * ⭐ একক "নিশ্চিত করুন"-এর দরজা। ⓘ ছক বসানো থাকলে সেবা [[HeldForApproval]] ছোঁড়ে আর অনুরোধ বসায় — সেটা "পাঠানো হলো";
     * ⛔ কিন্তু প্রত্যাখ্যাত কাগজ (অপেক্ষমাণ অনুরোধ নেই) বাদ, সেবার নিজের বার্তায়।
     */
    private function submit(Model $row): void
    {
        try {
            $row instanceof PurchaseBill ? $this->bills->confirm($row) : $this->orders->confirm($row);
        } catch (HeldForApproval $held) {
            if (BulkRun::approvalFor($row) === null) {
                throw $held;
            }
        }
    }

    /**
     * ⓘ তালিকার বারের বোতাম — কেবল যে কাজের চাবি এই মানুষের আছে ([[x-ui.bulk-form]]); ⛔ তবু সার্ভার [[run()]]-এ আবার দেখে।
     *
     * @return list<array{action: string, url: string}>
     */
    public static function actionsFor(string $paper, ?User $user): array
    {
        $spec = self::PAPERS[$paper];

        if ($user === null) {
            return [];
        }

        $actions = [];

        foreach ([BulkRun::SUBMIT => $spec['submit'], BulkRun::CANCEL => $spec['cancel'], BulkRun::APPROVE => $spec['approve'] ? 'approval.decide' : null] as $action => $key) {
            if ($key !== null && $user->can($key)) {
                $actions[] = ['action' => $action, 'url' => $action === BulkRun::APPROVE
                    ? route('purchase.bulk.approve', ['paper' => $paper])
                    : route('purchase.bulk', ['paper' => $paper, 'action' => $action])];
            }
        }

        return $actions;
    }
}
