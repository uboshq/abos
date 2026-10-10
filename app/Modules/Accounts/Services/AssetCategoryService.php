<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Services;

use App\Modules\Accounts\Models\Account;
use App\Modules\Accounts\Models\AssetCategory;
use App\Modules\Accounts\Models\FixedAsset;
use Illuminate\Validation\ValidationException;

/**
 * ⭐ সম্পদের শ্রেণি বানানো আর বদলানো — খাতগুলো ছক থেকে, ঠিক ধরনের (স্থায়ী সম্পদ ধাপ ১, ১০ অক্টোবর ২০২৬)।
 *
 * ⛔ খাতের ধরন মেলানো বাধ্যতামূলক: দামের খাত সম্পদ, সঞ্চিত অবচয় সম্পদের (উল্টো প্রকৃতির) খাত, অবচয় খরচ খরচের,
 * বিক্রির লাভ আয়ের, লোকসান আর অবমূল্যায়ন খরচের। ⚠️ ভুল ধরনে বসলে স্থিতিপত্রে সম্পদ লাভ-ক্ষতিতে চলে যেত, আর
 * অবচয় সম্পদ কমানোর বদলে দায় বাড়াত — দেখতে সব ঠিক, খাতা মিলত, অথচ দুইটা প্রতিবেদনই ভুল।
 * ⓘ টাকার খাত (নগদ/ব্যাংক) কখনো দামের খাত নয়; গ্রুপ খাতে দাখিলা বসে না, তাই কেবল পোস্টযোগ্য খাত।
 */
final class AssetCategoryService
{
    /** ঘর → খাতের যে ধরন চাই; `null` মানে ঘরটা খালি রাখা যায় */
    private const ACCOUNT_KINDS = [
        'asset_account_id' => [Account::ASSET, true],
        'accumulated_account_id' => [Account::ASSET, true],
        'expense_account_id' => [Account::EXPENSE, true],
        'gain_account_id' => [Account::INCOME, false],
        'loss_account_id' => [Account::EXPENSE, false],
        'impairment_account_id' => [Account::EXPENSE, false],
    ];

    /** @param  array<string, mixed>  $data */
    public function create(array $data): AssetCategory
    {
        return AssetCategory::query()->create([...$this->checked($data), 'created_by' => auth()->id()]);
    }

    /** @param  array<string, mixed>  $data */
    public function update(AssetCategory $category, array $data): AssetCategory
    {
        $category->update($this->checked($data, $category));

        return $category->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function checked(array $data, ?AssetCategory $current = null): array
    {
        $errors = [];

        foreach (self::ACCOUNT_KINDS as $field => [$type, $required]) {
            $id = $data[$field] ?? null;

            if (blank($id)) {
                if ($required) {
                    $errors[$field] = __('accounts::asset.category_account_required');
                }

                $data[$field] = null;

                continue;
            }

            $account = Account::query()->postable()->whereKey((int) $id)->first();

            if ($account === null || $account->type !== $type || ($field === 'asset_account_id' && $account->money_kind !== null)) {
                $errors[$field] = __('accounts::asset.category_account_wrong', ['kind' => __('accounts::asset.kind_'.$type)]);
            }
        }

        $method = (string) ($data['method'] ?? FixedAsset::STRAIGHT_LINE);

        if (! in_array($method, FixedAsset::METHODS, true)) {
            $errors['method'] = __('accounts::asset.method_unknown');
        }

        if ($method === FixedAsset::STRAIGHT_LINE && (int) ($data['life_months'] ?? 0) <= 0) {
            $errors['life_months'] = __('accounts::asset.life_required');
        }

        if ($method === FixedAsset::REDUCING && bccomp((string) ($data['rate'] ?? '0'), '0', 4) <= 0) {
            $errors['rate'] = __('accounts::asset.rate_required');
        }

        $residual = (string) ($data['residual_percent'] ?? '0');

        if (! is_numeric($residual) || bccomp($residual, '0', 4) < 0 || bccomp($residual, '100', 4) >= 0) {
            $errors['residual_percent'] = __('accounts::asset.residual_out_of_range');
        }

        $taken = AssetCategory::query()->where('code', (string) ($data['code'] ?? ''))
            ->when($current !== null, fn ($q) => $q->whereKeyNot($current->id))->exists();

        if ($taken) {
            $errors['code'] = __('accounts::asset.category_code_taken');
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'code' => (string) $data['code'],
            'name_en' => (string) $data['name_en'],
            'name_bn' => ($data['name_bn'] ?? null) ?: null,
            'asset_account_id' => (int) $data['asset_account_id'],
            'accumulated_account_id' => (int) $data['accumulated_account_id'],
            'expense_account_id' => (int) $data['expense_account_id'],
            'gain_account_id' => $data['gain_account_id'] === null ? null : (int) $data['gain_account_id'],
            'loss_account_id' => $data['loss_account_id'] === null ? null : (int) $data['loss_account_id'],
            'impairment_account_id' => $data['impairment_account_id'] === null ? null : (int) $data['impairment_account_id'],
            'method' => $method,
            'life_months' => blank($data['life_months'] ?? null) ? null : (int) $data['life_months'],
            'rate' => blank($data['rate'] ?? null) ? null : (string) $data['rate'],
            'residual_percent' => $residual,
            'capitalisation_threshold' => blank($data['capitalisation_threshold'] ?? null) ? null : (string) $data['capitalisation_threshold'],
            'is_active' => (bool) ($data['is_active'] ?? true),
        ];
    }
}
