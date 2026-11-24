<?php

declare(strict_types=1);

namespace App\Modules\Hr\Services;

use App\Core\Engines\Approval\ApprovalEngine;
use App\Core\Services\NotificationService;
use App\Core\Support\CompanyContext;
use App\Models\Approval;
use App\Models\ApprovalDecision;
use App\Models\User;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\PaymentChange;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ কর্মীর বেতনের ঠিকানা বদলায় দুই হাতে — আন্তর্জাতিক মানের ফাঁক #৮ (গুরুতর), ২০ অক্টোবর ২০২৬ (cb, ee99f3eaa; gaps-core-hr.md #৯)।
 *
 * ⛔ আগে `hr.employee.manage` + `hr.identity.view` থাকা একজনই কর্মীর ব্যাংক নম্বর, MFS নম্বর বা বেতনের পথ বদলাতে পারতেন, আর
 * সাথে সাথে বসত — পরের ব্যাংক-ফাইল বেতন নতুন হিসাবে পাঠাত। বেতন জালিয়াতির সবচেয়ে চেনা পথ (SOX/ITGC maker-checker; ISO 27001
 * A.5.3)।
 *
 * ⓘ এখন:
 *   · বদলটা কর্মীর সারিতে বসে না — "অপেক্ষার বদল" হয়ে থাকে ([[PaymentChange]], ঢাকা); অপেক্ষার সময় বেতন যায় পুরনো হিসাবে,
 *     কারণ বেতন চলে কর্মীর সারি থেকেই ([[PayrollService]])।
 *   · ⭐ সই সইয়ের ইঞ্জিনে, বেতনের রান আর কাঠামোর একই নিয়মে — মালিকের একমাত্র ব্যতিক্রম (a6, ২১ অক্টোবর ২০২৬;
 *     [[ApprovalRules::secondHand()]]): `hr` · `payment_change`, কোম্পানির ছক না থাকলে বা সব বন্ধ থাকলেও (UB) যন্ত্রের "দ্বিতীয় সই" —
 *     `hr.employee.manage` + `hr.identity.view` থাকা আরেকজন (যে চাবিতে বদলটা লেখা যায়), যিনি চাইলেন তিনি নন। ইনবক্স, ঘণ্টা আর
 *     কর্মীর পাতার বোতাম একই অনুরোধে। ⛔ যিনি চাইলেন তিনি কখনো সই দেন না — মালিকও নন (fe, ২১ অক্টোবর ২০২৬)।
 *     ⛔ cb-র আগের দুই ছাড় আর নেই: মালিকের বদল সরাসরি বসত, আর একটা সেটিং গোটা নিয়মটা বন্ধ করত — মালিক বলেছেন "সবসময়"।
 *   · সই পড়ার মুহূর্তে আগের মান `previous`-এ — পুরনো আর নতুন দুটোই থাকে, ঢাকা; নিরীক্ষার খাতায় কেবল "ঢাকা"।
 *   · কর্মীকে খবর যায় — চাওয়ার সময়েই, আর বসলে বা না হলে: তাঁর অজান্তে কেউ ঠিকানা বদলাতে চাইলে তিনিই প্রথম জানেন।
 *   · এক কর্মীর একটাই অপেক্ষার বদল — নতুন চাওয়া পুরনোটা আর তার অনুরোধ ফিরিয়ে নেয় (বাতিল, মোছা নয়)।
 */
final class PaymentChanges
{
    public const WAITING = 'hr.payment_change_waiting';

    public const TOLD = 'hr.payment_change_told';

    /** ⭐ অনুমোদনের ছকে এই কাজটা — `hr` · `payment_change` */
    public const MODULE = 'hr';

    public const ACTION = 'payment_change';

    /** ঢাকা ঘর — কর্মীর `sensitive_fields`-এর একই তিনটা */
    private const SECRET = ['bank_account_no', 'bank_routing_no', 'mfs_number'];

    public function __construct(private readonly ApprovalEngine $engine) {}

