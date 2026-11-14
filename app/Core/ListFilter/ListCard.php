<?php

declare(strict_types=1);

namespace App\Core\ListFilter;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * ⭐ তালিকার উপরের রঙিন কার্ড — চাপলেই সেই ছাঁকনি বসে (মালিকের অনুমোদিত নমুনা, ১৪ নভেম্বর ২০২৬; আন্তর্জাতিক মানে Fiori-র
 *   "icon tab bar" আর D365-এর "tiles")।
 *
 * ⓘ সংখ্যা আর টাকা আসে তালিকার নিজের কোয়েরি থেকে (শাখা, দেয়াল, খোঁজা সহ; ছাঁকনি ছাড়া) — কার্ডে "৩ জন" দেখে চাপ দিয়ে
 *   ৪ জন পাওয়ার সুযোগ নেই, কারণ কার্ডের ছাঁকনিটাও সেই একই ইঞ্জিনের ঘর ([[FilterSet]])।
 * ⓘ কোনো কার্ডে ছাঁকনি না থাকলে (`filter: []`) সেটা "সব" — কোনো ছাঁকনি চালু না থাকলে সেটাই জ্বলে থাকে।
 */
final class ListCard
{
    /**
     * @param  array<string, list<string>|string>  $filter  ঠিকানার চাবি => মান, যেমন ['quick' => ['due']]
     * @param  Closure(Builder): string  $value  বড় লেখা — "২৮ জন" বা "৳৪,৫৬,০০০.০০"
     * @param  Closure(Builder): string  $sub  ছোট লেখা — দ্বিতীয় তথ্য
     * @param  array{0: string, 1: string}  $gradient  দুই রং — গাঢ় থেকে উজ্জ্বল
     */
    public function __construct(
        public readonly string $label,
        public readonly string $icon,
        public readonly array $gradient,
        public readonly array $filter,
        private readonly Closure $value,
        private readonly Closure $sub,
    ) {}

    /**
     * @param  Closure(): Builder  $base
     * @param  list<string>  $keys  এই তালিকার ছাঁকনির সব চাবি — কার্ড চাপলে বাকিগুলো মোছে
     * @return array{label: string, icon: string, gradient: array{0: string, 1: string}, value: string, sub: string, url: string, on: bool}
     */
    public function view(Closure $base, Request $request, array $keys, bool $nothingOn): array
    {
        $query = $request->query();
        unset($query['page']);

        foreach ($keys as $key) {
            unset($query[$key]);
        }

        $query = array_merge($query, $this->filter);

        return [
            'label' => $this->label,
            'icon' => $this->icon,
            'gradient' => $this->gradient,
            'value' => ($this->value)($base()),
            'sub' => ($this->sub)($base()),
            'url' => $request->url().($query === [] ? '' : '?'.http_build_query($query)),
            'on' => $this->filter === [] ? $nothingOn : $this->isOn($request),
        ];
    }

    /** ⓘ কার্ডের প্রতিটা চাবি ঠিকানায় হুবহু সেই মানে — একটা বেশি বা কম টিক থাকলে কার্ড জ্বলে না */
    private function isOn(Request $request): bool
    {
        foreach ($this->filter as $key => $want) {
            $have = $request->query($key);
            $have = is_array($have) ? array_map('strval', $have) : (is_scalar($have) ? [(string) $have] : []);
            $want = is_array($want) ? array_map('strval', $want) : [(string) $want];
            sort($have);
            sort($want);

            if ($have !== $want) {
                return false;
            }
        }

        return true;
    }
}
