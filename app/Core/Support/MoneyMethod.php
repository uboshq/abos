<?php

declare(strict_types=1);

namespace App\Core\Support;

use App\Core\Support\CompanyContext;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ টাকা কীভাবে নড়ল — পদ্ধতির নিয়ম, এক জায়গায় (মালিক, ২১ অক্টোবর ২০২৬, fe-র মাধ্যমে; নকশা দফা #২, #৯)।
 *
 * ⓘ পর্দার অংশ [[components.money.method]]; এই ক্লাস তার সার্ভারের দিক — হিসাবের ভাউচার, সরবরাহকারী-পরিশোধ আর আদায়
 * তিনটাই একই নিয়ম পড়ে, তাই "ব্যাংকে TrxID লাগে" কথাটা এক পর্দায় সত্যি আর অন্যটায় মিথ্যা হতে পারে না।
 *
 *   নগদ          — কিছুই লাগে না
 *   MFS          — TrxID (`instrument_no`) পোস্টের জন্য
 *   ব্যাংক ট্রান্সফার — রেফারেন্স/TrxID (`instrument_no`) পোস্টের জন্য; মোড আর মূল্য-তারিখ ঐচ্ছিক
 *   নগদ জমা      — জমা স্লিপ (`deposit_slip_no`) পোস্টের জন্য
 *   চেক          — চেক নম্বর (`instrument_no`)
 *
 * ⚠️ খসড়ায় কিছুই বাধ্যতামূলক নয় — মানুষ আধা-ভরা কাগজ রেখে পরে ফেরেন; নিয়মটা কেবল পোস্টের মুহূর্তে ([[assertReadyToPost()]])।
 */
final class MoneyMethod
{
    /** নতুন পর্দার পাঁচটা পদ্ধতি, নকশার ক্রমে */
    public const METHODS = ['cash', 'mfs', 'transfer', 'deposit', 'cheque'];

    /** পুরনো কাগজে থাকতে পারে — যাচাই মানে, নতুন পর্দা দেখায় না */
    public const LEGACY = ['card'];

    /** যে পদ্ধতিতে চার্জ হয় — নগদে বা চেকে চার্জের ঘরই নেই */
    public const CHARGEABLE = ['mfs', 'transfer', 'deposit'];

    /**
     * যাচাইয়ের নিয়ম — হোস্ট ফর্মের নিজের নিয়মের সাথে মেশে।
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(string $chargeField = 'charge_amount'): array
    {
        return [
            'instrument' => ['nullable', 'string', Rule::in([...self::METHODS, ...self::LEGACY])],
            'instrument_no' => ['nullable', 'string', 'max:64'],
            'instrument_date' => ['nullable', 'date'],
            'from_bank' => ['nullable', 'string', 'max:120'],
            'transfer_mode_id' => ['nullable', 'integer',
                Rule::exists('mdm_transfer_modes', 'id')->where(fn ($q) => $q->where('company_id', CompanyContext::id())->orWhereNull('company_id'))],
            'value_date' => ['nullable', 'date'],
            'deposit_slip_no' => ['nullable', 'string', 'max:64'],
            $chargeField => ['nullable', 'numeric', 'min:0'],
        ];
    }

    /**
     * যে পদ্ধতির ঘর নয়, তার মান ফেলে দেওয়া — জমা দেওয়ার আগে।
     *
     * ⛔ কেউ "ব্যাংক ট্রান্সফার"-এ চার্জ লিখে পরে "নগদ" বাছলে চার্জটা লুকানো থেকে যেত আর খাতায় 5210-এ বসত; পর্দার
     * `x-if` ঘরটা পাঠায় না ঠিকই, কিন্তু ফোন বা পুরনো ফর্ম পাঠাতে পারে। সার্ভার তাই নিজেই ছাঁটে।
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function onlyItsOwn(array $data, string $chargeField = 'charge_amount'): array
    {
        /*
         * ⛔ পদ্ধতি না এলে কিছুই ছাঁটা নয় — পুরনো ডাক (প্রস্তাব, API, পুরনো পরিশোধ) পদ্ধতি ছাড়াই স্লিপ নম্বর পাঠায়।
         * ⓘ আগে খালি পদ্ধতিকে "নগদ" ধরে `instrument_no` মুছত: স্লিপ নীরবে হারাত, আর "একই স্লিপ দুবার নয়" পাহারা অন্ধ হত
         * ([[ASlipUsedTwiceThroughAnEditTest]], ২২ অক্টোবর ২০২৬)।
         */
        if (blank($data['instrument'] ?? null)) {
            return $data;
        }

        $method = (string) $data['instrument'];

        $keeps = match ($method) {
            'cash' => [],
            'mfs' => ['instrument_no', $chargeField],
            'transfer' => ['transfer_mode_id', 'instrument_no', 'value_date', $chargeField],
            'deposit' => ['deposit_slip_no', 'value_date', $chargeField],
            'cheque' => ['instrument_no', 'instrument_date', 'from_bank'],
            default => ['instrument_no', 'instrument_date', 'from_bank', $chargeField],
        };

        foreach (['instrument_no', 'instrument_date', 'from_bank', 'transfer_mode_id', 'value_date', 'deposit_slip_no', $chargeField] as $field) {
            if (array_key_exists($field, $data) && ! in_array($field, $keeps, true)) {
                $data[$field] = $field === $chargeField ? 0 : null;
            }
        }

        return $data;
    }

    /** কোন ঘরটা পোস্টের আগে ভরা চাই — না লাগলে `null` */
    public static function referenceField(?string $method): ?string
    {
        return match ($method) {
            'mfs', 'transfer', 'cheque' => 'instrument_no',
            'deposit' => 'deposit_slip_no',
            default => null,
        };
    }

    /**
     * ⛔ ব্যাংক বা MFS-এ রেফারেন্স ছাড়া পোস্ট নয় (নকশা #২: "রেফারেন্স/TrxID — পোস্টে বাধ্যতামূলক")।
     *
     * ⓘ রেফারেন্সই ব্যাংকের স্টেটমেন্টের সাথে মেলানোর একমাত্র সুতো, আর একই TrxID দুবার নেওয়া ঠেকানোর চাবি (#৭) — খালি
     * থাকলে দুটোই অন্ধ।
     *
     * @param  array<string, mixed>|object  $paper
     */
    public static function assertReadyToPost(array|object $paper): void
    {
        $read = fn (string $field) => is_array($paper) ? ($paper[$field] ?? null) : ($paper->{$field} ?? null);
        $method = $read('instrument');
        $field = self::referenceField(is_string($method) ? $method : null);

        if ($field === null || trim((string) $read($field)) !== '') {
            return;
        }

        throw ValidationException::withMessages([
            $field => __('money.validation.reference_needed', ['method' => __('money.method.'.$method)]),
        ]);
    }
}
