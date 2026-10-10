<?php

declare(strict_types=1);

namespace App\Modules\Sales\Services;

use App\Core\Engines\Approval\HeldForApproval;
use App\Core\Engines\Audit\AuditEngine;
use App\Core\Services\NotificationService;
use App\Core\Services\PermissionSyncer;
use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\AuditTrail;
use App\Models\User;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\VoucherService;
use App\Modules\Sales\Models\DeliveryChallan;
use App\Modules\Sales\Models\SalesInvoice;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * ⭐ শেষ সইয়ের পরে কাউন্টারের আটকে থাকা বিক্রি নিজে শেষ — মালিকের সিদ্ধান্ত ১,
 * ২৭ সেপ্টেম্বর ২০২৬: *"সব সহ শেষ হলে নিজে থেকেই পোস্ট হবে"*।
 *
 * ── ⓘ কী ঘটত ─────────────────────────────────────────────────────────────
 * কাউন্টারে সই লাগলে গোটা বিক্রি খসড়া থাকে — চালান, বিল, জমা
 * ([[DirectSaleService::hold()]])। ⛔ সব সই হয়ে গেলেও কিছু নড়ত না: কাউকে
 * বিলের পাতায় গিয়ে "নিশ্চিত" চাপতে হত। লাইভে INV-0005 ঠিক এই অবস্থায় —
 * চালানের সই আর জমার সই দুইটাই অনুমোদিত, অথচ বিল আর RCV-0005 খসড়া।
 *
 * ── ⭐ কাজটা নতুন নয়, হুবহু সেই বোতামের ─────────────────────────────────
 * শেষ করে [[DirectSaleService::finishHeld()]] — বোতামও ঐটাই ডাকে
 * ([[SalesInvoiceController::confirm()]])। ⛔ এখানে দ্বিতীয় কোনো "শেষ করার"
 * যুক্তি লেখা হয়নি: দুইটা থাকলে একদিন একটায় মাল বেরোত আর আরেকটায় না।
 *
 * ── ⚠️ কার নামে চলে — বিক্রি যিনি বানিয়েছেন ───────────────────────────
 * সইয়ের মুহূর্তে লগইন থাকেন **সইকারী**। ⛔ তাঁর নামে চালালে তিনটা ফাঁদ:
 *   ⓵ নগদের তালা ([[VoucherService::assertCashLandsInOwnTill()]]) সইকারীর বাক্স
 *     দেখত — কেরানির বাক্সের টাকা "আপনার বাক্স নয়" বলে আটকাত;
 *   ⓶ শাখা ও গুদামের সীমা সইকারীর — অন্য শাখার সইকারীর কাছে চালানটাই "নেই";
 *   ⓷ কাগজের `created_by`/`approved_by` গুলিয়ে যেত।
 * ⭐ তাই কাজটা চলে বানানেওয়ালার নামে — ঠিক যা তিনি নিজে বোতাম চাপলে হত — আর
 * শেষে লগইন ফেরত যায় `finally`-তে।
 *
 * ⚠️ তবু অডিট মিথ্যা বলে না: একটা আলাদা সারি লেখে *"স্বয়ংক্রিয় — শেষ সইয়ের
 * পর (সইকারী: …)"*, সইকারীর নামে ([[record()]])।
 *
 * ── ⛔ কখন শেষ হয় না ───────────────────────────────────────────────────
 * কোনো সই এখনো অপেক্ষায় বা প্রত্যাখ্যাত; কাউন্টারের রাখা খসড়া
 * (`counter_draft` — "খসড়া রাখুন", সইয়ে যায়নি); বানানেওয়ালা নিষ্ক্রিয় বা
 * কোম্পানির বাইরে; বা [[finishHeld()]] নিজেই থামায় (ধরুন বাকির দেয়াল)।
 * ⓘ প্রতিটায় বিলটা আগের মতো আটকে থাকে, কারণটা লগে যায়, আর হাতের বোতাম
 * কাজ করে।
 */
final class HeldCounterSaleFinisher
{
    public const FINISHED = 'finished';

    public const WAITING = 'waiting';

    public const REJECTED = 'rejected';

    public const NOT_HELD = 'not_held';

    public const MAKER_GONE = 'maker_gone';

    public const REFUSED = 'refused';

    /** ⓘ অডিটের কাজের নাম — ঘরটা ২৪ অক্ষরের (`audit_trails.action`) */
    public const AUDIT_ACTION = 'auto_finished_signed';

