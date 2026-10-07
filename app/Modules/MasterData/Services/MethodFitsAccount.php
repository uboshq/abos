<?php

declare(strict_types=1);

namespace App\Modules\MasterData\Services;

use App\Modules\Accounts\Models\Account;
use App\Modules\MasterData\Models\PaymentMethod;
use Illuminate\Validation\ValidationException;

/**
 * পেমেন্ট-পদ্ধতির ধরন আর তার টাকার খাতের ধরন এক হতে হবে — এক জায়গায় লেখা নিয়ম।
 *
 * ── ⛔ কেন এই ক্লাস (২৭ সেপ্টেম্বর ২০২৬) ───────────────────────────────
 * "নগদ" ধরনের পদ্ধতিকে বিকাশের খাতে বেঁধে দিলে সেটা নির্বিঘ্নে সেভ হত —
 * সেটিংসের তালিকা কেবল প্রতিটা ঘর আলাদা করে দেখত (`Rule::in`), জোড়াটা
 * কখনো নয়। ⚠️ আর কাউন্টারের ভাউচারে `instrument` ঘরে বসে পদ্ধতির **কোড**
 * (`CASH`, `BKASH`), তাই ভাউচারের "মাধ্যম আর খাত মেলে কি না" পাহারা
 * ([[VoucherService::assertTheWayMatchesTheAccount]]) ওই পথে কখনো চলে না।
 * ⛔ ফল: ক্যাশিয়ার "নগদ" চাপেন, টাকা বসে বিকাশে — দিনশেষে ড্রয়ারে কম,
 * বিকাশে এমন টাকা যা কখনো আসেনি।
 *
 * ── নিয়ম ──────────────────────────────────────────────────────────────
 *   · পদ্ধতি নেই, বা ধরন খালি → প্রশ্ন নেই (খালি ধরন ইচ্ছাকৃত "সাধারণ" সারি)
 *   · চেক → প্রশ্ন নেই: চেক আগে ১১০৪/২১১৫-এ বসে, ব্যাংকে পরে (নকশাই এমন)
 *   · নগদ → নগদের খাত · MFS → মোবাইল মানির খাত · ব্যাংক → ব্যাংক (⛔ MFS নয়)
 *   · ⚠️ তালিকার বাইরের ধরন → মেলে না (বদ্ধ তালিকা, খোলা দরজা নয়)
 *
 * ⓘ MasterData-তে, কারণ প্রশ্নটা পদ্ধতির — খাতের নয়। যে দরজা পদ্ধতি সেভ
 * করে, কাউন্টারে টাকা নেয়, বা দাবি মঞ্জুর করে — সবাই এটাই ডাকে, যাতে
 * নিয়মটা কোথাও আলাদা হয়ে না যায়।
 */
final class MethodFitsAccount
{
    /** ধরন নেই এমন পদ্ধতি — যেমন কোম্পানির নিজের যোগ করা "কার্ড" (খালি ধরন)। */
    private const UNCHECKED = [null, '', 'cheque'];

    /**
     * কেবল জানতে চাওয়া — মেলে কি না।
     */
    public function fits(Account $account, ?PaymentMethod $method): bool
    {
        return $method === null || $this->fitsKind($account, $method->kind);
    }

    /**
     * ধরন দিয়ে জিজ্ঞেস — পদ্ধতির সারি না থাকলেও (যেমন গ্রাহকের জমার দাবিতে
     * কেবল `bank`/`mfs`/`cash` শব্দটা থাকে)।
     */
    public function fitsKind(Account $account, ?string $kind): bool
    {
        if (in_array($kind, self::UNCHECKED, true)) {
            return true;
        }

        return match ($kind) {
            'cash' => $account->isCash(),
            'mfs' => $account->isMfs(),
            // ⛔ ব্যাংক মানে ব্যাংক — MFS নয় ([[Account::isBank]])
            'bank' => $account->isBank(),
            // ⚠️ বদ্ধ তালিকার বাইরের ধরন — নিঃশব্দে ছাড় নয়
            default => false,
        };
    }

    /**
     * না মিললে থামানো — বার্তা পদ্ধতি আর খাত দুইটারই নাম বলে।
     *
     * @param  string  $field  ত্রুটি কোন ঘরে দেখাবে — সেটিংসে `account_id`,
     *                         কাউন্টারে `payment_method_id`, সরাসরি বিক্রয়ে
     *                         `deposits.N.account_id`
     *
     * @throws ValidationException
     */
    public function assert(Account $account, ?PaymentMethod $method, string $field = 'account_id'): void
    {
        if ($this->fits($account, $method)) {
            return;
        }

        throw ValidationException::withMessages([
            $field => $this->message($account, $method->name(), (string) $method->kind),
        ]);
    }

    /**
     * ব্যবহারকারীর ভাষায় বার্তা — ধরনের নামটা লেবেল থেকে, কাঁচা কোড নয়।
     */
    public function message(Account $account, string $methodName, string $kind): string
    {
        $label = 'master_data::payment_kind.'.$kind;

        return __('master_data::validation.method_does_not_fit_account', [
            'method' => $methodName,
            'kind' => __($label) === $label ? $kind : __($label),
            'account' => $account->label(),
        ]);
    }
}
