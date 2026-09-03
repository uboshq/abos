<?php

declare(strict_types=1);

namespace App\Core\Engines\Duplication;

use App\Core\Module\ModuleRegistry;

/**
 * কোন রেকর্ডে নকল দেখা হবে — মডিউলের ঘোষণা থেকে, এক জায়গায়।
 *
 * নকল-ঠেকানোর যন্ত্রটা [[DuplicateGuard]], কিন্তু কোন মডেলে কোন ঘর তুলনা
 * হবে সেটা প্রতিটা মডিউল নিজের module.php-র `duplicates`-এ বলে। এই
 * রেজিস্ট্রি সব মডিউল পড়ে একত্র করে — ঠিক যেভাবে NumberSeriesProvisioner
 * doc_types পড়ে। ফলে নতুন মাস্টার যোগ করতে কোর ফাইল খুলতে হয় না, আর
 * একটা গার্ড দাবি করতে পারে নাম-ওয়ালা প্রতিটা মাস্টার ঘোষিত (সেকশন ১৯.৭)।
 */
final class DuplicationRegistry
{
    public function __construct(private readonly ModuleRegistry $registry) {}

    /**
     * সব মডিউলের নকল-নিয়ম, phone সবসময় একটা তালিকা (নেই থাকলে খালি)।
     *
     * @return list<array{model: class-string, name: list<string>, phone: list<string>}>
     */
    public function all(): array
    {
        $rules = [];

        foreach ($this->registry->all() as $module) {
            foreach ($module->duplicates as $rule) {
                $rules[] = [
                    'model' => $rule['model'],
                    'name' => array_values($rule['name']),
                    'phone' => array_values($rule['phone'] ?? []),
                ];
            }
        }

        return $rules;
    }

    /**
     * একটা মডেলের নিয়ম — ঘোষিত না হলে null।
     *
     * @param  class-string  $model
     * @return array{model: class-string, name: list<string>, phone: list<string>}|null
     */
    public function for(string $model): ?array
    {
        foreach ($this->all() as $rule) {
            if ($rule['model'] === $model) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * যেসব মডেলের নকল-সুরক্ষা ঘোষিত — গার্ড-টেস্ট এটা ধরেই মেলায়।
     *
     * @return list<class-string>
     */
    public function declaredModels(): array
    {
        return array_map(static fn (array $rule): string => $rule['model'], $this->all());
    }
}