    /**
     * ⭐ সই হলো, শেষ হলো না — কারণটা বিলের নিরীক্ষায় (লাইভ DRF-0008, ৭ অক্টোবর ২০২৬; fe)।
     *
     * ⛔ আগে কারণটা কেবল লগে যেত: বিলটা সইয়ের অপেক্ষাতেও নেই, খোলা খসড়াতেও নেই, আর কাউন্টার বলত "খসড়াটা আর খোলা নেই"।
     * ⓘ এখন কারণ বিলের গায়ে ([[lastRefusal()]]), তালিকায় নিজের ভাগ ([[signedNotFinished()]]), আর দুজনকে খবর ([[tell()]])।
     */
    public const AUDIT_REFUSED = 'auto_finish_refused';

    /** ⭐ খবরের ধরন — পাঠানেওয়ালা আর সইকারী দুজনেই পান ([[tell()]]) */
    public const NOTICE = 'sales.signed_sale_stuck';

    public function __construct(
        private readonly DirectSaleService $sales,
        private readonly AuditEngine $audit,
        private readonly NotificationService $notices,
    ) {}

    /**
     * ⭐ সই হয়েছে, শেষ হয়নি — খসড়া বিল আর খসড়া চালান, কাউন্টারের রাখা খসড়া নয় (`counter_draft` খালি) অথচ পর্দার ছবি
     * আছে (`counter_screen` — সইয়ে পাঠানোর ছাপ), আর কোনো সই অপেক্ষায় নেই ([[DirectSaleService::trueDrafts()]])।
     *
     * ⓘ দুই রকম: সবগুলো "হ্যাঁ" অথচ শেষ করতে গিয়ে থামল ([[finish()]] REFUSED — ধরুন ফ্রির দেয়াল), নয় কেউ "না" বলেছেন।
     * দুটোই আগে কোনো তালিকায় ছিল না।
     *
     * @param  Builder<SalesInvoice>|null  $query
     * @return Builder<SalesInvoice>
     */
    public static function signedNotFinished($query = null)
    {
        return DirectSaleService::trueDrafts($query)
            ->whereNull('sal_invoices.counter_draft')
            ->whereNotNull('sal_invoices.counter_screen');
    }

    /**
     * আসল খসড়া — উপরের ভাগ বাদে (খসড়ার ট্যাব আর গোনা দুজনেই এটা নেয়)।
     *
     * @param  Builder<SalesInvoice>  $query
     * @return Builder<SalesInvoice>
     */
    public static function exceptSigned($query)
    {
        return $query->where(fn ($q) => $q->whereNotNull('sal_invoices.counter_draft')
            ->orWhereNull('sal_invoices.counter_screen'));
    }

    /**
     * কেন আটকে — শেষ চেষ্টার কারণ (নিরীক্ষা থেকে), নাহলে এখনকার অবস্থা ([[readiness()]] — যেমন "সই প্রত্যাখ্যাত")।
     */
    public function lastRefusal(SalesInvoice $invoice): string
    {
        $ready = $this->readiness($invoice);

        if ($ready['state'] !== self::FINISHED) {
            return $ready['reason'];
        }

        return (string) (AuditTrail::query()
            ->where('auditable_type', $invoice->getMorphClass())
            ->where('auditable_id', $invoice->id)
            ->where('action', self::AUDIT_REFUSED)
            ->latest('id')
            ->value('reason') ?? '');
    }

