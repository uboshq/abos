<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Contracts\CapitalisesABillLine;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * ক্রয় বন্ধ — বিলের কোনো সারি নেই, তোলারও কিছু নেই ([[CapitalisesABillLine]])।
 */
final class NoCapitalisesABillLine implements CapitalisesABillLine
{
    public function lines(?string $term = null, int $limit = 50): array
    {
        return [];
    }

    public function line(int $lineId): ?array
    {
        return null;
    }

    public function capitalise(int $lineId, string $qty, int $assetAccountId, Carbon $on, string $narration): string
    {
        throw ValidationException::withMessages(['purchase_bill_line_id' => __('accounts::asset.bill_line_missing')]);
    }
}
