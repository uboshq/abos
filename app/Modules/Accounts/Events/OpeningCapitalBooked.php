<?php

declare(strict_types=1);

namespace App\Modules\Accounts\Events;

use App\Core\Events\DomainEvent;

/**
 * ⭐ খোলা জের মালিকের মূলধনে (৩১০০) বসল — মালিকের আদেশ, ৫ অক্টোবর ২০২৬ ([[OpeningBalanceService::announce()]])।
 *
 * ⓘ অর্থ মডিউল শোনে আর রেজিস্টারে মালিকের নামে শুরুর মূলধন তোলে ([[ReconcileOpeningCapital]])। হিসাব অর্থকে চেনে না,
 * তাই কথাটা ঘটনায় যায়; অর্থ বন্ধ থাকলে কেউ শোনে না, আর খাতা আগের মতোই ঠিক থাকে।
 */
final class OpeningCapitalBooked extends DomainEvent
{
}