    /**
     * ⭐ খসড়ায় ফেরান — সইয়ের পরে থেমে থাকা বিক্রি আবার কাউন্টারের খসড়া হয়, বদলে আবার পাঠানোর জন্য (fe, ৭ অক্টোবর ২০২৬)।
     *
     * ⓘ [[DirectSaleService::withdrawHeld()]]-এর একই কাজ, সইয়ের পরের অবস্থার জন্য: কাউন্টারের জমা-ভাউচার (খসড়া) বাতিল, পর্দার
     * ছবি খসড়ায় ফেরে। দেওয়া সইগুলো ইতিহাসে থাকে। ⛔ কেবল বিক্রির বানানেওয়ালা বা মালিক (সুপার অ্যাডমিন); সারি তালা দিয়ে আবার
     * দেখা হয়, যাতে একই মুহূর্তে "আবার চেষ্টা" পাকা করে ফেললে খসড়ায় ফেরানো থামে।
     */
    public function returnToDraft(SalesInvoice $invoice, User $user): void
    {
        // ⛔ বিলের নিজের শাখায় — শেষ করার একই কারণে (পুনঃঅডিট ৯ অক্টোবর ২০২৬, বিক্রয় ১)
        CompanyContext::inBranch($invoice->branch_id === null ? null : (int) $invoice->branch_id, fn () => DB::transaction(function () use ($invoice, $user): void {
            $locked = SalesInvoice::query()->lockForUpdate()->find($invoice->getKey());

            if ($locked === null || ! self::signedNotFinished()->whereKey($locked->id)->exists()) {
                throw ValidationException::withMessages(['invoice' => __('sales::auto_finish.not_stuck')]);
            }

            $owner = $user->roles->contains('name', PermissionSyncer::SUPER_ADMIN_ROLE);

            if ((int) $locked->created_by !== (int) $user->id && ! $owner) {
                throw ValidationException::withMessages(['invoice' => __('sales::auto_finish.return_only_maker')]);
            }

            $vouchers = Voucher::acrossBranches()
                ->where('origin', Voucher::ORIGIN_COUNTER)
                ->where('against_type', SalesInvoice::drillSourceType())
                ->where('against_id', $locked->id)
                ->get();

            foreach ($vouchers as $voucher) {
                if (! $voucher->isCancelled()) {
                    app(VoucherService::class)->cancel($voucher, __('sales::auto_finish.returned_reason'));
                }
            }

            $locked->update(['counter_draft' => $locked->counter_screen, 'counter_screen' => null]);

            $this->audit->recordAction($locked, 'returned_to_draft', __('sales::auto_finish.returned_reason'));
        }));
    }

    /**
     * অনুমোদনের কাগজটা কোন কাউন্টার-বিলের — চালান, বিল, বা বিলের বিপরীতে জমা।
     *
     * ⚠️ `acrossBranches()` — সইকারী অন্য শাখার হলে তাঁর সীমায় বিলটা "নেই" দেখাত।
     */
    public function invoiceFor(Approval $approval): ?SalesInvoice
    {
        $id = (int) $approval->approvable_id;

        $invoiceId = match ((string) $approval->approvable_type) {
            SalesInvoice::class => $id,

            DeliveryChallan::class => (int) SalesInvoice::acrossBranches()
                ->whereHas('lines.challanLine', fn ($q) => $q->where('delivery_challan_id', $id))
                ->value('sal_invoices.id'),

            Voucher::class => (int) Voucher::acrossBranches()
                ->whereKey($id)
                ->where('origin', Voucher::ORIGIN_COUNTER)
                ->where('against_type', SalesInvoice::drillSourceType())
                ->value('against_id'),

            default => 0,
        };

        return $invoiceId > 0 ? SalesInvoice::acrossBranches()->find($invoiceId) : null;
    }

    /**
     * শেষ করা যায় কি না — কিছু না লিখে, না জিজ্ঞেস করে।
     *
     * ⓘ শুকনো চালানো ([[FinishSignedCounterSales]] `--dry-run`) ঠিক এটাই দেখায়।
     *
     * @return array{state: string, reason: string}
     */
    public function readiness(SalesInvoice $invoice): array
    {
        if ($invoice->status !== 'draft' || $invoice->counter_draft !== null || ! $this->challanStillDraft($invoice)) {
            return ['state' => self::NOT_HELD, 'reason' => __('sales::auto_finish.not_held')];
        }

        $latest = $this->latestApprovals($invoice);

        if ($latest === []) {
            return ['state' => self::NOT_HELD, 'reason' => __('sales::auto_finish.no_signature')];
        }

        foreach ($latest as $approval) {
            if ($approval->status === Approval::PENDING) {
                return ['state' => self::WAITING, 'reason' => __('sales::auto_finish.waiting')];
            }

            if ($approval->status !== Approval::APPROVED) {
                return ['state' => self::REJECTED, 'reason' => __('sales::auto_finish.rejected')];
            }
        }

        $maker = $this->makerOf($invoice);

        if ($maker === null) {
            return ['state' => self::MAKER_GONE, 'reason' => __('sales::auto_finish.maker_gone')];
        }

        return ['state' => self::FINISHED, 'reason' => ''];
    }

