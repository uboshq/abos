<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Imports;

use App\Core\Contracts\Importer;
use App\Core\Contracts\ImportNeedsKeys;
use App\Core\Contracts\RefusesAPartialImport;
use App\Modules\Accounts\Models\AssetCategory;
use App\Modules\Accounts\Services\FixedAssetService;
use Illuminate\Support\Carbon;

/**
 * ⭐ ABOS-এর আগে কেনা সম্পদের তালিকা — এক ফাইলে (স্থায়ী সম্পদ ধাপ ১, মালিক, ১০ অক্টোবর ২০২৬)।
 *
 * ⓘ প্রতিটা সারি একটা সম্পদ: শ্রেণির কোড, নাম, কেনার তারিখ, দাম আর এ পর্যন্ত ক্ষয় — শ্রেণি বাকিটা (খাত, আয়ু) দেয়।
 * দাম বসে পুরনো খাতার জেরে (সম্পদ ডেবিট / সঞ্চিত মুনাফা ক্রেডিট), এ পর্যন্ত ক্ষয় সঞ্চিত মুনাফা থেকে সঞ্চিত অবচয়ে —
 * আজকের খরচে নয় ([[FixedAssetService::register()]] `opening`)।
 * ⛔ নিজের চাবি (`accounts.asset.import`) আর নিজের সই ([[AccountsSignature::FIXED_ASSET_OPENING]]) — রোজকার "সম্পদ যোগ"-এর
 * চাবিতে কেউ শত সম্পদের পুরনো জের একবারে বসাতে পারেন না। ⓘ একটা সারি ভুল হলে পুরো ফাইল ফেরে — অর্ধেক তালিকা খাতায় নয়।
 */
final class FixedAssetOpeningImporter implements Importer, ImportNeedsKeys, RefusesAPartialImport
{
    public function __construct(private readonly FixedAssetService $assets) {}

    public static function requiredPermissions(): array
    {
        return ['accounts.asset.import'];
    }

    public static function label(): string
    {
        return 'accounts::asset.import_label';
    }

    public static function columns(): array
    {
        return [
            'category_code' => ['label' => 'accounts::asset.category', 'required' => true],
            'name' => ['label' => 'accounts::asset.name', 'required' => true],
            'tag_no' => ['label' => 'accounts::asset.tag_no', 'required' => false],
            'acquired_on' => ['label' => 'accounts::asset.acquired_on', 'required' => true],
            'cost' => ['label' => 'accounts::asset.cost', 'required' => true],
            'accumulated' => ['label' => 'accounts::asset.opening_accumulated', 'required' => false],
            'salvage' => ['label' => 'accounts::asset.salvage', 'required' => false],
            'location' => ['label' => 'accounts::asset.location', 'required' => false],
            'serial_no' => ['label' => 'accounts::asset.serial_no', 'required' => false],
        ];
    }

    public function check(array $row): array
    {
        $errors = [];

        if ($this->category($row['category_code'] ?? '') === null) {
            $errors[] = __('core.import.unknown_value', ['column' => 'category_code', 'value' => $row['category_code'] ?? '']);
        }

        if (blank($row['name'] ?? null)) {
            $errors[] = __('accounts::asset.import_name_missing');
        }

        if ($this->date($row['acquired_on'] ?? '') === null) {
            $errors[] = __('core.import.not_a_date', ['column' => 'acquired_on']);
        }

        foreach (['cost', 'accumulated', 'salvage'] as $column) {
            $value = trim((string) ($row[$column] ?? ''));

            if (($column === 'cost' || $value !== '') && (! is_numeric($value) || bccomp($value, '0', 4) < 0)) {
                $errors[] = __('core.import.not_a_number', ['column' => $column]);
            }
        }

        if (is_numeric($row['cost'] ?? null) && is_numeric($row['accumulated'] ?? null)
            && bccomp((string) $row['accumulated'], (string) $row['cost'], 4) > 0) {
            $errors[] = __('accounts::asset.import_accumulated_over_cost');
        }

        return $errors;
    }

    public function import(array $row): void
    {
        $category = $this->category($row['category_code'] ?? '');

        if ($category === null) {
            return;
        }

        $this->assets->register([
            'category_id' => $category->id,
            'name' => trim((string) $row['name']),
            'tag_no' => ($row['tag_no'] ?? null) ?: null,
            'acquired_on' => $this->date((string) $row['acquired_on'])?->toDateString(),
            'cost' => (string) $row['cost'],
            'salvage' => filled($row['salvage'] ?? null) ? (string) $row['salvage'] : null,
            'location' => ($row['location'] ?? null) ?: null,
            'serial_no' => ($row['serial_no'] ?? null) ?: null,
            'funded_by' => FixedAssetService::FUNDED_OPENING,
            'opening_accumulated' => filled($row['accumulated'] ?? null) ? (string) $row['accumulated'] : '0',
        ]);
    }

    public function refusalNotice(): string
    {
        return __('accounts::asset.import_refused');
    }

    private function category(string $code): ?AssetCategory
    {
        $code = trim($code);

        return $code === '' ? null : AssetCategory::query()->active()->where('code', $code)->first();
    }

    private function date(string $value): ?Carbon
    {
        $value = trim($value);

        foreach (['d/m/Y', 'd-m-Y', 'Y-m-d', 'd.m.Y'] as $format) {
            try {
                $parsed = Carbon::createFromFormat($format, $value);
            } catch (\Throwable) {
                continue;
            }

            if ($parsed !== false && $parsed->format($format) === $value) {
                return $parsed->startOfDay();
            }
        }

        return null;
    }
}
