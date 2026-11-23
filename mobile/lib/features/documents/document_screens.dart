import '../../core/printing/paper_files.dart';
import 'dart:io';
import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:share_plus/share_plus.dart';

import '../../core/api_client/network_errors.dart';
import '../../core/documents_library/library_api.dart';
import '../../core/locale/l10n.dart';
import '../../core/navigation/recent_papers.dart';
import '../../core/theme/app_palette.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/widgets/paged_list.dart';
import '../../core/widgets/search_debouncer.dart';
import '../books/books_widgets.dart';
import '../printing/pdf_view_page.dart';
import '../../core/navigation/top_bar/abos_screen_bar.dart';

/// ⭐ ডকুমেন্টস, ফোনে — তালিকা, একটা কাগজ, তার ফাইল, আর যেখানে সই চাওয়া সেখানে সই (মালিকের আদেশ, ১৩ অক্টোবর ২০২৬)।
///
/// <p>⛔ ফোন কিছু ঠিক করে না: কে কোন কাগজ দেখেন, কে নামাতে পারেন, কে সই দেন — সব সার্ভারের উত্তর। "নামান" বোতাম কেবল
/// নামানোর অধিকার থাকলে, "সই দিন" কেবল সার্ভার অনুমোদনের আইডি দিলে।

String levelWords(AppL10n w, String level) => switch (level) {
      'public' => w.documentsLevelPublic,
      'internal' => w.documentsLevelInternal,
      'confidential' => w.documentsLevelConfidential,
      'highly_confidential' => w.documentsLevelHighlyConfidential,
      'restricted' => w.documentsLevelRestricted,
      _ => level,
    };

/// ⓘ "rejected" — fe-র শব্দ "প্রত্যাখ্যাত" (আগে "বাতিল", যা cancelled-এর শব্দ; শব্দকোষ, ২১ অক্টোবর ২০২৬)
String documentStatusWords(AppL10n w, String status) => switch (status) {
      'draft' => w.documentsStatusDraft,
      'submitted' => w.documentsStatusSubmitted,
      'under_review' => w.documentsStatusUnderReview,
      'changes_requested' => w.documentsStatusChangesRequested,
      'approved' => w.documentsStatusApproved,
      'rejected' => w.documentsStatusRejected,
      'published' => w.documentsStatusPublished,
      'published_unapproved' => w.documentsStatusPublishedUnapproved,
      'expired' => w.documentsStatusExpired,
      'archived' => w.documentsStatusArchived,
      _ => status,
    };

String signatureWords(AppL10n w, String state) => switch (state) {
      'pending' => w.documentsSignaturePending,
      'signed' => w.documentsSignatureSigned,
      'stale' => w.documentsSignatureStale,
      _ => w.documentsSignatureNone,
    };

Color _levelColour(BuildContext context, String level) => switch (level) {
      'confidential' || 'highly_confidential' || 'restricted' => context.palette.danger,
      _ => context.palette.onSurfaceMuted,
    };

class DocumentListScreen extends StatefulWidget {
  const DocumentListScreen({super.key, this.api = const ServerDocumentLibraryApi(), this.initialView = 'center'});

  final DocumentLibraryApi api;

  /// ⭐ মেনুর কোন সারি থেকে খোলা — কেন্দ্র, আমার, শেয়ার, মেয়াদ, সাম্প্রতিক, আর্কাইভ, বিন, খোঁজ (মালিক, ১৭ অক্টোবর ২০২৬)
  final String initialView;

  /// ওয়েবের আটটা তালিকা, মেনুর ক্রমে
  static const List<String> viewKeys = ['center', 'mine', 'shared', 'recent', 'expiry', 'archive', 'recycle', 'search'];

  static Map<String, String> viewsIn(AppL10n w) => {
        'center': w.documentsViewAll,
        'mine': w.documentsViewMine,
        'shared': w.documentsViewShared,
        'recent': w.documentsViewRecent,
        'expiry': w.documentsViewExpiry,
        'archive': w.documentsViewArchive,
        'recycle': w.documentsViewRecycle,
        'search': w.documentsViewSearch,
      };