    /**
     * শেষ করো — প্রস্তুত হলে; নাহলে কারণসহ ফেরো।
     *
     * ⚠️ কোনো ব্যতিক্রম বাইরে যায় না: ডাকেন সইয়ের পরের শ্রোতা আর এককালীন
     * কমান্ড, আর দুইটার কোনোটাতেই একটা বিলের বাধা বাকি কাজ থামাতে পারে না।
     *
     * @return array{state: string, reason: string}
     */
    public function finish(SalesInvoice $invoice, ?User $signer = null, bool $tell = true): array
    {
        $ready = $this->readiness($invoice);

        if ($ready['state'] !== self::FINISHED) {
            $this->log($invoice, $ready);

            // ⓘ বানানেওয়ালা নেই — সই পড়েছে, তবু শেষ হবে না; সইকারীকে জানানো (বিলটা "সই হয়েছে, শেষ হয়নি" ভাগে)
            if ($ready['state'] === self::MAKER_GONE && $tell) {
                $this->stuck($invoice, $signer, $ready['reason']);
            }

            return $ready;
        }

        /** @var User $maker */
        $maker = $this->makerOf($invoice);
        $before = Auth::user();

        try {
            Auth::setUser($maker);

            // ⛔ বিলের নিজের শাখায় — হেডারে অন্য শাখা থাকলে বিলটা "নেই" হত (পুনঃঅডিট ৯ অক্টোবর ২০২৬, বিক্রয় ১; [[CompanyContext::inBranch()]])
            CompanyContext::inBranch($invoice->branch_id === null ? null : (int) $invoice->branch_id,
                fn () => $this->sales->finishHeld(SalesInvoice::query()->findOrFail($invoice->id)));
        } catch (HeldForApproval) {
            /*
             * ⓘ শেষ করতে গিয়ে আরেকটা সই চাওয়া হলো — যেমন চালানের নিজের ছক
             * ([[DirectSaleService::finishHeld()]], 9973eed8)। ⚠️ এটা ব্যর্থতা নয়,
             * অপেক্ষা: অনুরোধটা বসেছে, আর ঐ সই পড়লে এই পথই আবার চলবে।
             * ⛔ "refused" লিখলে লগে মিথ্যা শোরগোল উঠত।
             */
            $result = ['state' => self::WAITING, 'reason' => __('sales::auto_finish.waiting')];
        } catch (ValidationException $e) {
            $result = [
                'state' => self::REFUSED,
                'reason' => collect($e->errors())->flatten()->first() ?? $e->getMessage(),
            ];
        } catch (Throwable $e) {
            report($e);

            $result = ['state' => self::REFUSED, 'reason' => $e->getMessage()];
        } finally {
            $this->restore($before);
        }

        if (isset($result)) {
            $this->log($invoice, $result);

            /*
             * ⭐ থামল — কারণটা বিলের গায়ে আর দুজনকে খবর (লাইভ DRF-0008)। ⚠️ লগইন ফেরত দেওয়ার **পরে**: বানানেওয়ালার নামে
             * চলতে থাকলে [[NotificationService::send()]] তাঁর নিজের খবর নিজেকে পাঠাত না ([[SignedChallanConfirmer]]-এর শিক্ষা)।
             * ⓘ হাতের "আবার চেষ্টা" খবর পাঠায় না — যিনি চাপলেন তিনি পর্দাতেই কারণ দেখেন; কারণটা তবু লেখা হয়।
             */
            if ($result['state'] === self::REFUSED) {
                $this->stuck($invoice, $signer, $result['reason'], $tell);
            }

            return $result;
        }

        /*
         * ⚠️ বিক্রি ততক্ষণে পাকা — অডিট লেখা ব্যর্থ হলেও সেটা সইকারীর কাছে
         * ভুল হয়ে ফেরে না (সই আর বিক্রি দুইটাই টিকে আছে); কেবল রিপোর্ট হয়।
         */
        try {
            $this->record($invoice->fresh() ?? $invoice, $signer, $maker);
        } catch (Throwable $e) {
            report($e);
        }

        return ['state' => self::FINISHED, 'reason' => ''];
    }