    /**
     * ফর্মের মান থেকে বেতনের ঠিকানার অংশ — যা আজকের থেকে আলাদা কেবল তা থাকলে বদল চাওয়া হয়; নাহলে null।
     *
     * ⓘ ডাকে [[EmployeeService::update()]] — তালা আর লেনদেনের ভিতরে, কর্মীর বাকি ঘরগুলো বসানোর পরে।
     *
     * @param  array<string, mixed>  $data  ফর্মের মান; ⓘ যে ঘর নেই (দেখার চাবি নেই বলে সরানো) সেটা আজকের মানেই থাকে
     */
    public function propose(Employee $employee, array $data, User $by): ?PaymentChange
    {
        $current = self::detailsOf($employee);
        $proposed = $current;

        foreach (PaymentChange::FIELDS as $field) {
            if (array_key_exists($field, $data)) {
                $proposed[$field] = blank($data[$field]) ? null : trim((string) $data[$field]);
            }
        }

        if ($proposed == $current) {
            return null;
        }

        $locked = Employee::query()->whereKey($employee->getKey())->lockForUpdate()->firstOrFail();

        // ⓘ আগের অপেক্ষার বদল ফিরে যায়, তার অনুরোধও — সইকারী কেবল সর্বশেষ চাওয়াটা দেখেন
        PaymentChange::query()->where('employee_id', $locked->id)->where('status', PaymentChange::PENDING)->get()
            ->each(function (PaymentChange $old) use ($by): void {
                $old->update([
                    'status' => PaymentChange::CANCELLED,
                    'decided_by' => $by->id,
                    'decided_at' => now(),
                    'note' => __('hr::payment_change.replaced'),
                ]);

                $asked = $this->approvalOf($old);

                if ($asked !== null) {
                    $this->engine->withdrawStale($asked);
                }
            });

        $change = PaymentChange::query()->create([
            'company_id' => (int) $locked->company_id,
            'employee_id' => (int) $locked->id,
            'proposed' => $proposed,
            'status' => PaymentChange::PENDING,
            'requested_by' => $by->id,
        ]);

        $approval = $this->engine->request(
            $change,
            self::MODULE,
            self::ACTION,
            amount: null,
            // ⓘ কারণে কেবল কর্মীর নাম — নম্বর নয়; অনুরোধের কারণ নিরীক্ষকও পড়েন
            reason: __('hr::payment_change.asked_reason', ['employee' => $locked->name()]),
            userId: (int) $by->id,
            matchOn: [],
        );

        // ⓘ কোনো নিয়মই ধরে না (মডিউলের ঘোষণা সরালে) — তখনই কেবল সরাসরি, সারিতে লেখা থাকে
        if ($approval === null) {
            return $this->apply($change, $locked, $by);
        }

        $this->tellTheSigners($change, $approval, $locked, $by);
        $this->tellTheEmployee($change, $locked, 'requested');

        return $change;
    }

    /**
     * ⭐ কর্মীর পাতার "সই দিন" — ইঞ্জিনের সই, ইনবক্সের একই নিয়মে; শেষ সইয়ে বসে ([[applySigned()]])।
     */
    public function approve(PaymentChange $change, User $by): PaymentChange
    {
        $approval = $this->pendingApproval($change);

        if (! $this->engine->canDecide($approval, $by)) {
            throw ValidationException::withMessages(['payment_change' => (int) $change->requested_by === (int) $by->id
                ? __('hr::payment_change.another_hand')
                : __('hr::payment_change.no_right')]);
        }

        $this->engine->approve($approval, $by);

        return $change->fresh();
    }

    /** না — কর্মীর সারি যেমন ছিল তেমনই; যিনি চাইলেন তিনি নিজেও ফিরিয়ে নিতে পারেন */
    public function reject(PaymentChange $change, User $by, ?string $note = null): PaymentChange
    {
        $approval = $this->pendingApproval($change);
        $own = (int) $change->requested_by === (int) $by->id;
        $note = filled($note) ? mb_substr(trim((string) $note), 0, 500) : null;

        if ($own) {
            // ⓘ আগে সারি বন্ধ (লেখকের নোটসহ), তারপর অনুরোধ — ফেরানোর শ্রোতা তখন বন্ধ সারি দেখে কিছু করে না
            $this->close($change, PaymentChange::CANCELLED, $by, $note);
            $this->engine->cancel($approval, $by);

            return $change->fresh();
        }

        if (! $this->engine->canDecide($approval, $by)) {
            throw ValidationException::withMessages(['payment_change' => __('hr::payment_change.no_right')]);
        }

        // ⓘ "না"-র শ্রোতা সারিটা বন্ধ করে আর কর্মীকে বলে ([[FinishThePaymentChangeOnTheLastSignature]])
        $this->engine->reject($approval, $by, $note ?? __('hr::payment_change.rejected'));

        return $change->fresh();
    }

