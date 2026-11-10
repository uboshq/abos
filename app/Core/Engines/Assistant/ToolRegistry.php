<?php

declare(strict_types=1);

namespace App\Core\Engines\Assistant;

use App\Core\Module\ModuleRegistry;
use App\Models\User;

/**
 * ⭐ কোপাইলটের দরজার তালিকা — প্রতিটা মডিউল নিজের দরজা দেয় (`module.php` → `assistant_tools`), কোর নাম জানে না।
 *
 * ⓘ একজন মানুষ কেবল সেই দরজাগুলো পান যার চাবি তাঁর আছে ([[for()]]) — মডেলও কেবল সেগুলোর নাম শোনে ([[describeFor()]]),
 *   তাই ক্যাশিয়ারের প্রশ্নে মডেল বেতনের দরজা বাছতেই পারে না। মডেল তবু বাইরের নাম বললে [[call()]] আবার চাবি দেখে — "আটকানো"।
 */
final class ToolRegistry
{
    public function __construct(private readonly ModuleRegistry $modules) {}

    /** @return array<string, class-string<AssistantTool>> নাম → দরজা */
    public function all(): array
    {
        $out = [];

        foreach ($this->modules->all() as $module) {
            foreach ($module->assistantTools as $class) {
                $out[$class::name()] = $class;
            }
        }

        return $out;
    }

    /** @return array<string, class-string<AssistantTool>> এই মানুষের চাবির দরজাগুলো */
    public function for(User $user): array
    {
        return array_filter($this->all(), fn (string $class): bool => $user->can($class::permission()));
    }

    /**
     * ⓘ মডেলের জন্য — নাম, বর্ণনা (চাওয়া ভাষায়), মানের ছক। ভেতরের তথ্য নয়।
     *
     * @return list<array{name: string, description: string, parameters: array<string, mixed>, required: list<string>}>
     */
    public function describeFor(User $user, string $locale): array
    {
        $out = [];

        foreach ($this->for($user) as $name => $class) {
            $d = $class::describe();
            $out[] = [
                'name' => $name,
                'description' => (string) ($d['description'][$locale === 'en' ? 'en' : 'bn'] ?? ''),
                'parameters' => $d['parameters'],
                'required' => $d['required'] ?? [],
            ];
        }

        return $out;
    }

    /**
     * ⭐ দরজা খোলা — চাবি আবার দেখা হয় (মডেলের কথায় ভরসা নয়)।
     *
     * @param  array<string, mixed>  $args
     * @return array{rows: list<array<string, string>>, columns: list<string>, summary: string, sources: list<array{label: string, href: string}>}|null  null = চাবি নেই বা দরজা নেই
     */
    public function call(User $user, string $name, array $args): ?array
    {
        $class = $this->all()[$name] ?? null;

        if ($class === null || ! $user->can($class::permission())) {
            return null;
        }

        return app($class)->run($user, $args);
    }
}
