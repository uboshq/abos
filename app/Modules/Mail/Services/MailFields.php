<?php

declare(strict_types=1);

namespace App\Modules\Mail\Services;

use App\Models\User;
use App\Modules\Customer\Models\Customer;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Supplier\Models\Supplier;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ টেমপ্লেটের ঘর — {গ্রাহকের নাম}, {বাকি টাকা} … পাঠানোর সময় আসল তথ্যে বসে (নমুনা v4, পর্দা ৫)।
 *
 * ⓘ যে ঘরের তথ্য নেই সেটা খালি থাকে না — ঘরের নামটাই থাকে, যাতে পাঠানোর আগে চোখে পড়ে আর হাতে লেখা যায়।
 * ⛔ সংখ্যা ইংরেজি অঙ্কে (মালিক, ১৬ নভেম্বর); টাকা ৳ চিহ্ন আর ভারতীয় কমায়।
 */
final class MailFields
{
    /** ⓘ পর্দায় দেখানো ঘরের তালিকা — চাপলে লেখায় বসে */
    public const FIELDS = [
        '{গ্রাহকের নাম}', '{বাকি টাকা}', '{সবচেয়ে পুরনো বিলের তারিখ}', '{শেষ বিল}', '{পয়েন্ট}', '{উপহার পয়েন্ট}',
        '{বিল নম্বর}', '{বিলের টাকা}', '{টাকা}', '{সরবরাহকারী}', '{তারিখ}', '{কোম্পানি}', '{আমার নাম}',
    ];

    /**
     * @param  array<string, string>  $extra  কাগজ থেকে পাঠালে — বিল নম্বর, টাকা ইত্যাদি
     * @return array<string, string>
     */
    public function values(User $user, ?string $email, array $extra = []): array
    {
        $out = [
            '{তারিখ}' => now()->format('d-m-Y'),
            '{আমার নাম}' => (string) $user->name,
            '{কোম্পানি}' => (string) (\App\Models\Company::query()->whereKey(\App\Core\Support\CompanyContext::companyId())->value('name_en') ?? ''),
        ];

        $email = mb_strtolower(trim((string) $email));

        if ($email !== '' && $user->can('customer.view')) {
            $c = Customer::query()->whereRaw('LOWER(email) = ?', [$email])->first();

            if ($c) {
                $out += $this->forCustomer($c);
            }
        }

        if ($email !== '' && ! isset($out['{গ্রাহকের নাম}']) && $user->can('supplier.view')) {
            $s = Supplier::query()->whereRaw('LOWER(email) = ?', [$email])->first();

            if ($s) {
                $out['{সরবরাহকারী}'] = $s->name();
                $out['{গ্রাহকের নাম}'] = $s->name();
            }
        }

        return array_merge($out, $extra);
    }

    /** @return array<string, string> */
    public function forCustomer(Customer $c): array
    {
        $due = (string) (DB::table('ledger_entries')->where('company_id', $c->company_id)
            ->where('party_type', Customer::drillSourceType())->where('party_id', $c->id)
            ->selectRaw('COALESCE(SUM(debit - credit), 0) b')->value('b') ?? '0');

        $oldest = SalesInvoice::query()->where('customer_id', $c->id)->posted()->withCollected()->orderBy('trx_date')->limit(200)->get()
            ->first(fn ($b) => bccomp((string) $b->dueAmount(), '0', 2) > 0);
        $last = SalesInvoice::query()->where('customer_id', $c->id)->posted()->latest('trx_date')->first();

        $out = [
            '{গ্রাহকের নাম}' => $c->name(),
            '{বাকি টাকা}' => self::money($due),
        ];

        if ($oldest) {
            $out['{সবচেয়ে পুরনো বিলের তারিখ}'] = $oldest->trx_date?->format('d-m-Y') ?? '';
        }

        if ($last) {
            $out['{শেষ বিল}'] = (string) $last->document_no.' · '.$last->trx_date?->format('d-m-Y');
        }

        if (class_exists(\App\Modules\Promotion\Services\LoyaltyLedger::class)) {
            try {
                $out['{পয়েন্ট}'] = number_format((float) app(\App\Modules\Promotion\Services\LoyaltyLedger::class)->balance($c->id));
            } catch (\Throwable) {
                // ⓘ পয়েন্ট চালু না থাকলে ঘরটা যেমন ছিল তেমন থাকে
            }
        }

        return $out;
    }

    /** ⓘ লেখায় ঘর বসানো — HTML-এ বসার আগে মানগুলো নিরাপদ করা হয় */
    public function fill(string $text, array $values, bool $html = true): string
    {
        $safe = $html ? array_map(fn ($v) => e((string) $v), $values) : $values;

        return strtr($text, $safe);
    }

    public static function money(string|float|int $v): string
    {
        $n = round((float) $v, 2);
        $neg = $n < 0;
        $n = abs($n);
        $int = (string) (int) floor($n);
        $dec = str_pad((string) (int) round(($n - floor($n)) * 100), 2, '0', STR_PAD_LEFT);
        // ⓘ ভারতীয় কমা: শেষ তিন অঙ্ক, তারপর দুই-দুই
        $last3 = substr($int, -3);
        $rest = substr($int, 0, -3);
        $rest = $rest !== '' ? preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest).',' : '';

        return ($neg ? '-' : '').'৳'.$rest.$last3.($dec !== '00' ? '.'.$dec : '');
    }
}
