<?php

declare(strict_types=1);

namespace App\Core\Panels;

use App\Core\Module\ModuleRegistry;

/**
 * একটা রেকর্ড সম্পর্কে বাকি মডিউলরা যা যা জানে, জোগাড় করা।
 *
 * DashboardRegistry-র মতোই: কোর কোনো মডিউলের নাম জানে না, কেবল
 * রেজিস্ট্রি হেঁটে যারা ঘোষণা করেছে তাদের ডাকে (সেকশন ১৯.৭)।
 */
class FactRegistry
{
    public function __construct(private readonly ModuleRegistry $modules) {}

    /**
     * এই রেকর্ডটা সম্পর্কে সবার বক্তব্য, সাজানো।
     *
     * ── কেন খালি মানগুলো এখানেই ছাঁকা হয় ────────────────────────────
     * প্রতিটা পাতাকে নিজে ছাঁকতে বললে একদিন কোনো পাতায় "শেষ কেনা: —"
     * বসত, আর ওটা তথ্য নয়, শূন্যতা। যে মডিউলের বলার কিছু নেই তার
     * সারিটা বসবেই না।
     *
     * ব্যতিক্রম চাপা দেওয়া হয় না: একটা সরবরাহকারী ভাঙলে পাতাটা ভাঙবে।
     * নীরবে বাদ দিলে তথ্যটা কোনোদিন ফিরত না, আর কেউ খেয়ালও করত না।
     *
     * @return list<Fact>
     */
    public function forRecord(string $entity, int $id): array
    {
        $facts = [];

        foreach ($this->modules->all() as $module) {
            foreach ($module->facts as $provider) {
                foreach ($provider::factsFor($entity, $id) as $fact) {
                    if ($fact->value === null || trim($fact->value) === '') {
                        continue;
                    }

                    $facts[] = $fact;
                }
            }
        }

        usort($facts, fn (Fact $a, Fact $b) => [$a->sort, $a->label] <=> [$b->sort, $b->label]);

        return $facts;
    }
}
