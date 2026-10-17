<?php

declare(strict_types=1);

namespace App\Modules\Finance\Phone;

use App\Core\Phone\PaperSheet;
use App\Core\Phone\PhonePaper;
use App\Core\Support\DateFormat;
use App\Core\Support\Money;
use App\Models\User;
use App\Modules\Finance\Models\InsurancePolicy;
use App\Modules\Finance\Models\InsurancePremium;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * বীমা পলিসি — ওয়েবের `/finance/insurance` ([[PhonePaper]])। ⓘ কেবল দেখা: প্রিমিয়াম দেওয়া প্রদান ভাউচারে, দাবি ওয়েবে। তারিখের
 * ছাঁকনি নেই (পলিসি চলমান); অবস্থা চালু/বন্ধ; পাতায় প্রিমিয়ামের সূচি।
 */
final class InsurancePaper extends PhonePaper
{
    public function key(): string
    {
        return 'finance_insurance';
    }

    public function module(): string
    {
        return 'finance';
    }

    public function permission(): string
    {
        return 'finance.insurance.view';
    }

    public function webRoute(): array
    {
        return ['finance.insurance.index', []];
    }

    public function query(): Builder
    {
        return InsurancePolicy::query()->inViewedBranch();
    }

    public function dateColumn(): ?string
    {
        return null;
    }

    public function statuses(): array
    {
        return ['active' => __('finance::state.active'), 'inactive' => __('core.status.closed')];
    }

    public function applyStatus(Builder $query, ?string $status): void
    {
        if ($status !== null) {
            $query->where('fin_insurance_policies.is_active', $status === 'active');
        }
    }

    public function statusOf(Model $paper): string
    {
        return $paper->getAttribute('is_active') ? 'active' : 'inactive';
    }

    public function search(Builder $query, string $term): void
    {
        $like = '%'.addcslashes($term, '%_\\').'%';
        $query->where(fn (Builder $q) => $q->where('fin_insurance_policies.policy_no', 'like', $like)
            ->orWhere('fin_insurance_policies.subject', 'like', $like));
    }

    public function sumColumn(User $user): ?string
    {
        return 'fin_insurance_policies.premium';
    }

    public function numberOf(Model $paper): string
    {
        return (string) $paper->getAttribute('policy_no');
    }

    public function with(): array
    {
        return ['institution'];
    }

    public function row(Model $paper, User $user): array
    {
        /** @var InsurancePolicy $paper */
        return [
            'party' => self::nameOf($paper->institution),
            'note' => trim(implode(' · ', array_filter([
                $paper->covers ? __('finance::insurance.covers_'.$paper->covers) : null,
                $paper->subject,
                $paper->ends_on ? __('finance::insurance.ends_on').' '.DateFormat::format($paper->ends_on) : null,
            ]))),
            'total' => (string) ($paper->premium ?? '0'),
        ];
    }

    public function detail(Model $paper, User $user): array
    {
        /** @var InsurancePolicy $paper */
        $paper->loadMissing(['institution', 'premiums']);

        return PaperSheet::make(
            facts: [
                __('finance::insurance.insurer') => self::nameOf($paper->institution),
                __('finance::insurance.covers') => $paper->covers ? __('finance::insurance.covers_'.$paper->covers) : null,
                __('finance::insurance.subject') => $paper->subject,
                __('finance::insurance.sum_insured') => Money::format((string) ($paper->sum_insured ?? '0')),
                __('finance::insurance.premium') => Money::format((string) ($paper->premium ?? '0')),
                __('finance::insurance.frequency') => $paper->frequency ? __('finance::insurance.frequency_'.$paper->frequency) : null,
                __('finance::insurance.period') => trim(DateFormat::format($paper->starts_on).' – '.DateFormat::format($paper->ends_on), ' –'),
                __('finance::insurance.notes') => $paper->notes,
            ],
            columns: [
                'period' => [__('finance::insurance.period')],
                'amount' => [__('finance::insurance.premium'), true],
                'state' => [__('finance::insurance.premium_state')],
            ],
            rows: $paper->premiums->sortBy('period_from')->values()->map(fn (InsurancePremium $p) => [
                'period' => trim(DateFormat::format($p->period_from).' – '.DateFormat::format($p->period_to), ' –'),
                'amount' => Money::format((string) $p->amount),
                'state' => $p->status === 'posted' ? __('finance::insurance.premium_state_paid')
                    : ($p->period_from !== null && $p->period_from->isPast() ? __('finance::insurance.premium_state_overdue') : null),
            ])->all(),
        );
    }
}
