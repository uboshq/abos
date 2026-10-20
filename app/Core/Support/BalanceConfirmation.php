<?php

declare(strict_types=1);

namespace App\Core\Support;

use App\Core\Engines\Audit\AuditEngine;
use App\Core\Engines\Print\PaperSize;
use App\Core\Engines\Print\PrintEngine;
use App\Models\LedgerEntry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * ⭐ পাওনা-দেনা নিশ্চিতকরণের চিঠি — একটা তারিখে পক্ষের খাতার জের, আর উত্তরের অংশ (ISA 505 External Confirmations;
 * ERP ১০০%-এর হিসাবের ফাঁক #১৯, ২০ অক্টোবর ২০২৬)। গ্রাহক আর সরবরাহকারী দুজনেরই ([[CustomerBalanceConfirmationController]],
 * [[SupplierBalanceConfirmationController]])।
 *
 * <p>⛔ জের পক্ষের পাতার খাতার অবিকল — একই ভিত্তি (হেডারে বাছা শাখা, পক্ষ, সরবরাহকারীর বেলায় GRNI বাদ) আর একই হিসাব
 * ([[PartyLedger::statement()]]), কেবল "পর্যন্ত" চিঠির তারিখ। তাই চিঠি পাতার শেষ জের থেকে এক পয়সাও আলাদা বলে না।
 *
 * <p>⛔ কে কবে ছাপলেন নিরীক্ষায় থাকে ([[AuditEngine::recordAction()]], কাজ `balance_confirmation_printed`, কারণে তারিখ, জের আর ভাষা)।
 * ⓘ ভাষা `?lang=bn|en` — পক্ষ যে ভাষা পড়েন; না দিলে ব্যবহারকারীর ভাষা। A4, কোম্পানির ছাপার নিয়মে ([[PrintEngine]])।
 */
final class BalanceConfirmation
{
    public const ACTION = 'balance_confirmation_printed';

    /** উত্তর ফেরত দেওয়ার দিন */
    public const REPLY_DAYS = 15;

    public function __construct(private readonly PrintEngine $print, private readonly AuditEngine $audit) {}

    /** তারিখ পর্যন্ত জের, Dr − Cr (পাতার খাতার একই নিয়ম) */
    public function balance(Builder $ledger, string $asOf): string
    {
        return PartyLedger::statement($ledger, Request::create('', 'GET', ['to' => $asOf]))['closing'];
    }

    /**
     * @param  Builder<LedgerEntry>  $ledger  পক্ষের পাতার খাতার একই ভিত্তি
     * @param  array{name?: string, code?: string, address?: ?string, phone?: ?string}  $facts
     */
    public function respond(Request $request, Model $party, string $label, array $facts, Builder $ledger): Response
    {
        $data = $request->validate([
            'as_of' => ['nullable', 'date_format:Y-m-d'],
            'lang' => ['nullable', 'in:bn,en'],
        ]);
        $asOf = $data['as_of'] ?? now()->toDateString();
        $locale = $data['lang'] ?? app()->getLocale();
        $balance = $this->balance($ledger, $asOf);
        $side = match (bccomp($balance, '0', 4)) {
            1 => 'dr',
            -1 => 'cr',
            default => 'nil',
        };

        $pdf = $this->print->render(
            template: 'print.balance-confirmation',
            data: [
                'title' => (string) __('confirmation.title', [], $locale),
                'party' => $label,
                'facts' => $facts,
                'asOf' => $asOf,
                'amount' => Money::format(ltrim($balance, '-')),
                'side' => $side,
                'days' => self::REPLY_DAYS,
            ],
            paper: PaperSize::A4,
            locale: $locale,
        );

        $this->audit->recordAction($party, self::ACTION, $asOf.' · '.Money::drCr($balance).' · '.$locale);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ($request->boolean('download') ? 'attachment' : 'inline')
                .'; filename="balance-confirmation-'.($facts['code'] ?? $party->getKey()).'-'.$asOf.'.pdf"',
        ]);
    }
}
