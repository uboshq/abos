<?php

declare(strict_types=1);

/*
 * Document messages — centre, upload, versions, archive (8 October 2026, phase one).
 */
return [
    'count' => '{0} No documents|{1} 1 document|[2,*] :count documents',
    'search_placeholder' => 'Search name, number, tag or description...',
    'none_yet' => 'No documents here yet.',
    'all_folders' => 'All folders',

    'number_auto' => 'The number is assigned automatically',
    'files_hint' => 'Up to :count files at once, :max each — PDF, images, Word, Excel, PowerPoint or text. Each file becomes its own document.',
    'name_hint' => 'Leave empty to use the file name. With several files, each file name is added after this name.',
    'expiry_hint' => 'For licences, contracts or certificates that expire — the centre can then filter by expiry.',
    'tags_hint' => 'Separate with commas, e.g. rent, warehouse, 2026',
    'comment_hint' => 'Kept with the first version — for example where the paper came from.',
    'company_wide' => 'Whole company',
    'level_hint' => 'Only the levels you can see yourself are offered.',

    'uploaded_one' => 'Document uploaded — number :no.',
    'uploaded_many' => '{1} 1 document uploaded.|[2,*] :count documents uploaded.',
    'updated' => 'Details saved.',
    'archived' => 'Document archived — it leaves the centre but is not deleted.',
    'unarchived' => 'Document restored from the archive — it shows in the centre again.',
    'deleted' => 'Document deleted. The file and its history are kept.',
    'version_added' => 'New version :version uploaded. Earlier versions are unchanged.',
    'version_restored' => ':from restored — as new version :version.',
    'restored_comment' => 'Restored from :version',
    'restored_from' => 'Restored from :version',

    'is_archived' => 'This document is archived — on :when by :who. Restore it before changing it.',
    'is_approved' => 'This document is approved — its details cannot be changed in place; a change is a new version.',
    'no_file' => 'This document has no file yet.',
    'no_preview' => 'This kind of file cannot be shown on the page — download it to open.',
    'current' => 'Current',

    'archive_confirm' => 'Archive this document? It leaves the centre but is not deleted.',
    'delete_confirm' => 'Delete this document? It leaves every list; the file and history are kept.',
    'restore_confirm' => 'Restore :version? It becomes a new version; no version is deleted.',
    'major_hint' => 'Major change — next main version (e.g. v1.2 to v2.0)',

    'file_too_big' => ':name is too large — the limit is :max.',
    'file_wrong_kind' => ':name was not accepted — only PDF, images, Word, Excel, PowerPoint or text files, and the content must match the file name.',
    'content_hint' => 'Searches the name, description, version comments and extra fields.',
    'restored_from_bin' => 'The document is back from the recycle bin, in its earlier state.',
    'purged' => 'The document was deleted for good. The audit trail keeps who did it and when.',
    'purge_confirm' => 'Delete for good? The file and every version cannot be brought back. The audit trail stays.',
    'access_saved' => 'Access saved.',
    'access_removed' => 'Access removed.',
    'access_hint' => 'Access given here is for this one document only — the person sees it even without the confidentiality level or department. It never opens another branch or company.',
    'access_none' => 'Nobody has been given access to this document on its own.',
    'access_remove_confirm' => 'Remove this access?',
    'admin_saved' => 'Saved.',
    'admin_subtitle' => 'Types, folders, tags and extra fields — your company\'s own',
    'admin_builtin_types' => 'The eleven built-in types (contract, certificate, invoice…) are always there; this lists only the ones you added.',
    'admin_builtin_categories' => 'The nine built-in folders (contracts, HR, finance…) are always there; this lists only the ones you added.',
    'admin_none' => 'Nothing added yet.',
    'tag_remove_confirm' => 'Remove this tag from the list? Documents that already carry it keep it.',
    'every_type' => 'Every document type',
    'storage_hint' => 'The limit and file kinds are changed in the control panel.',
    'meta_required' => 'Required for this document type.',
];
