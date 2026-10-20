<?php

declare(strict_types=1);

namespace App\Modules\Hr\Services;

use App\Core\Services\NotificationService;
use App\Core\Services\PermissionSyncer;
use App\Core\Services\SettingsService;
use App\Core\Support\CompanyContext;
use App\Core\Support\MakerChecker;
use App\Models\User;
use App\Modules\Hr\Models\Employee;
use App\Modules\Hr\Models\PaymentChange;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ কর্মীর বেতনের ঠিকানা বদলায় দুই হাতে — আন্তর্জাতিক মানের ফাঁক #৮ (গুরুতর), ২০ অক্টোবর ২০২৬ (fe; gaps-core-hr.md #৯)।
 *
 * ⛔ আগে `hr.employee.manage` + `hr.identity.view` থাকা একজনই কর্মীর ব্যাংক নম্বর, MFS নম্বর বা বেতনের পথ বদলাতে পারতেন, আর
 * সাথে সাথে বসত — পরের ব্যাংক-ফাইল বেতন নতুন হিসাবে পাঠাত। বেতন জালিয়াতির সবচেয়ে চেনা পথ (SOX/ITGC maker-checker; ISO 27001
 * A.5.3)।
 *
 * ⓘ এখন:
 *   · বদলটা কর্মীর সারিতে বসে না — "অপেক্ষার বদল" হয়ে থাকে ([[PaymentChange]], ঢাকা); অপেক্ষার সময় বেতন যায় পুরনো হিসাবে,
 *     কারণ বেতন চলে কর্মীর সারি থেকেই ([[PayrollService]])।
 *   · বসে দ্বিতীয় একজনের সইয়ে — `hr.payment_change.approve` থাকা, আর লেখক নন ([[MakerChecker::isAnotherHand()]])।
 *   · সই পড়ার মুহূর্তে আগের মান `previous`-এ — পুরনো আর নতুন দুটোই থাকে, ঢাকা; নিরীক্ষার খাতায় কেবল "ঢাকা"।
 *   · কর্মীকে খবর যায় — চাওয়ার সময়েই, আর বসলে বা না হলে: তাঁর অজান্তে কেউ ঠিকানা বদলাতে চাইলে তিনিই প্রথম জানেন।
 *   · এক কর্মীর একটাই অপেক্ষার বদল — নতুন চাওয়া পুরনোটা ফিরিয়ে নেয় (বাতিল, মোছা নয়)।
 *
 * ⓘ ছাড় দুটো, মালিকের নিয়মে: super admin নিজে বদলালে সরাসরি বসে (maker-checker-এর মালিকের ছাড়, ১৪ অক্টোবর ২০২৬) — সারিটা
 * তবু লেখা হয়, কর্মী খবর পান; আর কোম্পানি `hr.payment_change_needs_second_hand` বন্ধ করলে সবারটা সরাসরি বসে, একই রকম লেখা
 * থাকে (সই সব জায়গায়, চালু-বন্ধসহ — মালিক)। ডিফল্টে চালু।
 */
final class PaymentChanges
{
    public const WAITING = 'hr.payment_change_waiting';

    public const TOLD = 'hr.payment_change_told';

    /** ঢাকা ঘর — কর্মীর `sensitive_fields`-এর একই তিনটা */
    private const SECRET = ['bank_account_no', 'bank_routing_no', 'mfs_number'];

    public function __construct(private readonly SettingsService $settings) {}

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

        // ⓘ আগের অপেক্ষার বদল ফিরে যায় — সইকারী কেবল সর্বশেষ চাওয়াটা দেখেন
        PaymentChange::query()->where('employee_id', $locked->id)->where('status', PaymentChange::PENDING)->get()
            ->each(fn (PaymentChange $old) => $old->update([
                'status' => PaymentChange::CANCELLED,
                'decided_by' => $by->id,
                'decided_at' => now(),
                'note' => __('hr::payment_change.replaced'),
            ]));

