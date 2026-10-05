<?php

declare(strict_types=1);

namespace App\Modules\Finance\Listeners;

use App\Core\Support\CompanyContext;
use App\Modules\Accounts\Events\OpeningCapitalBooked;
use App\Modules\Finance\Services\OwnerCapital;

/**
 * ⭐ খোলা জের মালিকের মূলধনে বসলেই রেজিস্টারে মালিকের নামে — শাখা ধরে (মালিকের আদেশ, ৫ অক্টোবর ২০২৬; [[OwnerCapital::reconcile()]])।
 *
 * ⓘ মালিক বাছা না থাকলে কিছুই হয় না — মূলধনের পাতা প্রথমবার বাছতে বলে, আর বাছার মুহূর্তে পুরো ফাঁক একবারে ভরে।
 */
final class ReconcileOpeningCapital
{
    public function handle(OpeningCapitalBooked $event): void
    {
        CompanyContext::forCompany($event->companyId, fn () => app(OwnerCapital::class)->reconcile());
    }
}
