@props(['menu' => []])

{{--
    ⭐ এক করা মডিউলের ড্যাশবোর্ডের ট্যাব — মালিক, ২০ নভেম্বর ২০২৬:
      হিসাব ও অর্থ → হিসাব · অর্থ / ট্রেজারি · স্থায়ী সম্পদ
      বিক্রয়      → বিক্রয় · গ্রাহক · বাকি নিয়ন্ত্রণ · অফার
      ক্রয়        → ক্রয় · সরবরাহকারী

    ⓘ প্রতিটা ট্যাব সদস্যের নিজের ড্যাশবোর্ডের ঠিকানা — ভিতরের বোর্ড একটুও বদলায়নি, পুরনো বুকমার্কও খোলে।
    ⓘ সদস্যের তালিকা মেনু থেকেই ([[MenuBuilder::membersOf()]]) — চাবি নেই বা মডিউল বন্ধ হলে তার ট্যাব নেই; দুইটার কম হলে কিছুই আঁকা হয় না।
    ⓘ কেবল তখন, যখন এই পাতা কোনো সদস্যের ড্যাশবোর্ড — অন্য পাতায় নয়।
--}}
@php
    $mergedTabs = [];
    $mergedName = '';

    foreach ($menu as $mergedModule) {
        $members = $mergedModule['members'] ?? [];

        if (count($members) < 2 || ! collect($members)->contains('active', true)) {
            continue;
        }

        $mergedName = (string) $mergedModule['label'];
        $mergedTabs = array_map(fn (array $member): array => [
            'key' => $member['code'],
            'label' => $member['tab'],
            'url' => $member['url'],
            'active' => $member['active'],
        ], $members);

        break;
    }
@endphp

@if ($mergedTabs !== [])
    <x-ui.list-tabs :tabs="$mergedTabs" :label="$mergedName" data-merged-tabs />
@endif