    /**
     * ⭐ শেষ সই পড়ল — বদলটা এবার বসে; "না" হলে সারিটা বন্ধ ([[FinishThePaymentChangeOnTheLastSignature]])।
     *
     * ⛔ সইয়ের দিনেও পথ আর ঘর মেলে ([[apply()]]); না মিললে বসে না, সারিটা অপেক্ষায় থাকে না — "না" হয়, কারণসহ, আর কর্মী জানেন।
     */
    public function finishSigned(Approval $approval): void
    {
        $change = $approval->approvable;

        if (! $change instanceof PaymentChange || ! $change->isPending()) {
            return;
        }

        $signer = $this->lastSigner($approval);

        if ($approval->status === Approval::REJECTED) {
            $this->close($change, PaymentChange::REJECTED, $signer, $approval->decisions()->latest('id')->value('remarks'));

            return;
        }

        if ($approval->status !== Approval::APPROVED || $approval->isUsed() || $signer === null) {
            return;
        }

        try {
            DB::transaction(function () use ($change, $approval, $signer): void {
                $employee = Employee::query()->whereKey($change->employee_id)->lockForUpdate()->firstOrFail();
                $fresh = PaymentChange::query()->whereKey($change->getKey())->lockForUpdate()->firstOrFail();

                if (! $fresh->isPending()) {
                    return;
                }

                $this->apply($fresh, $employee, $signer);
                $approval->markUsed();
            });
        } catch (ValidationException $e) {
            $this->close($change, PaymentChange::REJECTED, $signer, (string) collect($e->errors())->flatten()->first());
        }
    }

    /**
     * ⭐ অনুমোদনের কেন্দ্রের সাধারণ "ফিরিয়ে নিন" — অপেক্ষার বদলটাও বন্ধ, অডিটে কারণসহ; কর্মীর সারিতে আগের তথ্যই
     * ([[CloseThePaymentChangeOnWithdrawal]]; fe, ২১ অক্টোবর ২০২৬)।
     */
    public function closeWithdrawn(Approval $approval): void
    {
        $change = $approval->approvable;

        if (! $change instanceof PaymentChange || ! $change->isPending()) {
            return;
        }

        $by = $approval->requested_by === null ? null : User::query()->find((int) $approval->requested_by);
        $this->close($change, PaymentChange::CANCELLED, $by, __('hr::payment_change.withdrawn_note'));
        $change->auditAction('payment_change_withdrawn', __('hr::payment_change.withdrawn_note'));
    }

    public function pendingFor(Employee $employee): ?PaymentChange
    {
        return PaymentChange::query()->where('employee_id', $employee->id)->where('status', PaymentChange::PENDING)
            ->with('requester')->latest('id')->first();
    }

    /** ⓘ এই বদলের সইয়ের অনুরোধ — সর্বশেষটা */
    public function approvalOf(PaymentChange $change): ?Approval
    {
        return $this->engine->latestFor($change, self::ACTION);
    }

    /** ⓘ পাতার বোতাম — এই মানুষ এখন সই দিতে পারেন কি না, ইনবক্সের একই প্রশ্নে */
    public function canSign(PaymentChange $change, ?User $user): bool
    {
        $approval = $this->approvalOf($change);

        return $user !== null && $approval !== null && $approval->isPending() && $this->engine->canDecide($approval, $user);
    }

    /** @return array<string, string|null> কর্মীর সারিতে আজ যা আছে — বেতনের ঠিকানার ঘরগুলো */
    public static function detailsOf(Employee $employee): array
    {
        $out = [];

        foreach (PaymentChange::FIELDS as $field) {
            $value = $employee->getAttribute($field);
            $out[$field] = blank($value) ? null : (string) $value;
        }

        return $out;
    }

    /**
     * ⓘ ঢাকা দেখানো — শেষ চার অঙ্ক; পুরো নম্বর কেবল `hr.identity.view` থাকলে (কর্মীর পাতার একই চাবি)।
     */
    public static function shown(string $field, ?string $value, ?User $viewer): string
    {
        if (blank($value)) {
            return '—';
        }

        if ($field === 'payment_method') {
            return (string) __('hr::kind.'.$value);
        }

        // ⓘ ব্যাংকের নাম, শাখা আর হিসাবের নাম খোলা ঘর — কর্মীর পাতায়ও খোলা
        if (! in_array($field, self::SECRET, true) || $viewer?->can('hr.identity.view')) {
            return (string) $value;
        }

        return str_repeat('•', max(0, mb_strlen((string) $value) - 4)).mb_substr((string) $value, -4);
    }

