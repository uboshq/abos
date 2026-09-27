{{-- সারির কাজ — কেবল সম্পাদনা; মোছা নেই, নিষ্ক্রিয় করা পাশের পিলে। --}}
<x-ui.row-actions :items="[
    ['label' => __('core.action.edit'), 'url' => route('system_admin.branch.edit', $branch->id)],
]" />
