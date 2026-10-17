<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Phone;

use App\Core\Phone\PaperSheet;
use App\Core\Phone\PhonePaper;
use App\Core\Support\DateFormat;
use App\Core\Support\Money;
use App\Models\User;
use App\Modules\Accounts\Models\InterCompanyTransfer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * এক কোম্পানির টাকা আরেক কোম্পানির জন্য — ওয়েবের `/accounts/inter-company` ([[PhonePaper]])। ⓘ কেবল দেখা: দুই কোম্পানির খাতায়
 * লেখা দুই কোম্পানির চাবি চায় ([[InterCompanyService::assertBothAreMine()]]), সেটা ওয়েবেই। নম্বর আমাদের দিকের জাবেদার।
 */
final class InterCompanyPaper extends PhonePaper
{
    public function key(): string
    {
        return 'accounts_inter_company';
    }

    public function module(): string
    {
        return 'accounts';
    }

    public function permission(): string
    {
        return 'accounts.inter_company';
    }

    public function webRoute(): array
    {
        return ['accounts.inter_company.index', []];
    }

    public function query(): Builder
    {
        return InterCompanyTransfer::query();
    }

    /** ⓘ কোম্পানির কাগজ, শাখার নয় */
    public function branchColumn(): ?string
    {
        return null;
    }

    public function searchable(): bool
    {
        return false;
    }

    public function sumColumn(User $user): ?string
    {
        return 'acc_inter_company.amount';
    }

    public function with(): array
    {
        return ['counterCompany', 'outVoucher'];
    }

    public function numberOf(Model $paper): string
    {
        /** @var InterCompanyTransfer $paper */
        return (string) ($paper->outVoucher?->document_no ?? '#'.$paper->getKey());
    }

    public function row(Model $paper, User $user): array
    {
        /** @var InterCompanyTransfer $paper */
        return ['party' => $paper->counterCompany?->name(), 'note' => $paper->purpose, 'total' => (string) $paper->amount];
    }

    public function detail(Model $paper, User $user): array
    {
        /** @var InterCompanyTransfer $paper */
        $paper->loadMissing(['counterCompany', 'outVoucher', 'inVoucher', 'creator']);

        return PaperSheet::make(facts: [
            __('accounts::field.inter_company_counter') => $paper->counterCompany?->name(),
            __('accounts::field.date') => DateFormat::format($paper->trx_date),
            __('accounts::field.amount') => Money::format((string) $paper->amount),
            __('accounts::field.inter_company_purpose') => $paper->purpose,
            __('accounts::field.inter_company_both_sides') => trim(($paper->outVoucher?->document_no ?? '').' · '.($paper->inVoucher?->document_no ?? ''), ' ·'),
        ]);
    }
}