    private function pendingApproval(PaymentChange $change): Approval
    {
        // ⛔ তালা দিয়ে তাজা অবস্থা — হাতের কপি বাসি হতে পারে
        $change = DB::transaction(fn () => PaymentChange::query()->whereKey($change->getKey())->lockForUpdate()->firstOrFail());
        $approval = $this->approvalOf($change);

        if (! $change->isPending() || $approval === null || ! $approval->isPending()) {
            throw ValidationException::withMessages(['payment_change' => __('hr::payment_change.not_pending')]);
        }

        return $approval;
    }

    private function lastSigner(Approval $approval): ?User
    {
        $id = $approval->decisions()->whereIn('decision', [ApprovalDecision::APPROVED, ApprovalDecision::REJECTED])
            ->latest('id')->value('user_id');

        return $id === null ? null : User::query()->find((int) $id);
    }

    private function close(PaymentChange $change, string $status, ?User $by, ?string $note): void
    {
        $change->update([
            'status' => $status,
            'decided_by' => $by?->id,
            'decided_at' => now(),
            'note' => filled($note) ? mb_substr(trim((string) $note), 0, 500) : null,
        ]);

        $employee = Employee::query()->find($change->employee_id);

        // ⓘ "না" হোক বা লেখক ফিরিয়ে নিন — কর্মী জানেন বদলটা বসেনি (cb-র আগের আচরণ)
        if ($employee !== null) {
            $this->tellTheEmployee($change, $employee, 'rejected');
        }
    }

    private function apply(PaymentChange $change, Employee $employee, User $by): PaymentChange
    {
        $proposed = $change->proposed;

        // ⛔ সইয়ের দিনেও পথ আর ঘর মেলে — "ব্যাংক" অথচ নম্বর খালি হলে বেতনের দিনে গোটা ফাইল ফেরে
        if (($proposed['payment_method'] ?? 'cash') === 'bank' && blank($proposed['bank_account_no'] ?? null)) {
            throw ValidationException::withMessages(['bank_account_no' => __('hr::validation.bank_account_required')]);
        }

        if (($proposed['payment_method'] ?? 'cash') === 'mfs' && blank($proposed['mfs_number'] ?? null)) {
            throw ValidationException::withMessages(['mfs_number' => __('hr::validation.mfs_number_required')]);
        }

        $previous = self::detailsOf($employee);

        $employee->forceFill(array_intersect_key($proposed, array_flip(PaymentChange::FIELDS)))->save();

        $change->update([
            'previous' => $previous,
            'status' => PaymentChange::APPLIED,
            'decided_by' => $by->id,
            'decided_at' => now(),
        ]);

        $this->tellTheEmployee($change, $employee, 'applied');

        return $change->fresh();
    }

    /** সইকারীরা — এই কোম্পানির, যাঁরা এই অনুরোধে সই দিতে পারেন (ইঞ্জিনের একই প্রশ্ন), লেখক বাদ */
    private function tellTheSigners(PaymentChange $change, Approval $approval, Employee $employee, User $by): void
    {
        $signers = User::query()
            ->whereHas('companies', fn ($q) => $q->whereKey(CompanyContext::id()))
            ->whereKeyNot($by->id)
            ->where('is_active', true)
            ->get()
            ->filter(fn (User $u) => $this->engine->canDecide($approval, $u));

        foreach ($signers as $signer) {
            app(NotificationService::class)->send(
                $signer,
                self::WAITING,
                __('hr::payment_change.waiting_title', ['employee' => $employee->name()]),
                __('hr::payment_change.waiting_body', ['by' => $by->name, 'employee' => $employee->name()]),
                route('hr.employee.show', $employee),
                priority: 'high',
                key: 'hr:payment_change:'.$change->id.':'.$signer->id,
                about: $employee,
            );
        }
    }

    /** কর্মী নিজে — লগইন থাকলে; ⓘ তাঁর নিজের কাজ হলেও যায় (কে কখন তাঁর বেতনের ঠিকানা ছুঁল, তিনি জানবেন) */
    private function tellTheEmployee(PaymentChange $change, Employee $employee, string $what): void
    {
        if ($employee->user_id === null) {
            return;
        }

        app(NotificationService::class)->send(
            (int) $employee->user_id,
            self::TOLD,
            __('hr::payment_change.told_'.$what.'_title'),
            __('hr::payment_change.told_'.$what.'_body'),
            evenToSelf: true,
            priority: 'high',
            key: 'hr:payment_change:'.$change->id.':'.$what,
        );
    }
}
