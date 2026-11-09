{{--
    সারির কাজ — কেবল সম্পাদনা।

    মোছা নেই: একটা কোম্পানি মানে তার প্রতিটা বিল, চালান আর খতিয়ানের
    সারি, আর ভুল করে চাপলে ফেরার পথ নেই। সচল/নিষ্ক্রিয় করাটা পাশের
    পিলেই আছে, তাই এখানে আবার রাখা হয়নি — একই কাজ দুই জায়গায় থাকলে
    একদিন একটা বদলাত আর অন্যটা পুরনো আচরণে থেকে যেত।
--}}
{{-- ⭐ তিন বিন্দুর বদলে "অ্যাকশন ▾" — মালিক, ৯ নভেম্বর ২০২৬ ("Abos er sokol list sob skin dekhe deke update koro"); কাজগুলো আগের মতোই --}}
@php $items = [
    ['label' => __('core.action.edit'), 'url' => route('system_admin.company.edit', $company->id)],
]; @endphp
<x-ui.row-actions :label="__('core.table.action_menu')" :items="\App\Core\Support\RowMenu::dress($items, \App\Core\Support\RowMenu::title($company), \App\Core\Support\RowMenu::activeState($company), null, $company->code ?? null, 'system_admin')" />
