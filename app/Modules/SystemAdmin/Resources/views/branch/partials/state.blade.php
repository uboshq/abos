{{--
    সচল না নিষ্ক্রিয় — পিলটাই বোতাম, কোম্পানির পাতার একই ধাঁচ।

    ⚠️ `method="POST"` স্পষ্ট — [[x-ui.state-toggle]]-এর ডিফল্ট `DELETE`,
    আর রুটটা POST চায় (কোম্পানির পাতায় ঠিক এই ভুলে সুইচ নীরবে কিছুই করত না)।
--}}
<x-ui.state-toggle
    :active="$branch->is_active"
    :action="route('system_admin.branch.toggle', $branch->id)"
    method="POST"
    size="sm"
    :title="$branch->is_active ? __('system_admin::action.deactivate_branch') : __('system_admin::action.activate_branch')"
    :aria-label="($branch->is_active ? __('system_admin::action.deactivate_branch') : __('system_admin::action.activate_branch')).' — '.$branch->code" />
