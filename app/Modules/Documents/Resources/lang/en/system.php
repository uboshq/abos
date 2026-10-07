<?php

declare(strict_types=1);

/*
 * The ABOS systems DOC will stand on — one line each. The class names live in
 * DocumentPlan::SYSTEMS; this file is only the human words.
 */
return [
    'approval' => 'Approval engine — who signs, at which step, within which limit',
    'approval_flow' => 'Approval flows — the order of signatures (one, many, in sequence)',
    'notification' => 'Notification center — the bell, only for people who have something to do',
    'number_series' => 'Number series engine — every document gets its own gapless number',
    'audit_engine' => 'Audit engine — who changed what, when; the old and the new value',
    'audit_trait' => 'IsAudited — put it on a model and every change reaches the audit',
    'attachment' => 'File attachments — files on disk, dangerous types refused',
    'image' => 'Image processing — phone photos rotated, cropped and shrunk (like CamScanner)',
    'report' => 'Report engine — each report its own key, split by branch, export',
    'dashboard' => 'Dashboard engine — figures, lists and tiles, filtered by key',
    'data_scope' => 'DataScope — which branches a person may see',
    'branch_wall' => 'Branch wall — put it on a model and every query is filtered by branch',
    'company_wall' => 'Company wall — one company\'s rows never reach another',
    'search' => 'Search engine — every module in one search, filtered by permission',
    'print' => 'Print engine — paper sizes, templates and samples',
    'settings' => 'Settings — each company\'s own values, from the Control Panel',
    'permissions' => 'Permissions — each module\'s keys, handed out by role',
    'drill' => 'Drill-down — from any row to its real paper in one click',
    'menu' => 'Menu — the sidebar built from module.php, every row with its own switch',
];
