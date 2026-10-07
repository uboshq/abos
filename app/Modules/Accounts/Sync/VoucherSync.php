<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Sync;

use App\Core\Contracts\SyncsToDevices;
use App\Core\Engines\Sync\PushedChange;
use App\Core\Engines\Sync\SyncBatch;
use App\Core\Engines\Sync\SyncPosition;
use App\Core\Engines\Sync\SyncRejection;
use App\Models\User;
use App\Modules\Accounts\Http\Requests\VoucherRequest;
use App\Modules\Accounts\Models\Voucher;
use App\Modules\Accounts\Services\VoucherWriter;
use Illuminate\Routing\Redirector;
use Illuminate\Support\Carbon;

/**
 * ⭐ ফোনে লেখা ভাউচার — অফিসের লোকের (মালিক, ৭ অক্টোবর ২০২৬: "সব ভাউচার দেওয়ার কথা ছিল")।
 *
 * <p>সিঙ্কের সারি দিয়ে আসে, তাই নেট না থাকলে ফোনে জমা থাকে আর একই ভাউচার দুবার পৌঁছালেও একবারই বসে (changeId,
 * [[SyncService::applyOne()]])। ⭐ নিয়ম সব ওয়েবের: সারিটা থেকে ওয়েবের অনুরোধই বানানো হয় ([[VoucherRequest]] — ধরন,
 * তারিখ, বিবরণের সুইচ, দুই দিকের খাত, বাকিতে খরচে পক্ষ, জাবেদার পক্ষ আর মিল), তারপর ওয়েবের একই লেখার পথ
 * ([[VoucherWriter]] — ছাঁচ, সই, লেখক ≠ পাকাকারী, নিজের টিলের নগদ, লেনদেন নম্বর)। কোনো নিয়ম আটকালে কেবল এই
 * সারিটাই ফেরে, বাংলা কারণসহ — খসড়াও থাকে না (এক লেনদেন)।
 *
 * <p>ফোনে টানা কিছু নেই: ভাউচারের তালিকা আর পাতা দরজা দিয়ে আসে ([[VoucherApiController]]) — হাজার ভাউচার ফোনের
 * ভাণ্ডারে রাখার কারণ নেই।
 */
final class VoucherSync implements SyncsToDevices
{
    public function __construct(private readonly VoucherWriter $writer) {}

    public static function module(): string
    {
        return 'accounts';
    }

    public static function entityType(): string
    {
        return 'Voucher';
    }

    /** ⓘ ওয়েবের ভাউচার লেখার চাবি — ক্যাশিয়ারের আছে, রিপোর্টের চাবি নেই; টানায় কিছু আসে না */
    public static function requiredPermission(): ?string
    {
        return 'accounts.voucher.create';
    }

    /** ⛔ ফোন থেকে লেখার চাবি — ওয়েবের একই ([[SyncsToDevices::requiredPushPermission()]]) */
    public static function requiredPushPermission(): ?string
    {
        return 'accounts.voucher.create';
    }

    /** ফোনে ভাউচার রাখা হয় না — তালিকা দরজা দিয়ে, পাতা ধরে */
    public function pull(User $user, ?Carbon $since, int $limit, ?SyncPosition $after = null): SyncBatch
    {
        return SyncBatch::of([], 0, $limit, null);
    }

    public function acceptsPush(): bool
    {
        return true;
    }

    public function apply(User $user, PushedChange $change): string
    {
        if (! $change->isCreate()) {
            throw SyncRejection::conflict(__('accounts::phone_voucher.edit_needs_network'));
        }

        $payload = $change->payload();
        $type = $payload['type'] ?? null;

        if (! is_string($type) || ! in_array($type, Voucher::TYPES, true)) {
            throw new SyncRejection(__('accounts::phone_voucher.unknown_type'));
        }

        /*
         * ⭐ ওয়েবের অনুরোধ, হুবহু — `prepareForValidation()` (পক্ষ ভাগ, খাত ভরা), নিয়ম আর `after()`-এর যাচাই সব চলে;
         * ভুল হলে ValidationException, আর সেবা সেটা এই সারির REJECTED বানায় ([[SyncService::applyOne()]])।
         * ⓘ ফাইল আসে না (সংযুক্তি ফোনের সারিতে যায় না), আর `save_as_draft` ফোনের "খসড়া রাখুন"।
         */
        $request = VoucherRequest::create('/api/v1/accounts/vouchers', 'POST', $payload);
        $request->setContainer(app())->setRedirector(app(Redirector::class));
        $request->setUserResolver(fn () => $user);
        $request->validateResolved();

        $data = $request->validated();
        $data['type'] = $type;

        [$voucher] = $this->writer->store(
            $data,
            $this->writer->linesFor($type, $request->all()),
            asDraft: $request->boolean('save_as_draft'),
        );

        return (string) $voucher->public_id;
    }
}
