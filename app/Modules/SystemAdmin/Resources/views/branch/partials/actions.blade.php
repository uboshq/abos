{{-- সারির কাজ — সম্পাদনা আর মুছুন; সক্রিয়/নিষ্ক্রিয় পাশের পিলে।
     ⓘ মুছুন কেবল অব্যবহৃত শাখায় খাটে — নিয়ম BranchDesk::remove()-এ, বোতামে নয়। --}}
{{-- ⭐ তিন বিন্দুর বদলে "অ্যাকশন ▾" — মালিক, ৯ নভেম্বর ২০২৬ ("Abos er sokol list sob skin dekhe deke update koro"); কাজগুলো আগের মতোই --}}
@php $items = [
    ['label' => __('core.action.edit'), 'url' => route('system_admin.branch.edit', $branch->id)],
    ['label' => __('core.action.delete'), 'url' => route('system_admin.branch.destroy', $branch->id), 'method' => 'delete', 'tone' => 'danger'],
]; @endphp
<x-ui.row-actions :label="__('core.table.action_menu')" :items="\App\Core\Support\RowMenu::dress($items, \App\Core\Support\RowMenu::title($branch), \App\Core\Support\RowMenu::activeState($branch), null, $branch->code ?? null, 'system_admin')" />