    /**
     * এই বিলের সবগুলো সইয়ের সবশেষ অনুরোধ — চালান, বিল, আর প্রতিটা কাউন্টার-জমা।
     *
     * ⓘ প্রতি কাগজ-আর-কাজে সবশেষটা: প্রত্যাখ্যাত অনুরোধের পরে নতুন অনুরোধ
     * বসলে পুরনো "না" আর কিছু আটকায় না।
     *
     * @return list<Approval>
     */
    public function latestApprovals(SalesInvoice $invoice): array
    {
        $challanIds = $invoice->lines()->with('challanLine')->get()
            ->map(fn ($line) => (int) ($line->challanLine?->delivery_challan_id ?? 0))
            ->filter()->unique()->values()->all();

        $voucherIds = Voucher::acrossBranches()
            ->where('origin', Voucher::ORIGIN_COUNTER)
            ->where('against_type', SalesInvoice::drillSourceType())
            ->where('against_id', $invoice->id)
            ->pluck('id')->all();

        $rows = Approval::query()
            ->where('company_id', $invoice->company_id)
            ->whereIn('status', [Approval::PENDING, Approval::APPROVED, Approval::REJECTED])
            ->where(fn ($q) => $q
                ->where(fn ($c) => $c->where('approvable_type', SalesInvoice::class)->where('approvable_id', $invoice->id))
                ->orWhere(fn ($c) => $c->where('approvable_type', DeliveryChallan::class)->whereIn('approvable_id', $challanIds ?: [0]))
                ->orWhere(fn ($c) => $c->where('approvable_type', Voucher::class)->whereIn('approvable_id', $voucherIds ?: [0])))
            ->orderBy('id')
            ->get();

        $latest = [];

        foreach ($rows as $row) {
            $latest[$row->approvable_type.'#'.$row->approvable_id.'#'.$row->action] = $row;
        }

        return array_values($latest);
    }

    private function challanStillDraft(SalesInvoice $invoice): bool
    {
        $challanId = (int) ($invoice->lines()->with('challanLine')->first()?->challanLine?->delivery_challan_id ?? 0);

        return $challanId > 0 && DeliveryChallan::acrossBranches()
            ->whereKey($challanId)
            ->where('status', 'draft')
            ->exists();
    }

    /**
     * যাঁর নামে চলবে — বিক্রির বানানেওয়ালা, যদি তিনি এখনো সক্রিয় আর এই কোম্পানির।
     *
     * ⛔ নিষ্ক্রিয় কারও নামে টাকা খাতায় বসানো যায় না — তাঁর বাক্স আর কারও
     * হেফাজতে নেই। তখন বিলটা আটকে থাকে, আর কেউ হাতে শেষ করেন।
     */
    private function makerOf(SalesInvoice $invoice): ?User
    {
        $maker = User::query()->find((int) $invoice->created_by);

        if ($maker === null || ! $maker->is_active || ! $maker->canAccessCompany((int) $invoice->company_id)) {
            return null;
        }

        return $maker;
    }

    private function restore(?Authenticatable $before): void
    {
        if ($before !== null) {
            Auth::setUser($before);

            return;
        }

        Auth::forgetUser();
    }

    /** ⓘ অডিটের সারি — সইকারীর নামে, কারণ সিদ্ধান্তটা তাঁর; কাজটা চলেছে বানানেওয়ালার নামে। */
    private function record(SalesInvoice $invoice, ?User $signer, User $maker): void
    {
        $this->audit->recordAction(
            $invoice,
            self::AUDIT_ACTION,
            __('sales::auto_finish.audit', [
                'signer' => $signer?->name ?? __('sales::auto_finish.by_command'),
                'maker' => $maker->name,
            ]),
        );
    }

    /** কারণ নিরীক্ষায় — আর (চাইলে) বানানেওয়ালা ও সইকারীকে খবর। কোনো ব্যর্থতা বাইরে যায় না। */
    private function stuck(SalesInvoice $invoice, ?User $signer, string $reason, bool $tell = true): void
    {
        try {
            $this->audit->recordAction($invoice, self::AUDIT_REFUSED, $reason);
        } catch (Throwable $e) {
            report($e);
        }

        if (! $tell) {
            return;
        }

        $people = collect([User::query()->find((int) $invoice->created_by), $signer])
            ->filter()->unique(fn (User $u) => $u->id);

        foreach ($people as $person) {
            try {
                $this->notices->send(
                    $person,
                    self::NOTICE,
                    __('sales::auto_finish.sale_stuck_title', ['no' => $invoice->document_no]),
                    __('sales::auto_finish.sale_stuck_body', ['reason' => $reason]),
                    route('sales.direct.drafts', ['tab' => 'signed']),
                    // ⓘ সইকারী নিজেই সই দিলেন, কিন্তু থেমে যাওয়াটা দেখেননি — তাই নিজের কাজ হলেও খবর
                    evenToSelf: true,
                );
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    /** @param  array{state: string, reason: string}  $result */
    private function log(SalesInvoice $invoice, array $result): void
    {
        if ($result['state'] === self::NOT_HELD || $result['state'] === self::WAITING) {
            return;
        }

        Log::warning('A signed counter sale was not finished automatically', [
            'company_id' => (int) $invoice->company_id,
            'invoice' => (string) $invoice->document_no,
            'state' => $result['state'],
            'reason' => $result['reason'],
            'context_company' => CompanyContext::id(),
        ]);
    }
}
