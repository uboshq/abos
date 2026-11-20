<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Contracts\OldReceiptPapers;

/**
 * ⓘ কোনো মডিউল আগের টাকা-ঢোকার কাগজ না দিলে — রসিদের তালিকায় বাড়তি কিছু নেই ([[OldReceiptPapers]])।
 */
final class NoOldReceiptPapers implements OldReceiptPapers
{
    public function forReceiptList(array $filters, ?string $search, int $limit = 20): array
    {
        return ['rows' => [], 'count' => 0, 'total' => '0', 'more_url' => null];
    }
}
