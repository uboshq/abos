<?php

declare(strict_types=1);

/*
 * ⭐ বাতিলের কারণগুলো — ২৪ সেপ্টেম্বর ২০২৬।
 *
 * ⓘ কোডগুলো [[ApprovalDecision::REASONS]]-এ, আর নামগুলো এখানে।
 *
 * ⚠️ `unstated` কোডের তালিকায় নেই, আর সেটা ঠিক: ওটা কেউ
 * বাছতে পারে না। ⓘ পুরনো সারিগুলোর কোনো কারণ নেই (`null`), আর
 * রিপোর্ট ওগুলোকে এই নামে গোনে — ⛔ বাদ দিলে যোগফল বাস্তবের
 * চেয়ে কম দেখাত, আর মালিক ভাবতেন বাতিল কম হয়।
 */
return [
    'price' => 'The price is wrong',
    'document' => 'The paper has a mistake',
    'credit' => 'It goes past the credit limit',
    'budget' => 'No budget for it',
    'policy' => 'Against policy',
    'other' => 'Other',
    'unstated' => 'Unstated',
];
