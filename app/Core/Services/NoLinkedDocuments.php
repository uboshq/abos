<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Contracts\LinkedDocuments;
use App\Models\User;

/**
 * ডকুমেন্ট মডিউল বন্ধ থাকলে জোড়া কাগজের উত্তর — কিছুই নেই, আর সেটাই সত্যি ([[LinkedDocuments]])।
 */
final class NoLinkedDocuments implements LinkedDocuments
{
    public function forRecord(string $sourceType, int $sourceId, User $user): array
    {
        return [];
    }
}
