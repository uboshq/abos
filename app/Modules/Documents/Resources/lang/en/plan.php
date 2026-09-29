<?php

declare(strict_types=1);

/*
 * The 25 sections of the owner's plan (docs/ডকুমেন্ট-ম্যানেজমেন্ট-পরিকল্পনা.md,
 * 30 Sep 2026). Status and foundations live in DocumentPlan::SECTIONS.
 */
return [
    's1' => [
        'title' => 'Module architecture',
        'summary' => 'Three layers: storage (versions, metadata, archive), intelligence (OCR / ABE, search, extraction) and workflow (approval, signature, sharing) — with audit and security underneath all of them.',
    ],
    's2' => [
        'title' => 'Menu structure',
        'summary' => 'Twenty-one menus, from the dashboard to document administration. This is the only part live today: every row opens a page of this plan.',
    ],
    's3' => [
        'title' => 'Dashboard',
        'summary' => 'Total, active, mine, pending approval, pending signature, expiring soon, expired, archived and storage used; an activity chart, a status split and the recent documents list.',
    ],
    's4' => [
        'title' => 'Document center',
        'summary' => 'Search, filter, sort. Folders: company documents, contracts, HR, finance, sales, purchase, inventory, legal, compliance. The list shows name, type, owner, version and status.',
    ],
    's5' => [
        'title' => 'Document details',
        'summary' => 'A preview and the facts (name, type, version, owner, status, created, expiry). Tabs: preview, details, versions, approval, share, audit.',
    ],
    's6' => [
        'title' => 'Upload document',
        'summary' => 'Drag and drop or browse; one, many, a folder, a scanner or a camera. Fields: name, type, category, department, owner, date, expiry, confidentiality, tags, description.',
    ],
    's7' => [
        'title' => 'Scan & OCR',
        'summary' => 'Scanner or camera image → text → metadata → search index. Bangla and English, printed text, tables and forms. OCR runs offline; no handwriting by AI (owner, 27 Sep).',
    ],
    's8' => [
        'title' => 'Document Intelligence (ABE)',
        'summary' => 'Summarise, extract, translate, compare, classify, ask — by rules, patterns, history and statistics, offline. No external AI; no document ever leaves for an outside server.',
    ],
    's9' => [
        'title' => 'Version control',
        'summary' => 'v1.0 → v1.1 → v2.0 …; view, compare, restore, download, comment, author, date. An approved document is never overwritten — it gets a new version.',
    ],
    's10' => [
        'title' => 'Approval workflow',
        'summary' => 'Draft → submitted → under review → (returned) → approved → published → archived. The approval queue shows the document, requester, level and due date.',
    ],
    's11' => [
        'title' => 'Digital signature center',
        'summary' => 'Documents waiting for a signature; review, sign, reject, request changes. Single, multiple or sequential signatures; requests, verification and history.',
    ],
    's12' => [
        'title' => 'Expiry & renewal',
        'summary' => 'Expired, and expiring within 7/30/90 days. Reminders at 90 → 60 → 30 → 15 → 7 → 1 days before.',
    ],
    's13' => [
        'title' => 'Document security',
        'summary' => 'Permissions by role and by document: view, upload, edit, delete, download, print, share, approve, sign, archive, restore. Walls: company → branch → department → employee → document.',
    ],
    's14' => [
        'title' => 'Classification',
        'summary' => 'Public, internal, confidential, highly confidential, restricted. Restricted documents need an extra permission.',
    ],
    's15' => [
        'title' => 'Document relationships',
        'summary' => 'Customer (contracts, correspondence), supplier (agreements, certificates), purchase (RFQ, PO, bill, supporting papers), employee (appointment letter, NID, certificates). DOC becomes the central document layer of ABOS.',
    ],
    's16' => [
        'title' => 'Advanced search',
        'summary' => 'Type, category, company, branch, department, owner, date range, expiry, status, version, security level, tags — and the content itself (including OCR text).',
    ],
    's17' => [
        'title' => 'Reports',
        'summary' => 'Document register and summary; by type, department, branch, employee; uploads, downloads, approvals, rejections, signatures, expiry, renewal, archive, versions, storage. Audit: access, download, print, share, delete and restore history.',
    ],
    's18' => [
        'title' => 'Audit trail',
        'summary' => 'Who did what, when, from which address (IP) and device. Ordinary users cannot delete the audit.',
    ],
    's19' => [
        'title' => 'Recycle bin',
        'summary' => 'Deleted documents, who deleted them and when; restore. Permanent deletion only with permission.',
    ],
    's20' => [
        'title' => 'Document administration',
        'summary' => 'Types, categories, number series, metadata fields, tags, storage, OCR, ABE, approval and signature rules, security, retention, archive, expiry, notification rules and permissions.',
    ],
    's21' => [
        'title' => 'Core data structure',
        'summary' => 'Documents, versions, files, types, categories, metadata, tags, permissions, shares, approvals, signatures, expiry, archive, audit, OCR, ABE results, links, templates, notifications, recycle bin. No table exists today — the code comes later.',
    ],
    's22' => [
        'title' => 'Status system',
        'summary' => 'Draft, submitted, under review, changes requested, approved, rejected, published, expired, archived, deleted.',
    ],
    's23' => [
        'title' => 'Notifications',
        'summary' => 'New document, shared, approval required, approved, rejected, signature required, signature completed, expiry warning, expired, renewal required, updated, permission changed — through the central ABOS notifications.',
    ],
    's24' => [
        'title' => 'UI/UX',
        'summary' => 'The same look as the rest of ABOS: professional, clean, fast.',
    ],
    's25' => [
        'title' => 'The most important rule',
        'summary' => 'Not just upload and download: capture → OCR → classification (ABE) → extraction → secure storage → search, workflow, versions → approval, signature, history → sharing → archive → audit.',
    ],
];
