<?php

declare(strict_types=1);

namespace App\Core\Services;

use App\Core\Contracts\ParkedStock;

/**
 * [[ParkedStock]]-এর "কিছুই আটকে নেই" বাস্তবায়ন।
 *
 * ⓘ কাউন্টার না থাকলে কেউ বিল ধরে রাখে না, তাই ছাড়ার মতো কিছুও নেই। ⚠️ বাঁধন
 * ছাড়া কনটেইনার ছুঁড়ত, আর প্রতিটা বিল নিশ্চিত করা বন্ধ হয়ে যেত — [[NoRecipeBook]]-এর একই কারণ।
 */
final class NoParkedStock implements ParkedStock
{
    public function release(int $invoiceId, ?string $documentNo): void {}
}