  @override
  State<DocumentListScreen> createState() => _DocumentListScreenState();
}

class _DocumentListScreenState extends State<DocumentListScreen> {
  late String _view = DocumentListScreen.viewKeys.contains(widget.initialView) ? widget.initialView : 'center';
  String _q = '';
  late final _typed = SearchDebouncer(onSearch: (q) => setState(() => _q = q));
  final _search = TextEditingController();

  /// ⭐ গোটা তালিকার গোনা — সার্ভারের, পাতার নয়
  final _count = ValueNotifier<int?>(null);

  @override
  void dispose() {
    _search.dispose();
    _typed.dispose();
    _count.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final w = context.l10n;
    return Scaffold(
        appBar: AbosScreenBar(title: w.documentsTitle),
        body: PagedList<LibraryDocument>(
          reloadKey: '$_view/$_q',
          load: (page) async {
            final p = await widget.api.list(view: _view, q: _q, page: page);
            if (page == 1) _count.value = p.count;
            return (p.rows, p.nextPage);
          },
          failed: w.documentsListFailed,
          whenAbsent: w.documentsListAbsent,
          emptyIcon: Icons.folder_open_outlined,
          emptyTitle: w.documentsListEmpty,
          header: [
            TextField(
              key: const ValueKey('doc-search'),
              controller: _search,
              textInputAction: TextInputAction.search,
              decoration: InputDecoration(prefixIcon: const Icon(Icons.search), hintText: w.documentsSearchHint),
              onChanged: _typed.onChanged,
              onSubmitted: _typed.onSubmitted,
            ),
            const SizedBox(height: AppSpacing.sm),
            TabChips(
              tabs: DocumentListScreen.viewsIn(w),
              selected: _view,
              onSelected: (v) => setState(() => _view = v),
            ),
            ValueListenableBuilder<int?>(
              valueListenable: _count,
              builder: (context, count, _) => count == null
                  ? const SizedBox.shrink()
                  : Padding(
                      padding: const EdgeInsets.only(top: AppSpacing.xs),
                      child: Text(w.documentsCount(count),
                          key: const ValueKey('doc-count'), style: const TextStyle(fontWeight: FontWeight.w600)),
                    ),
            ),
          ],
          item: (context, d) => Card(
            margin: const EdgeInsets.only(bottom: AppSpacing.xs),
            child: ListTile(
              key: ValueKey('doc-${d.id}'),
              leading: const Icon(Icons.description_outlined),
              title: Text(d.name, style: const TextStyle(fontWeight: FontWeight.w600)),
              subtitle: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text([d.no, if (d.typeName.isNotEmpty) d.typeName, if (d.folderName.isNotEmpty) d.folderName].join(' · '),
                      maxLines: 1, overflow: TextOverflow.ellipsis),
                  Text(
                    [levelWords(w, d.level), if (d.expiryDate != null) w.documentsExpires(dayOf(d.expiryDate))].join(' · '),
                    style: TextStyle(color: _levelColour(context, d.level)),
                  ),
                  if (d.deleted)
                    Text(w.documentsDeleted, style: TextStyle(color: context.palette.danger)),
                ],
              ),
              // ⓘ বিনের কাগজ খোলা যায় না — সার্ভারের দরজা ৪০৪ দিত
              onTap: d.deleted
                  ? null
                  : () => Navigator.of(context)
                      .push(MaterialPageRoute<void>(builder: (_) => DocumentScreen(id: d.id, api: widget.api))),
            ),
          ),
        ),
      );
  }
}

/// ফাইলটা কীভাবে খোলা হবে — পরীক্ষায় বদলানো যায়
typedef FileOpener = Future<void> Function(BuildContext context, LibraryFile file, Uint8List bytes, String title);

