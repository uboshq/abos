<?php

declare(strict_types=1);

namespace App\Modules\Supplier\Widgets;

use App\Core\Widgets\WidgetSummary;
use App\Models\User;
use App\Modules\Supplier\Services\PrincipalCommissionFigures;

/**
 * ⭐ প্রিন্সিপালের কমিশন — হোম-স্ক্রিনের উইজেট (মালিক, fe-র মারফত, ২০ অক্টোবর ২০২৬: *"app e dekhate hobe"*; চুক্তি
 * `pc/CONTRACT.md` §৩)।
 *
 * ⓘ সংখ্যা ওয়েবের সরবরাহকারী-ড্যাশবোর্ডের বাক্সের হুবহু ([[PrincipalCommissionFigures]]) — চলতি চক্র, হেডারে বাছা শাখা,
 * শাখার দেয়াল। মোট সব প্রিন্সিপালের; সারি সর্বোচ্চ ৮টা (দলের নিয়ম), রিপোর্টের ক্রমে।
 * ⛔ চাবি `supplier.report` — ওয়েবের রিপোর্ট আর বাক্সের একই; না থাকলে উত্তরে key-ই নেই ([[WidgetSummaries::visibleTo()]])।
 * ⓘ ফোনের সুইচ `purchase`: ফোনে "সরবরাহকারী" আলাদা মডিউল নয়, প্রিন্সিপাল ক্রয়েরই অংশ (আজকের পাতার প্রিন্সিপালের ঘর আর
 * ক্রয়ের ফোন-দরজার একই সুইচ)।
 * ⛔ data()-তে সময় নেই — চক্রের তারিখ তথ্য, "এখন" নয়; তাই ETag-এ ৩০৪ আসে।
 */
final class PrincipalCommissionSummary implements WidgetSummary
{
    public function __construct(private readonly PrincipalCommissionFigures $figures) {}

    public function key(): string
    {
        return 'principal_commission';
    }

    public function module(): string
    {
        return 'purchase';
    }

    public function permission(): string
    {
        return 'supplier.report';
    }

    public function data(User $user): array
    {
        ['rows' => $rows, 'total' => $total] = $this->figures->figures();

        return [
            'rows' => array_slice($rows, 0, PrincipalCommissionFigures::WIDGET_ROWS),
            'total' => $total,
            'link' => '/home/principal-commission',
        ];
    }
}
