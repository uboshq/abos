{{-- সারির কাজ — সম্পাদনা আর মুছুন; সক্রিয়/নিষ্ক্রিয় পাশের পিলে।
     ⓘ মুছুন কেবল অব্যবহৃত শাখায় খাটে — নিয়ম BranchDesk::remove()-এ, বোতামে নয়। --}}
<x-ui.row-actions :items="[
    ['label' => __('core.action.edit'), 'url' => route('system_admin.branch.edit', $branch->id)],
    ['label' => __('core.action.delete'), 'url' => route('system_admin.branch.destroy', $branch->id), 'method' => 'delete', 'tone' => 'danger'],
]" />
