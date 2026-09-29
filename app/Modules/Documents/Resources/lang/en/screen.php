<?php

declare(strict_types=1);

/*
 * What each screen will do — from the owner's plan (30 Sep 2026). `new` exists
 * only where the screen needs something ABOS does not have at all today.
 */
return [
    'center' => [
        'what' => 'Every document of the organisation in one place — by folder (company documents, contracts, HR, finance, sales, purchase, inventory, legal, compliance). Search, filter, sort; the list shows name, type, owner, version and status. Opening a document shows tabs for preview, details, versions, approval, share and audit.',
    ],
    'inbox' => [
        'what' => 'Documents that came to me — someone shared one, asked for approval, or asked for a signature. The plan has no separate description for this screen, only its menu name (§2); it will gather the approval (§10), signature (§11) and notification (§23) news in one place.',
    ],
    'upload' => [
        'what' => 'Bringing documents in — drag and drop or browse; one, many, a whole folder, a scanner or a camera. With name, type, category, department, owner, date, expiry, confidentiality, tags and description. Every document gets its own number, and every change reaches the audit.',
    ],
    'scan' => [
        'what' => 'Capture paper from a scanner or a phone camera, then OCR: image → text → metadata (such as bill number, date, supplier, amount) → search index. Bangla and English, printed text, tables and forms. A document is saved only after a person has checked it.',
        'new' => 'ABOS has no OCR today — this is a new part, and it will run inside the server, offline. No image leaves for an outside server. Handwriting will not be read by AI (owner, 27 Sep).',
    ],
    'intelligence' => [
        'what' => 'Document Intelligence (ABE) — summarise, extract, translate, compare, classify and ask. All by rules, patterns, history and statistics: for example, recognising the fields of a new bill from the shape of the same supplier\'s earlier bills.',
        'new' => 'No external AI (owner, 27 and 30 Sep). The ABE rules have to be written new; no document leaves for an outside server.',
    ],
    'mine' => [
        'what' => 'The documents I own or uploaded — the same list as the document center, only mine. Visible only inside my own company and branch walls.',
    ],
    'shared' => [
        'what' => 'Documents others shared with me, and the ones I shared — with whom, when, and with which rights (view, download, print). Every share sends a notification and stays in the audit.',
    ],
    'recent' => [
        'what' => 'Recently opened or changed documents — name, type, owner, version, status and when it changed. The full version of the dashboard\'s recent list (§3).',
    ],
    'favourite' => [
        'what' => 'Documents I look for again and again, marked with a star — back in one click. The plan has no separate description for this screen, only its menu name (§2).',
        'new' => 'ABOS has no favourites list anywhere today — each user\'s own small list has to be built new.',
    ],
    'templates' => [
        'what' => 'Templates for documents needed again and again — contracts, appointment letters, letters. A document made from a template gets its own number and prints through the ABOS print templates.',
    ],
    'editor' => [
        'what' => 'Changing a document means a new version: v1.0 → v1.1 → v2.0. See earlier versions, compare two, restore an old one, comment, author and date. An approved document can never be changed in place.',
        'new' => 'ABOS has no version history or version comparison today — the audit records changes, but it does not keep file versions. This is a new part.',
    ],
    'approval' => [
        'what' => 'Documents waiting for my approval — the document, the requester, the step and the due date. Flow: draft → submitted → under review → (returned) → approved → published → archived. Approval runs on the ABOS approval engine, not a separate system.',
    ],
    'signature' => [
        'what' => 'Documents that need my signature — review, sign, reject or request changes. Single, multiple or sequential signatures; requests, verification and history. The signing order comes from the approval flows.',
    ],
    'expiry' => [
        'what' => 'Documents that have expired, and those expiring within 7, 30 or 90 days (licences, contracts, certificates). Reminders 90 → 60 → 30 → 15 → 7 → 1 days before, through the notification center.',
    ],
    'archive' => [
        'what' => 'Where finished documents go — out of the working lists, but never deleted; with permission they can be searched, opened and restored. How long they are kept is set by the retention and archive policies in administration.',
    ],
    'recycle' => [
        'what' => 'Deleted documents — which document, who deleted it, when; it can be restored. Permanent deletion only for someone who holds that permission.',
    ],
    'search' => [
        'what' => 'Search from every side: type, category, company, branch, department, owner, date range, expiry, status, version, security level, tags — and the text inside the document, including OCR text. Results show only what the person may see.',
    ],
    'reports' => [
        'what' => 'Document register and summary; by type, department, branch and employee; uploads, downloads, approvals, rejections, signatures, expiry, renewal, archive, versions and storage. On the ABOS report engine — with the branch split and export.',
    ],
    'audit' => [
        'what' => 'Who opened, downloaded, printed, shared, deleted or restored which document — when, from which address (IP) and device. Ordinary users cannot delete the audit.',
    ],
    'admin' => [
        'what' => 'The rules of DOC: document types, categories, number series, metadata fields, tags, storage, OCR and ABE settings, approval and signature rules, security, retention, archive, expiry, notifications and permissions. Confidentiality levels: public, internal, confidential, highly confidential, restricted.',
    ],
];
