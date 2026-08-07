<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Services;

use App\Core\Engines\NumberSeries\NumberSeriesEngine;
use App\Core\Support\CompanyContext;
use App\Core\Support\DocumentStatus;
use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\CashTill;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * নগদ কাউন্টার তৈরি ও রক্ষণাবেক্ষণ।
 *
 * প্রতিটা টিলের সাথে একটা হিসাব-খাত জন্মায়, "১১০১ হাতে নগদ"-এর নিচে।
 * দুইটা আলাদা করে বানাতে দিলে কেউ খাত ছাড়া টিল বানাত, আর তখন ওই টিলের
 * টাকা লেজারে কোথাও থাকত না।
 */
final class CashTillService
{
    public function __construct(
        private readonly AccountService $accounts,
        private readonly NumberSeriesEngine $numbers,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): CashTill
    {
        return DB::transaction(function () use ($data) {
            $parent = $this->cashParent();

            /*
             * কোড না দিলে সিরিজ থেকে — মালিকের নির্দেশ (২০২৬-০৮-০৭)।
             *
             * টিলের কোডটা নিচে খাতের কোডেও বসে ("১১০১-TIL-…"), তাই
             * অটো কোড হলে ছকেও একই পরিচয়ই যায় — দুই পর্দায় এক জিনিস
             * এক নামে।
             */
            $code = trim((string) ($data['code'] ?? ''));
            $code = $code !== '' ? $code : $this->numbers->next('TIL');

            $this->assertCodeIsFree($code);

            /*
             * খাতের কোড টিলের কোড থেকেই — "১১০১-CASH01"।
             *
             * নিজে থেকে একটা ক্রমিক সংখ্যা দিলে হিসাবরক্ষক ছকে গিয়ে
             * বুঝত না কোন খাতটা কোন টিলের। কোডটা মিলিয়ে দিলে দুই
             * পর্দাতেই একই জিনিস একই নামে চেনা যায়।
             */
            $account = $this->accounts->create([
                'code' => $parent->code.'-'.$code,
                'name_en' => $data['name_en'],
                'name_bn' => $data['name_bn'] ?? null,
                'parent_id' => $parent->id,
                'is_cash' => true,
                'opening_balance' => $data['opening_balance'] ?? 0,
                'opening_date' => $data['opening_date'] ?? null,
            ]);

            $till = CashTill::create([
                ...$data,
                'code' => $code,
                'account_id' => $account->id,
                'branch_id' => $data['branch_id'] ?? CompanyContext::branchId(),
                'limit_amount' => $data['limit_amount'] ?? 0,
                'is_primary' => false,
                'is_active' => $data['is_active'] ?? true,
                'status' => DocumentStatus::CONFIRMED,
                'created_by' => auth()->id(),
            ]);

            // প্রধান টিল আলাদা করে বসানো হয়, কারণ একটাই থাকতে পারে
            if ($data['is_primary'] ?? false) {
                $this->makePrimary($till);
            }

            return $till->fresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(CashTill $till, array $data): CashTill
    {
        return DB::transaction(function () use ($till, $data) {
            if (isset($data['code']) && trim((string) $data['code']) !== $till->code) {
                $this->assertCodeIsFree(trim((string) $data['code']), $till->id);
            }

            // খোলা ব্যালেন্স খাতে বসে, আর সেটা তৈরির পর বদলায় না —
            // AccountService একই নিয়ম মানে, একই কারণে।
            unset($data['opening_balance'], $data['opening_date'], $data['account_id']);

            $wantsPrimary = (bool) ($data['is_primary'] ?? $till->is_primary);
            unset($data['is_primary']);

            $till->update($data);

            // নাম বদলালে খাতের নামও — নাহলে ছকে পুরনো নাম আর টিলের
            // পর্দায় নতুন নাম, একই জিনিসের দুই পরিচয়
            if ($till->wasChanged(['name_en', 'name_bn'])) {
                $this->accounts->update($till->account, [
                    'name_en' => $till->name_en,
                    'name_bn' => $till->name_bn,
                ]);
            }

            if ($wantsPrimary && ! $till->is_primary) {
                $this->makePrimary($till);
            }

            return $till->fresh();
        });
    }

    /**
     * প্রধান টিল ঠিক করা — একটাই থাকতে পারে।
     *
     * আগেরটা নামিয়ে তারপর নতুনটা তোলা হয়, একই ট্রানজেকশনে: উল্টো ক্রমে
     * করলে মাঝখানে এক মুহূর্তের জন্য দুইটা প্রধান টিল থাকত, আর ঠিক তখন
     * কেউ জমা দিলে টাকাটা কোনটায় যাবে তা নির্ধারিত হত না।
     */
    public function makePrimary(CashTill $till): CashTill
    {
        return DB::transaction(function () use ($till) {
            CashTill::query()
                ->primary()
                ->whereKeyNot($till->id)
                ->get()
                ->each(fn (CashTill $other) => $other->forceFill(['is_primary' => false])->save());

            $till->refresh()->forceFill(['is_primary' => true, 'is_active' => true])->save();

            return $till->fresh();
        });
    }

    /**
     * নিষ্ক্রিয় করা — মোছা নয় (নিয়ম ৫)।
     *
     * টাকা হাতে থাকা অবস্থায় বন্ধ করা যায় না। করতে দিলে ওই টাকাটা
     * কারও হিসাবেই থাকত না — খাতটা থেকে যেত, কিন্তু পর্দায় আর দেখা
     * যেত না, আর মিলাতে গিয়ে কেউ বুঝত না টাকাটা কোথায়।
     */
    public function deactivate(CashTill $till): CashTill
    {
        if (bccomp($till->balance(), '0', 4) !== 0) {
            throw ValidationException::withMessages([
                'is_active' => __('accounts::validation.till_has_money', [
                    'amount' => number_format((float) $till->balance(), 2),
                ]),
            ]);
        }

        if ($till->is_primary) {
            throw ValidationException::withMessages([
                'is_active' => __('accounts::validation.primary_till_cannot_close'),
            ]);
        }

        return DB::transaction(function () use ($till) {
            $till->refresh()->forceFill(['is_active' => false])->save();

            // খাতটাও নিষ্ক্রিয় — নাহলে ভাউচারের ড্রপডাউনে বন্ধ টিলের খাত
            // দেখা যেত আর কেউ ওখানে টাকা বসিয়ে দিত
            $this->accounts->deactivate($till->account);

            return $till->fresh();
        });
    }

    /**
     * প্রথম টিল — প্রমিত ছক বসানোর সাথে সাথেই।
     *
     * ছক বসিয়ে টিল না বানালে "১১০১ হাতে নগদ" একটা গ্রুপ হয়ে খালি পড়ে
     * থাকত, আর প্রথম আদায় ভাউচারটা লিখতে গিয়ে ব্যবহারকারী দেখত কোনো
     * নগদ খাতই বাছার নেই।
     */
    public function ensurePrimaryTill(string $nameEn = 'Main Cash', string $nameBn = 'প্রধান নগদ'): CashTill
    {
        $existing = CashTill::query()->primary()->first();

        if ($existing !== null) {
            return $existing;
        }

        return $this->create([
            'code' => 'CASH',
            'name_en' => $nameEn,
            'name_bn' => $nameBn,
            'is_primary' => true,
        ]);
    }

    private function cashParent(): Account
    {
        $parent = StandardChart::find(StandardChart::CASH_IN_HAND);

        if ($parent === null) {
            throw ValidationException::withMessages([
                'account_id' => __('accounts::validation.cash_group_missing', [
                    'code' => StandardChart::CASH_IN_HAND,
                ]),
            ]);
        }

        return $parent;
    }

    private function assertCodeIsFree(string $code, ?int $exceptId = null): void
    {
        $taken = CashTill::query()
            ->where('code', $code)
            ->when($exceptId, fn ($q, $id) => $q->whereKeyNot($id))
            ->withTrashed()
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([
                'code' => __('accounts::validation.till_code_taken', ['code' => $code]),
            ]);
        }
    }
}