class DocumentScreen extends StatefulWidget {
  const DocumentScreen({super.key, required this.id, this.api = const ServerDocumentLibraryApi(), this.opener});

  final String id;
  final DocumentLibraryApi api;
  final FileOpener? opener;

  @override
  State<DocumentScreen> createState() => _DocumentScreenState();
}

class _DocumentScreenState extends State<DocumentScreen> {
  int _reload = 0;
  bool _busy = false;

  Future<void> _open(LibraryDocument d, {required bool download}) async {
    final file = d.file;
    if (file == null) return;
    final failed = context.l10n.documentsFileOpenFailed;
    setState(() => _busy = true);
    try {
      final bytes = await widget.api.file(d.id, download: download);
      if (!mounted) return;
      await (widget.opener ?? _defaultOpener)(context, file, bytes, d.name);
    } catch (e) {
      _say(errorMessageFor(e, fallback: failed));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _sign(String approvalId) async {
    final remarks = TextEditingController();
    final w = context.l10n;
    final ok = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text(w.documentsSignAsk),
        content: TextField(
          key: const ValueKey('sign-remarks'),
          controller: remarks,
          decoration: InputDecoration(labelText: w.documentsRemarksOptional),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context, false), child: Text(w.documentsNo)),
          FilledButton(key: const ValueKey('sign-confirm'), onPressed: () => Navigator.pop(context, true), child: Text(w.documentsSign)),
        ],
      ),
    );
    if (ok != true) return;
    await _decide(() => widget.api.sign(approvalId, remarks: remarks.text.trim().isEmpty ? null : remarks.text.trim()),
        done: w.documentsSigned);
  }

  Future<void> _refuse(String approvalId) async {
    final reason = TextEditingController();
    final w = context.l10n;
    final ok = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text(w.documentsRefuseAsk),
        content: TextField(
          key: const ValueKey('refuse-reason'),
          controller: reason,
          decoration: InputDecoration(labelText: w.documentsReasonField),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context, false), child: Text(w.documentsGoBack)),
          FilledButton(
              key: const ValueKey('refuse-confirm'),
              style: FilledButton.styleFrom(backgroundColor: context.palette.danger),
              onPressed: () => Navigator.pop(context, true),
              child: Text(w.documentsRefuse)),
        ],
      ),
    );
    if (ok != true) return;
    if (reason.text.trim().isEmpty) {
      _say(w.documentsReasonNeeded);
      return;
    }
    await _decide(() => widget.api.refuse(approvalId, remarks: reason.text.trim()), done: w.documentsRefused);
  }

  Future<void> _decide(Future<void> Function() call, {required String done}) async {
    final failed = context.l10n.documentsDecisionFailed;
    setState(() => _busy = true);
    try {
      await call();
      _say(done);
      if (mounted) setState(() => _reload++);
    } catch (e) {
      _say(errorMessageFor(e, fallback: failed));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  void _say(String text) {
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(text)));
  }

  @override
  Widget build(BuildContext context) {
    final w = context.l10n;
    return PaperPage<LibraryDocument>(
        key: ValueKey(_reload),
        title: w.documentsPaper,
        titleOf: (d) => d.name,
        load: () => widget.api.one(widget.id),
        failed: w.documentsOneFailed,
        onLoaded: (d) => RecentPapers.note(kind: 'document', id: widget.id, number: d.no),
        body: (context, d) {
          final file = d.file;
          final sig = d.signature;
          return [
            if (_busy) const LinearProgressIndicator(),
            FactRow(w.documentsNumber, d.no),
            if (d.typeName.isNotEmpty) FactRow(w.documentsType, d.typeName),
            if (d.folderName.isNotEmpty) FactRow(w.documentsFolder, d.folderName),
            FactRow(w.documentsLevel, levelWords(w, d.level), colour: _levelColour(context, d.level)),
            FactRow(w.documentsStatus, documentStatusWords(w, d.status)),
            if (d.documentDate != null) FactRow(w.documentsDate, dayOf(d.documentDate)),
            if (d.expiryDate != null) FactRow(w.documentsExpiry, dayOf(d.expiryDate)),
            if (d.owner.isNotEmpty) FactRow(w.documentsOwner, d.owner),
            if (d.tags.isNotEmpty) FactRow(w.documentsTags, d.tags.join(', ')),
            if (d.description.isNotEmpty) FactRow(w.documentsDescription, d.description),
            SectionTitle(w.documentsFile),
            if (file == null)
              Text(w.documentsNoFile, style: TextStyle(color: context.palette.onSurfaceMuted))
            else ...[
              FactRow(w.documentsFileName, file.name),
              FactRow(w.documentsVersion, file.version),
              Wrap(
                spacing: AppSpacing.sm,
                children: [
                  if (file.inline)
                    FilledButton.icon(
                      key: const ValueKey('doc-view'),
                      onPressed: _busy ? null : () => _open(d, download: false),
                      icon: const Icon(Icons.visibility_outlined),
                      label: Text(w.documentsViewFile),
                    ),
                  if (file.download)
                    OutlinedButton.icon(
                      key: const ValueKey('doc-download'),
                      onPressed: _busy ? null : () => _open(d, download: true),
                      icon: const Icon(Icons.download_outlined),
                      label: Text(w.documentsDownload),
                    ),
                ],
              ),
              if (!file.inline && !file.download)
                Text(w.documentsCannotOpen,
                    style: TextStyle(color: context.palette.onSurfaceMuted)),
            ],
            if (sig != null) ...[
              SectionTitle(w.documentsSignature),
              FactRow(w.documentsStatus, signatureWords(w, sig.state),
                  colour: sig.state == 'signed' ? context.palette.success : sig.state == 'pending' ? context.palette.warning : null),
              for (final s in sig.signed)
                FactRow(s.by, s.current ? dayOf(s.at) : w.documentsSignedOld(dayOf(s.at))),
              if (sig.approvalId != null)
                Card(
                  key: const ValueKey('doc-sign-card'),
                  color: context.palette.warningSurface,
                  child: Padding(
                    padding: const EdgeInsets.all(AppSpacing.md),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        Text(w.documentsSignWanted,
                            style: const TextStyle(fontWeight: FontWeight.w600)),
                        const SizedBox(height: AppSpacing.sm),
                        FilledButton.icon(
                          key: const ValueKey('doc-sign'),
                          onPressed: _busy ? null : () => _sign(sig.approvalId!),
                          icon: const Icon(Icons.draw_outlined),
                          label: Text(w.documentsSign),
                        ),
                        TextButton(
                          key: const ValueKey('doc-refuse'),
                          onPressed: _busy ? null : () => _refuse(sig.approvalId!),
                          child: Text(w.documentsRefuse),
                        ),
                      ],
                    ),
                  ),
                ),
            ],
          ];
        },
      );
  }
}

/// PDF পাতার ভিতরে, ছবি পর্দায়, বাকি সব পাঠানোর শিটে
Future<void> _defaultOpener(BuildContext context, LibraryFile file, Uint8List bytes, String title) async {
  if (file.isPdf) {
    await PdfViewPage.open(context, pdf: bytes, title: title);
    return;
  }
  if (file.isImage) {
    await Navigator.of(context).push(MaterialPageRoute<void>(
      builder: (_) => Scaffold(
        appBar: AbosScreenBar(title: title),
        body: InteractiveViewer(child: Center(child: Image.memory(bytes))),
      ),
    ));
    return;
  }
  final dir = await PaperFiles.cache();
  final name = PaperFiles.safeName(file.name);
  final path = '${dir.path}/$name';
  await File(path).writeAsBytes(bytes, flush: true);
  await Share.shareXFiles([XFile(path, mimeType: file.mime.isEmpty ? null : file.mime, name: name)]);
}
