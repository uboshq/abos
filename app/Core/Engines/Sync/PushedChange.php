<?php

declare(strict_types=1);

namespace App\Core\Engines\Sync;

use Illuminate\Validation\ValidationException;

/**
 * একটা ফোন যা পাঠিয়েছে — যাচাই করা, কিন্তু এখনো ব্যাখ্যা করা হয়নি।
 *
 * ── কেন আলাদা বস্তু, কাঁচা array নয় ─────────────────────────────────
 * পুশের শরীরটা আসে একটা তালিকা হিসেবে, আর প্রতিটা সারি বাইরের জগতের
 * লেখা। কাঁচা array হ্যান্ডলারে পৌঁছালে প্রতিটা হ্যান্ডলার নিজে থেকে
 * `$row['operation'] ?? ''` লিখত — আর একটা হ্যান্ডলার একদিন ভুলে যেত।
 *
 * এখানে একবার যাচাই হয়, তারপর হ্যান্ডলার একটা **নিশ্চিত আকার** পায়।
 */
final class PushedChange
{
    private function __construct(
        public readonly string $changeId,
        public readonly string $entityType,
        public readonly ?string $entityId,
        public readonly string $operation,
        public readonly string $payloadJson,
        public readonly int $clientVersion,
    ) {}

    public const CREATE = 'CREATE';

    public const UPDATE = 'UPDATE';

    /**
     * তারের আকার থেকে — `sync_engine.dart`-এর `flush()` ঠিক এই ছয়টা ঘর
     * পাঠায়।
     *
     * @param  array<string, mixed>  $row
     *
     * @throws ValidationException যখন সারিটা ব্যাখ্যা করার মতোই নয়
     */
    public static function fromArray(array $row): self
    {
        /*
         * `changeId` ছাড়া সারিটা **নীরবে দুইবার বসার একটা আমন্ত্রণ** —
         * দুইবার বসা ঠেকানোর পুরো ব্যবস্থাটাই ওই চাবির উপর দাঁড়ানো।
         * তাই এটাই প্রথম যাচাই, আর এখানে ক্ষমা নেই।
         */
        $changeId = self::text($row, 'changeId');
        if ($changeId === null) {
            throw ValidationException::withMessages([
                'changeId' => __('sync.change_needs_id'),
            ]);
        }

        $entityType = self::text($row, 'entityType');
        if ($entityType === null) {
            throw ValidationException::withMessages([
                'entityType' => __('sync.change_needs_entity_type'),
            ]);
        }

        $operation = strtoupper((string) self::text($row, 'operation'));
        if (! in_array($operation, [self::CREATE, self::UPDATE], true)) {
            throw ValidationException::withMessages([
                'operation' => __('sync.unknown_operation', ['operation' => $operation]),
            ]);
        }

        /*
         * UPDATE-এ আইডি ছাড়া কিছু করার নেই — কোন সারিটা বদলাবে তা-ই
         * জানা নেই। CREATE-এ আইডি না থাকাই স্বাভাবিক: সার্ভার সারিটা
         * এখনো বানায়নি।
         */
        $entityId = self::text($row, 'entityId');
        if ($operation === self::UPDATE && $entityId === null) {
            throw ValidationException::withMessages([
                'entityId' => __('sync.update_needs_entity_id'),
            ]);
        }

        $payloadJson = $row['payloadJson'] ?? null;
        if (! is_string($payloadJson) || $payloadJson === '') {
            throw ValidationException::withMessages([
                'payloadJson' => __('sync.change_needs_payload'),
            ]);
        }

        /*
         * ভাঙা JSON এখানেই ধরা — হ্যান্ডলারে নয়।
         *
         * হ্যান্ডলারে ধরলে প্রতিটা হ্যান্ডলারকে একই try/catch লিখতে হত,
         * আর যেটা ভুলে যেত সেটা একটা ৫০০ দিত — অর্থাৎ **পুরো ব্যাচটাই**
         * ব্যর্থ হত, ওই একটা সারির বদলে।
         */
        try {
            $decoded = json_decode($payloadJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ValidationException::withMessages([
                'payloadJson' => __('sync.payload_is_not_readable'),
            ]);
        }

        if (! is_array($decoded)) {
            throw ValidationException::withMessages([
                'payloadJson' => __('sync.payload_is_not_readable'),
            ]);
        }

        return new self(
            changeId: $changeId,
            entityType: $entityType,
            entityId: $entityId,
            operation: $operation,
            payloadJson: $payloadJson,
            clientVersion: max(1, (int) ($row['clientVersion'] ?? 1)),
        );
    }

    /**
     * ব্যাখ্যা করা payload — হ্যান্ডলার এটাই পড়ে।
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($this->payloadJson, true);

        return $decoded;
    }

    public function isCreate(): bool
    {
        return $this->operation === self::CREATE;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private static function text(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
