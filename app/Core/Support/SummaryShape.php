<?php

declare(strict_types=1);

namespace App\Core\Support;

use App\Core\Contracts\ClassifiesDrafts;
use Illuminate\Database\Eloquent\Model;

/**
 * একটা কাগজের তালিকা-মোটের নিয়ম — কোন কলামে টাকা, কোন status কোন দলে ([[ListSummary]])।
 *
 * ⓘ দলের নাম [[ListSummary]]-এর ধ্রুবক; [[ListSummary::CLASSIFY]] মানে "এই status-এর সারি কাগজের সই-সেবা ভাগ করবে"
 * ([[ClassifiesDrafts]])। ⭐ `open` — counted-এর ভিতরের অংশ (যেমন DO: অনুমোদিত, বিল বাকি), আলাদা দল নয়।
 */
final readonly class SummaryShape
{
    /**
     * @param  class-string<Model>  $model
     * @param  array<string, string>  $groups  status => দল (বা CLASSIFY)
     * @param  list<string>  $open  counted-এর যে status-গুলো "খোলা" — counted-এর উপসেট
     * @param  class-string<ClassifiesDrafts>|null  $classifier
     */
    public function __construct(
        public string $model,
        public string $amount,
        public array $groups,
        public array $open = [],
        public ?string $classifier = null,
        public string $status = 'status',
    ) {}

    public function table(): string
    {
        return (new $this->model)->getTable();
    }

    /**
     * summary-তে কোন দলের চাবি থাকবে — যে দল এই কাগজে হয়ই না, তার চাবি নেই (শূন্য নয়), চুক্তির ক্রমে।
     *
     * @return list<string>
     */
    public function keys(): array
    {
        $present = array_values(array_diff(array_unique(array_values($this->groups)), [ListSummary::CLASSIFY]));

        if (in_array(ListSummary::CLASSIFY, $this->groups, true) && $this->classifier !== null) {
            $present = [...$present, ...app($this->classifier)->groups()];
        }

        return array_values(array_filter(ListSummary::GROUPS, fn (string $g) => in_array($g, $present, true)));
    }
}
