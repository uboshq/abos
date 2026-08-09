{{-- সচল না নিষ্ক্রিয় — পিলটাই বোতাম, আলাদা কলাম নয় (মালিকের নমুনা) --}}
<x-ui.state-toggle
    :active="$company->is_active"
    :action="route('system_admin.company.toggle', $company->id)"
    size="sm" />