        $change = PaymentChange::query()->create([
            'company_id' => (int) $locked->company_id,
            'employee_id' => (int) $locked->id,
            'proposed' => $proposed,
            'status' => PaymentChange::PENDING,
            'requested_by' => $by->id,
        ]);

        if ($this->needsSecondHand() && ! self::isOwner($by)) {
            $this->tellTheSigners($change, $locked, $by);
            $this->tellTheEmployee($change, $locked, 'requested');

            return $change;
        }

        // ⓘ ছাড়: মালিক নিজে, বা কোম্পানি সই বন্ধ করেছে — সরাসরি বসে, সারিতে লেখা থাকে
        return $this->apply($change, $locked, $by);
    }

    /** দ্বিতীয় হাতের সই — বসানো */
    public function approve(PaymentChange $change, User $by): PaymentChange
    {
        return DB::transaction(function () use ($change, $by) {
            [$change, $employee] = $this->lockPending($change);

            if (! $by->can('hr.payment_change.approve')) {
                throw ValidationException::withMessages(['payment_change' => __('hr::payment_change.no_right')]);
            }

            if (! MakerChecker::isAnotherHand($by, (int) $change->requested_by)) {
                throw ValidationException::withMessages(['payment_change' => __('hr::payment_change.another_hand')]);
            }

            return $this->apply($change, $employee, $by);
        });
    }

    /** না — কর্মীর সারি যেমন ছিল তেমনই; যিনি চাইলেন তিনি নিজেও ফিরিয়ে নিতে পারেন */
    public function reject(PaymentChange $change, User $by, ?string $note = null): PaymentChange
    {
        return DB::transaction(function () use ($change, $by, $note) {
            [$change, $employee] = $this->lockPending($change);

            $own = (int) $change->requested_by === (int) $by->id;

            if (! $own && ! $by->can('hr.payment_change.approve')) {
                throw ValidationException::withMessages(['payment_change' => __('hr::payment_change.no_right')]);
            }

            $change->update([
                'status' => $own ? PaymentChange::CANCELLED : PaymentChange::REJECTED,
                'decided_by' => $by->id,
                'decided_at' => now(),
                'note' => filled($note) ? mb_substr(trim((string) $note), 0, 500) : null,
            ]);

            $this->tellTheEmployee($change, $employee, 'rejected');

            return $change->fresh();
        });
    }

    public function pendingFor(Employee $employee): ?PaymentChange
    {
        return PaymentChange::query()->where('employee_id', $employee->id)->where('status', PaymentChange::PENDING)
            ->with('requester')->latest('id')->first();
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

    private function needsSecondHand(): bool
    {
        return (bool) $this->settings->get('hr.payment_change_needs_second_hand', true);
    }

    /** super admin — maker-checker-এর মালিকের ছাড় ([[MakerChecker]]) */
    private static function isOwner(User $user): bool
    {
        return $user->roles->contains('name', PermissionSyncer::SUPER_ADMIN_ROLE);
    }

    /** @return array{0: PaymentChange, 1: Employee} */
    private function lockPending(PaymentChange $change): array
    {
        $employee = Employee::query()->whereKey($change->employee_id)->lockForUpdate()->firstOrFail();
        $change = PaymentChange::query()->whereKey($change->getKey())->lockForUpdate()->firstOrFail();

        if (! $change->isPending()) {
            throw ValidationException::withMessages(['payment_change' => __('hr::payment_change.not_pending')]);
        }

        return [$change, $employee];
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

    /** সইকারীরা — এই কোম্পানির, চাবি থাকা, লেখক বাদ */
    private function tellTheSigners(PaymentChange $change, Employee $employee, User $by): void
    {
        $signers = User::query()
            ->whereHas('companies', fn ($q) => $q->whereKey(CompanyContext::id()))
            ->whereKeyNot($by->id)
            ->get()
            ->filter(fn (User $u) => $u->can('hr.payment_change.approve'));

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
