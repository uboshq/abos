<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Accounts;

use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Models\Company;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\CashTill;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\StandardChart;
use App\Modules\Customer\Models\Customer;
use App\Modules\Supplier\Models\Supplier;
use Database\Seeders\DemoSeeder;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ভাউচারের ফর্ম যা দেখায়, ঠিক তাই পাঠায় — আর সার্ভার সেটাই মানে।
 *
 * ── ⛔ লাইভে TCL-এ ধরা পড়েছিল (hp2, ২৭ সেপ্টেম্বর ২০২৬) ──────────────
 *   ১. পরিশোধ ভাউচার **কখনো পোস্ট হয় না** — "যে খাত থেকে" ঘরটা লুকানো ও
 *      নিষ্ক্রিয়, তাই ব্রাউজার পাঠায়ই না, আর বার্তা আসে "যে খাত থেকে
 *      দিতেই হবে"।
 *   ২. রসিদে "নগদ" বাছলেও তালিকায় কেবল ব্যাংক/বিকাশ; নগদ মূলধন বসল
 *      বিকাশে, আর কারণ কোথাও লেখা নেই।
 *   ৩. ব্যাংক বাছলে লেনদেন-নম্বরের ঘর আসে না; আর ভুল নিয়ে ফর্ম ফিরলে
 *      মাধ্যমটা আবার "নগদ"।
 *
 * ── ⭐ কেন পরীক্ষাটা পাতা পড়ে পাঠায় ──────────────────────────────────
 * আগের পরীক্ষাগুলো (`VoucherTest`) `from_account_id` **নিজে লিখে** পাঠাত
 * — যে ঘরটা আসল ফর্ম কখনো পাঠায় না। ⛔ তাই ভাঙা পর্দাটা সবুজ দেখাত।
 * ⓘ এখানে পাতাটা আঁকা হয়, আর ব্রাউজার যা পাঠাত কেবল সেই ঘরগুলো নেওয়া
 * হয়: `<template>`-এর ভিতরের, নিষ্ক্রিয়, বা `hidden`-এর ভিতরের ঘর বাদ।
 *
 * ── ⓘ মালিকের নিয়ম অক্ষত ─────────────────────────────────────────────
 * *"cash e sudu tar nijer cash accounts e taka nite parbe"* (২১ সেপ্টেম্বর)।
 * ⚠️ এখানে নিয়মটা ঢিলে হয় না — কেবল পর্দা কারণটা আগে বলে, আর নিজের নামে
 * বসানো অফিস-নগদ (`held_by`) নিজের বাক্স হিসেবে গোনা হয়।
 */
final class TheVoucherFormSendsWhatItShowsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private Account $bank;

    private Account $mfs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);

        $this->company = Company::query()->where('code', 'TDEPOT')->firstOrFail();
        CompanyContext::set($this->company->id, $this->company->defaultBranch()?->id);

        app(StandardChart::class)->install();

        $this->owner = User::query()->where('email', 'owner@abos.test')->firstOrFail();
        $this->actingAs($this->owner);

        $this->bank = $this->moneyAccount('1102-FORM', StandardChart::BANK, Account::BANK);
        $this->mfs = $this->moneyAccount('1105-FORM', '1105', Account::MFS);
    }

    // ── ১ · পরিশোধ ─────────────────────────────────────────────────────

    public function test_the_payment_form_sends_the_account_the_money_leaves(): void
    {
        $fields = $this->formSends('payment');

        $this->assertArrayHasKey('from_account_id', $fields,
            '⛔ পরিশোধের ফর্ম "কোথা থেকে দেওয়া হল" ঘরটা পাঠায় না — ঘরটা লুকানো বা নিষ্ক্রিয়, '
            .'আর সার্ভার তাই প্রতিটা পরিশোধে "যে খাত থেকে দিতেই হবে" বলে।');
    }

    public function test_a_supplier_payment_posts_with_exactly_what_the_form_sends(): void
    {
        $supplier = Supplier::query()->firstOrFail();

        $this->submit('payment', [
            'party_type' => 'supplier',
            'party_id' => (string) $supplier->id,
            'from_account_id' => (string) $this->bank->id,
            'instrument' => 'transfer',
            'instrument_no' => 'NPSB-7001',
            'amount' => '700',
        ])->assertSessionHasNoErrors();

        $voucher = Voucher::query()->where('type', Voucher::PAYMENT)->latest('id')->firstOrFail();

        $this->assertSame(DocumentStatus::CONFIRMED, $voucher->status, 'পরিশোধটা খাতায় বসেনি।');

        $credit = $voucher->lines->first(fn ($l) => bccomp((string) $l->credit, '0', 4) > 0);
        $debit = $voucher->lines->first(fn ($l) => bccomp((string) $l->debit, '0', 4) > 0);

        $this->assertSame($this->bank->id, (int) $credit->account_id, 'টাকাটা বাছা ব্যাংক থেকে যায়নি।');

        /*
         * ⛔ আগে সার্ভার সরবরাহকারীর পরিশোধে উল্টো দিকে ২১১০ বসাত — একটা
         * **গ্রুপ** খাত, যাতে দাখিলাই বসে না। ⭐ দেনা কমে পোস্টযোগ্য ২১১১-এ।
         */
        $this->assertSame(StandardChart::PAYABLE, $debit->account->code,
            'সরবরাহকারীর পরিশোধে দেনা পোস্টযোগ্য সরবরাহকারী-খাতে (২১১১) কমেনি।');

        /*
         * ⓘ পক্ষের নাম ভাউচারের সারিতে নয়, **খতিয়ানে** বসে — পোস্টের সময়,
         * কেবল পাওনা-দেনার খাতে। ⚠️ বকেয়া খতিয়ান থেকেই গোনা হয়, তাই প্রশ্নটা
         * ওখানেই: কোন সরবরাহকারীর দেনা কমল।
         */
        $entry = LedgerEntry::query()
            ->where('account_id', $debit->account_id)
            ->where('source_type', Voucher::SOURCE_TYPES[Voucher::PAYMENT])
            ->where('source_id', $voucher->id)
            ->where('debit', '>', 0)
            ->first();

        $this->assertNotNull($entry, 'খতিয়ানে সরবরাহকারীর দেনা কমার দাখিলাই নেই।');
        $this->assertSame('supplier', $entry->party_type, 'দেনা কমেছে, কিন্তু কার — খতিয়ানে লেখা নেই।');
        $this->assertSame($supplier->id, (int) $entry->party_id);
    }

    // ── ২ · মাধ্যম আর খাতের মিল ────────────────────────────────────────

    public function test_cash_chosen_with_an_mfs_account_is_refused(): void
    {
        /* ⛔ লাইভের ঘটনাটাই: "নগদ" বাছা, টাকা বসল বিকাশে */
        $this->submit('receipt', $this->customerReceipt([
            'instrument' => 'cash',
            'to_account_id' => (string) $this->mfs->id,
        ]))->assertSessionHasErrors('instrument');

        $this->assertSame(0, Voucher::query()->where('type', Voucher::RECEIPT)
            ->where('status', DocumentStatus::CONFIRMED)->where('money_account_id', $this->mfs->id)->count(),
            '"নগদ" বলে বিকাশ খাতে টাকা বসে গেছে।');
    }

    public function test_the_same_receipt_by_mfs_into_the_mfs_account_goes_through(): void
    {
        /* ⓘ পাল্টা দাবি — পাহারা যেন সব রসিদ না আটকায় */
        $this->submit('receipt', $this->customerReceipt([
            'instrument' => 'mfs',
            'to_account_id' => (string) $this->mfs->id,
            'instrument_no' => 'BK-1002',
        ]))->assertSessionHasNoErrors();

        $this->assertSame(1, Voucher::query()->where('type', Voucher::RECEIPT)
            ->where('status', DocumentStatus::CONFIRMED)->where('money_account_id', $this->mfs->id)->count());
    }

    // ── ৩ · নগদ কেবল নিজের বাক্সে — আর পর্দা কারণ বলে ─────────────────

    public function test_someone_without_a_till_is_told_why_before_saving(): void
    {
        $other = $this->otherUser();
        $this->till($this->cashAccount('1101-THEIRS'), $other->id);

        $html = (string) $this->get(route('accounts.voucher.create', ['type' => 'receipt']))
            ->assertOk()->getContent();

        $this->assertStringContainsString(e(__('accounts::validation.no_till_of_your_own')), $html,
            '⛔ নগদ খাত লুকানো, অথচ পর্দা কারণ বলে না — ব্যবহারকারী জানেন না কেন "নগদ" বাছা যায় না।');
    }

    public function test_office_cash_in_my_name_is_offered_and_takes_money(): void
    {
        $other = $this->otherUser();
        $this->till($this->cashAccount('1101-THEIRS'), $other->id);

        $mine = $this->cashAccount('1101-MINE');
        $mine->forceFill(['held_by' => $this->owner->id])->save();

        $offered = $this->optionsOf('receipt', 'to_account_id');

        $this->assertContains((string) $mine->id, $offered,
            '⛔ আমার নামে বসানো অফিস-নগদ (`held_by`) আমাকেই দেখানো হয়নি। তালিকায় এসেছে: '
            .implode(', ', $offered).' · আমার খাত: '.$mine->id);

        $this->submit('receipt', $this->customerReceipt([
            'instrument' => 'cash',
            'to_account_id' => (string) $mine->id,
            'amount' => '500',
        ]))->assertSessionHasNoErrors();
    }

    public function test_office_cash_in_someone_elses_name_is_neither_offered_nor_taken(): void
    {
        $other = $this->otherUser();
        $this->till($this->cashAccount('1101-TILL'), $other->id);

        $theirs = $this->cashAccount('1101-OFFICE-THEIRS');
        $theirs->forceFill(['held_by' => $other->id])->save();

        $this->assertNotContains((string) $theirs->id, $this->optionsOf('receipt', 'to_account_id'),
            'অন্যের নামের অফিস-নগদ আমার তালিকায় এসেছে।');

        /* ⚠️ বিপজ্জনক ইনপুট: তালিকায় না থাকলেও হাতে পাঠানো */
        $this->post(route('accounts.voucher.store', ['type' => 'receipt']), $this->customerReceipt([
            'instrument' => 'cash',
            'to_account_id' => (string) $theirs->id,
            'amount' => '500',
        ], trusted: true))->assertSessionHasErrors();

        $this->assertSame(0, Voucher::query()->where('status', DocumentStatus::CONFIRMED)
            ->whereHas('lines', fn ($q) => $q->where('account_id', $theirs->id))->count(),
            '⛔ অন্যের নামের নগদ খাতে টাকা বসে গেছে।');
    }

    // ── ৪ · মাধ্যমের ব্লক ভুলের পরেও মনে রাখে, আর খাতের ধরন জানে ──────

    public function test_the_money_block_remembers_the_chosen_way_after_a_bounce(): void
    {
        $html = (string) $this->withSession(['_old_input' => ['instrument' => 'transfer', 'charge_amount' => '15']])
            ->get(route('accounts.voucher.create', ['type' => 'receipt']))
            ->assertOk()->getContent();

        preg_match('/x-data="(moneyMovement\([^"]*)"/', $html, $block);

        $this->assertNotEmpty($block, 'পাতায় মাধ্যমের ব্লকটাই নেই।');

        $this->assertMatchesRegularExpression('/method:\s*(&quot;|&#039;|["\'])transfer\1/', $block[1],
            '⛔ ভুল নিয়ে ফিরলে মাধ্যমের ব্লক আবার "নগদ"-এ শুরু করে — বাছা "ট্রান্সফার" আর লেনদেন-নম্বর হারায়। '
            .'পাতায় আছে: '.$block[1]);
    }

    public function test_every_money_account_option_says_what_kind_it_is(): void
    {
        /*
         * ⓘ খাতের ধরনটাই বলে লেনদেন-নম্বর লাগবে কি না। ⚠️ option-এ ধরন না
         * থাকলে পর্দা ব্যাংক বাছার পরেও নম্বরের ঘর আনতে পারে না।
         */
        foreach (['receipt' => 'to_account_id', 'payment' => 'from_account_id'] as $type => $name) {
            $kinds = $this->optionKinds($type, $name);

            $this->assertSame(Account::BANK, $kinds[(string) $this->bank->id] ?? null,
                "{$type}: ব্যাংক খাতের option ধরন (data-kind) বলে না।");
            $this->assertSame(Account::MFS, $kinds[(string) $this->mfs->id] ?? null,
                "{$type}: MFS খাতের option ধরন (data-kind) বলে না।");
        }
    }

    // ── প্রস্তুতি ────────────────────────────────────────────────────────

    /**
     * ব্রাউজার এই পাতা থেকে কোন ঘরগুলো পাঠাত, আর কোন মানে।
     *
     * ⓘ বাদ: `<template>`-এর ভিতরের (Alpine না বসালে ওগুলো পাতায় নেই),
     * নিষ্ক্রিয়, `hidden`-এর ভিতরের, আর বোতাম। রেডিও ও চেকবক্স কেবল টিক
     * দেওয়াটা।
     *
     * @return array<string, string>
     */
    private function formSends(string $type, string $method = 'cash'): array
    {
        $xpath = $this->formDom($type);
        $fields = [];

        foreach ($xpath->query('//form[@method="POST" or @method="post"]//*[self::input or self::select or self::textarea][@name]') as $el) {
            /** @var DOMElement $el */
            if ($this->skipped($el, $method)) {
                continue;
            }

            $name = $el->getAttribute('name');
            $tag = strtolower($el->tagName);
            $kind = strtolower($el->getAttribute('type'));

            if ($tag === 'input' && in_array($kind, ['submit', 'button', 'file'], true)) {
                continue;
            }

            if ($tag === 'input' && in_array($kind, ['radio', 'checkbox'], true)) {
                if ($el->hasAttribute('checked')) {
                    $fields[$name] = $el->getAttribute('value');
                }

                continue;
            }

            if ($tag === 'select') {
                $selected = $xpath->query('.//option[@selected]', $el)->item(0)
                    ?? $xpath->query('.//option', $el)->item(0);
                $fields[$name] = $selected instanceof DOMElement ? $selected->getAttribute('value') : '';

                continue;
            }

            $fields[$name] = $tag === 'textarea' ? $el->textContent : $el->getAttribute('value');
        }

        return $fields;
    }

    /**
     * ⓘ মাধ্যমের ব্লক (`<template x-if="method === 'mfs'">`) ব্রাউজারে তখনই
     * পাতায় বসে যখন ব্যবহারকারী ঐ চিপটা বাছেন — তাই বাছা মাধ্যমের ব্লক
     * গোনা হয়, বাকি সব `<template>` বাদ। ⚠️ প্রথম চালে সব বাদ যাচ্ছিল, আর
     * দুইটা দাবি কোডের নয়, পরীক্ষার ভুলে লাল হয়েছিল।
     */
    private function skipped(DOMElement $el, ?string $method = null): bool
    {
        if ($el->hasAttribute('disabled')) {
            return true;
        }

        for ($node = $el->parentNode; $node instanceof DOMElement; $node = $node->parentNode) {
            if ($node->hasAttribute('hidden')) {
                return true;
            }

            if (strtolower($node->tagName) === 'template') {
                $shows = preg_replace('/\s+/', ' ', trim($node->getAttribute('x-if')));

                if ($method === null || $shows !== "method === '{$method}'") {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * ফর্ম যা পাঠাত, তার উপর ব্যবহারকারীর বাছাই — কেবল যে ঘরগুলো ফর্মে আছে।
     *
     * ⚠️ ফর্মে নেই এমন ঘর চাওয়া হলে দাবিটা সেখানেই লাল — কারণ আসল
     * ব্যবহারকারী ঐ ঘরে কিছু লিখতেই পারেন না।
     *
     * @param  array<string, string>  $choices
     */
    private function submit(string $type, array $choices): \Illuminate\Testing\TestResponse
    {
        $fields = $this->formSends($type, $choices['instrument'] ?? 'cash');

        foreach ($choices as $name => $value) {
            $radio = in_array($name, ['instrument'], true);

            $this->assertTrue($radio || array_key_exists($name, $fields),
                "⛔ {$type} ফর্মে '{$name}' ঘরটাই পাঠানো হয় না — ব্যবহারকারী এটা ভরতেই পারেন না।");

            $fields[$name] = $value;
        }

        // ⓘ তারিখের ঘর পাতায় ফাঁকা আসতে পারে — ব্যবহারকারী লেখেন, তাই পরীক্ষাও লেখে
        $fields['trx_date'] = ($fields['trx_date'] ?? '') !== '' ? $fields['trx_date'] : now()->toDateString();
        // ⓘ বিবরণ বাধ্যতামূলক (ভাউচারের পরিকল্পনা ৩খ, ৭ অক্টোবর ২০২৬) — ব্যবহারকারী লেখেন, তাই পরীক্ষাও লেখে
        $fields['narration'] = ($fields['narration'] ?? '') !== '' ? $fields['narration'] : 'পরীক্ষার বিবরণ';

        return $this->from(route('accounts.voucher.create', ['type' => $type]))
            ->post(route('accounts.voucher.store', ['type' => $type]), $fields);
    }

    /**
     * গ্রাহকের কাছ থেকে একটা রসিদ — ফর্মের ঘর ধরে।
     *
     * @param  array<string, string>  $choices
     * @return array<string, string>
     */
    private function customerReceipt(array $choices, bool $trusted = false): array
    {
        $customer = Customer::query()->firstOrFail();

        $base = [
            'party_type' => 'customer',
            'party_id' => (string) $customer->id,
            'amount' => '1000',
            'trx_date' => now()->toDateString(),
            'narration' => 'পরীক্ষার বিবরণ', // ⓘ বিবরণ বাধ্যতামূলক (৩খ)
        ];

        return $trusted ? ['type' => 'receipt', ...$base, ...$choices] : [...$base, ...$choices];
    }

    /**
     * ⚠️ `array_keys` সংখ্যার মতো দেখতে কী-কে int বানায় — তাই string-এ
     * ফেরানো; নাহলে তালিকায় থাকা খাতও কড়া তুলনায় "নেই" দেখাত।
     *
     * @return list<string>
     */
    private function optionsOf(string $type, string $name): array
    {
        return array_map('strval', array_keys($this->optionKinds($type, $name)));
    }

    /** @return array<string, string> option value → data-kind */
    private function optionKinds(string $type, string $name): array
    {
        $xpath = $this->formDom($type);
        $out = [];

        foreach ($xpath->query('//select[@name="'.$name.'"]') as $select) {
            /** @var DOMElement $select */
            if ($this->skipped($select)) {
                continue;
            }

            foreach ($xpath->query('.//option[@value!=""]', $select) as $option) {
                /** @var DOMElement $option */
                $out[$option->getAttribute('value')] = $option->getAttribute('data-kind');
            }
        }

        return $out;
    }

    private function formDom(string $type): DOMXPath
    {
        $html = (string) $this->get(route('accounts.voucher.create', ['type' => $type]))->assertOk()->getContent();

        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();

        return new DOMXPath($dom);
    }

    private function moneyAccount(string $code, string $parentCode, string $kind): Account
    {
        return Account::query()->create([
            'company_id' => CompanyContext::id(),
            'code' => $code,
            'name_en' => $code,
            'name_bn' => $code,
            'parent_id' => StandardChart::find($parentCode)->id,
            'type' => Account::ASSET,
            'nature' => Account::DEBIT,
            'money_kind' => $kind,
            'is_active' => true,
            'status' => DocumentStatus::CONFIRMED,
        ]);
    }

    private function cashAccount(string $code): Account
    {
        return $this->moneyAccount($code, StandardChart::CASH_IN_HAND, Account::CASH);
    }

    private function till(Account $account, int $holderId): CashTill
    {
        return CashTill::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->company->defaultBranch()?->id,
            'account_id' => $account->id,
            'code' => 'TILL-'.$account->code,
            'name_en' => 'Till '.$account->code,
            'holder_id' => $holderId,
            'is_active' => true,
        ]);
    }

    private function otherUser(): User
    {
        return User::query()->where('email', 'accounts@abos.test')->firstOrFail();
    }
}
