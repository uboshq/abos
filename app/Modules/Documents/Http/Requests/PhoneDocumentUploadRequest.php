<?php

declare(strict_types=1);

namespace App\Modules\Documents\Http\Requests;

use App\Models\Branch;

/**
 * ⭐ ফোন থেকে কাগজ তোলা — ওয়েবের তোলার হুবহু নিয়ম ([[DocumentUploadRequest]]), কেবল শাখা আসে public_id-তে (চুক্তি §৩ক)।
 *
 * ⓘ `branch` (public_id) → `branch_id` যাচাইয়ের আগে; অচেনা শাখা `branch_id`-এর নিজের নিয়মেই ৪২২ (দেয়ালের বাইরের শাখাও)।
 */
final class PhoneDocumentUploadRequest extends DocumentUploadRequest
{
    protected function prepareForValidation(): void
    {
        $branch = trim((string) $this->input('branch', ''));

        if ($branch !== '') {
            // ⓘ না পেলে -1 — `Rule::in(নাগালের শাখা)` সেটা ফেরায়, ফাঁকা (গোটা কোম্পানি) হয়ে যায় না
            $this->merge(['branch_id' => Branch::query()->where('public_id', $branch)->value('id') ?? -1]);
        }
    }
}
