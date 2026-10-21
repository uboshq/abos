<?php

declare(strict_types=1);

namespace App\Modules\Sales\Widgets;

use App\Core\Services\PhoneModules;
use App\Core\Widgets\WidgetSummary;
use App\Models\User;
use App\Modules\Sales\Sync\CollectionSync;

/**
 * ⭐ "দ্রুত কাজ" উইজেট (৮ নম্বর) — নতুন অর্ডার, টাকা আদায়, ভাউচার, স্ক্যান, অনুমোদন, নোটিশ (a6, ২০ অক্টোবর ২০২৬;
 * [[WidgetSummary]])।
 *
 * ⛔ মালিকের নিয়ম: যে বোতামের চাবি নেই, সেটা আসবেই না। প্রতিটা বোতামের চাবি হুবহু সেই দরজার, যেটা বোতাম খোলে — আর
 * সেই বোতামের নিজের মডিউল ফোনে বন্ধ থাকলে ([[PhoneModules::isOn()]], শাখার সুইচসহ) বোতামটাও নেই।
 *
 * ⓘ ক্রম স্থির: order, collect, voucher, scan, approve, notice — ফোন যতগুলো আঁটে দেখায়।
 * ⓘ `link`: ওয়েবের পথ যেখানে ফোন সেটা নিজের পর্দায় খোলে ([[NativeForWeb]]); স্ক্যান, আদায় আর নোটিশ বোর্ডের এমন ওয়েব-পথ
 * নেই, তাই ফোনের নিজের পথ (`/home/…`)।
 * ⓘ উইজেটের চাবি নেই (null) — প্রতিটা বোতাম নিজের চাবি দেখে; নোটিশ বোর্ড সবার, তাই কোনো চাবি নেই এমন কর্মীও অন্তত সেটা পান।
 */
final class QuickActionsSummary implements WidgetSummary
{
    public function __construct(private readonly PhoneModules $phone) {}

    public function key(): string
    {
        return 'quick_actions';
    }

    /**
     * ⓘ অপরিহার্য `system_admin` — বোতামগুলো নানা মডিউলের, প্রতিটা নিজের সুইচ দেখে; `sales` নিলে বিক্রয় বন্ধ কোম্পানির
     * হিসাবরক্ষক ভাউচারের বোতামটাও হারাতেন।
     */
    public function module(): string
    {
        return 'system_admin';
    }

    public function permission(): ?string
    {
        return null;
    }

    public function data(User $user): array
    {
        $actions = [];
        foreach ($this->buttons($user) as [$key, $module, $allowed, $link]) {
            if (($module === null || $this->phone->isOn($module)) && $allowed) {
                $actions[] = ['key' => $key, 'link' => $link];
            }
        }

        return ['actions' => $actions];
    }

    /** @return list<array{0: string, 1: string|null, 2: bool, 3: string}> চাবি, ফোনের মডিউল, অনুমতি আছে কি না, পথ */
    private function buttons(User $user): array
    {
        return [
            // নতুন আদেশ — SalesOrderPolicy::create (sales.order.create); ফোনে NewOrderScreen
            ['order', 'sales', $user->can('sales.order.create'), '/sales/orders/create'],
            // ⛔ আদায় — ফোনের নিজের প্রশ্ন (/me-র mayCollect): আদায়ের চাবি + খাতায় টাকা তোলার চাবি + দেয়ালের বাইরে
            ['collect', 'sales', CollectionSync::officeMayCollect($user), '/home/collections'],
            // ভাউচার — VoucherPolicy::create (accounts.voucher.create); ফোনে ভাউচারের তালিকা, "নতুন ভাউচার" বোতামসহ
            ['voucher', 'accounts', $user->can('accounts.voucher.create'), '/accounts/voucher-list'],
            // কাগজের QR — ফোনেরই পর্দা; সার্ভারের স্ক্যানের দরজা sales.delivery.view
            ['scan', 'sales', $user->can('sales.delivery.view'), '/home/scan'],
            // অনুমোদনের ইনবক্স — approval.decide
            ['approve', 'approval', $user->can('approval.decide'), '/approvals'],
            // নোটিশ বোর্ড — সবার, কোনো মডিউলের সুইচে নেই (ওয়েবের /system/notices-এও চাবি নেই)
            ['notice', null, true, '/home/notices'],
        ];
    }
}
