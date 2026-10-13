import 'dart:io';
import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:path_provider/path_provider.dart';
import 'package:share_plus/share_plus.dart';

import '../../core/api_client/network_errors.dart';
import '../../core/documents_library/library_api.dart';
import '../../core/navigation/recent_papers.dart';
import '../../core/theme/app_colors.dart';
import '../../core/theme/app_spacing.dart';
import '../../core/widgets/paged_list.dart';
import '../books/books_widgets.dart';
import '../printing/pdf_view_page.dart';

/// ⭐ ডকুমেন্টস, ফোনে — তালিকা, একটা কাগজ, তার ফাইল, আর যেখানে সই চাওয়া সেখানে সই (মালিকের আদেশ, ১৩ অক্টোবর ২০২৬)।
///
/// <p>⛔ ফোন কিছু ঠিক করে না: কে কোন কাগজ দেখেন, কে নামাতে পারেন, কে সই দেন — সব সার্ভারের উত্তর। "নামান" বোতাম কেবল
/// নামানোর অধিকার থাকলে, "সই দিন" কেবল সার্ভার অনুমোদনের আইডি দিলে।

String levelWords(String level) => switch (level) {
      'public' => 'সবার জন্য',
      'internal' => 'অভ্যন্তরীণ',
      'confidential' => 'গোপন',
      'highly_confidential' => 'অতি গোপন',
      'restricted' => 'সংরক্ষিত',
      _ => level,
    };

String documentStatusWords(String status) => switch (status) {
      'draft' => 'খসড়া',
      'submitted' => 'জমা হয়েছে',
      'under_review' => 'পর্যালোচনায়',
      'changes_requested' => 'বদল চাওয়া হয়েছে',
      'approved' => 'অনুমোদিত',
      'rejected' => 'বাতিল',
      'published' => 'প্রকাশিত',
      'published_unapproved' => 'অনুমোদন ছাড়া প্রকাশিত',
      'expired' => 'মেয়াদোত্তীর্ণ',
      'archived' => 'আর্কাইভে',
      _ => status,
    };

String signatureWords(String state) => switch (state) {
      'pending' => 'সইয়ের অপেক্ষায়',
      'signed' => 'সই হয়েছে — চলতি ভার্সনে',
      'stale' => 'সই পুরনো ভার্সনে — চলতিটায় সই নেই',
      _ => 'সই চাওয়া হয়নি',
    };

Color _levelColour(String level) => switch (level) {
      'confidential' || 'highly_confidential' || 'restricted' => AppColors.danger,
      _ => AppColors.onSurfaceMuted,
    };

class DocumentListScreen extends StatefulWidget {
  const DocumentListScreen({super.key, this.api = const ServerDocumentLibraryApi()});

  final DocumentLibraryApi api;

  @override
  State<DocumentListScreen> createState() => _DocumentListScreenState();
}

class _DocumentListScreenState extends State<DocumentListScreen> {
  String _view = 'center';
  String _q = '';
  final _search = TextEditingController();

  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: const Text('ডকুমেন্টস')),
        body: PagedList<LibraryDocument>(
          reloadKey: '$_view/$_q',
          load: (page) async {
            final p = await widget.api.list(view: _view, q: _q, page: page);
            return (p.rows, p.nextPage);
          },
          failed: 'কাগজের তালিকা আনা গেল না। নিচে টেনে আবার চেষ্টা করুন।',
          whenAbsent: 'সার্ভারে ডকুমেন্টস এখনো আসেনি — অফিসে জানান।',
          emptyIcon: Icons.folder_open_outlined,
          emptyTitle: 'এখানে দেখার মতো কোনো কাগজ নেই',
          header: [
            TextField(
              key: const ValueKey('doc-search'),
              controller: _search,
              textInputAction: TextInputAction.search,
              decoration: const InputDecoration(prefixIcon: Icon(Icons.search), hintText: 'নাম, নম্বর বা ট্যাগ'),
              onSubmitted: (v) => setState(() => _q = v.trim()),
            ),
            const SizedBox(height: AppSpacing.sm),
            TabChips(
              tabs: const {'center': 'সব', 'mine': 'আমার', 'shared': 'আমার সাথে শেয়ার', 'expiry': 'মেয়াদ'},
              selected: _view,
              onSelected: (v) => setState(() => _view = v),
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
                    [levelWords(d.level), if (d.expiryDate != null) 'মেয়াদ ${dayOf(d.expiryDate)}'].join(' · '),
                    style: TextStyle(color: _levelColour(d.level)),
                  ),
                ],
              ),
              onTap: () => Navigator.of(context)
                  .push(MaterialPageRoute<void>(builder: (_) => DocumentScreen(id: d.id, api: widget.api))),
            ),
          ),
        ),
      );
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
    setState(() => _busy = true);
    try {
      final bytes = await widget.api.file(d.id, download: download);
      if (!mounted) return;
      await (widget.opener ?? _defaultOpener)(context, file, bytes, d.name);
    } catch (e) {
      _say(errorMessageFor(e, fallback: 'ফাইলটা খোলা গেল না।'));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _sign(String approvalId) async {
    final remarks = TextEditingController();
    final ok = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('এই কাগজে সই দেবেন?'),
        content: TextField(
          key: const ValueKey('sign-remarks'),
          controller: remarks,
          decoration: const InputDecoration(labelText: 'মন্তব্য (ইচ্ছা হলে)'),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('না')),
          FilledButton(key: const ValueKey('sign-confirm'), onPressed: () => Navigator.pop(context, true), child: const Text('সই দিন')),
        ],
      ),
    );
    if (ok != true) return;
    await _decide(() => widget.api.sign(approvalId, remarks: remarks.text.trim().isEmpty ? null : remarks.text.trim()),
        done: 'সই দেওয়া হয়েছে।');
  }

  Future<void> _refuse(String approvalId) async {
    final reason = TextEditingController();
    final ok = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('সই দেবেন না?'),
        content: TextField(
          key: const ValueKey('refuse-reason'),
          controller: reason,
          decoration: const InputDecoration(labelText: 'কারণ লিখুন'),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('ফিরে যান')),
          FilledButton(
              key: const ValueKey('refuse-confirm'),
              style: FilledButton.styleFrom(backgroundColor: AppColors.danger),
              onPressed: () => Navigator.pop(context, true),
              child: const Text('সই দেব না')),
        ],
      ),
    );
    if (ok != true) return;
    if (reason.text.trim().isEmpty) {
      _say('সই না দেওয়ার কারণ লিখতে হবে।');
      return;
    }
    await _decide(() => widget.api.refuse(approvalId, remarks: reason.text.trim()), done: 'সই দেওয়া হয়নি — কারণসহ জানানো হয়েছে।');
  }

  Future<void> _decide(Future<void> Function() call, {required String done}) async {
    setState(() => _busy = true);
    try {
      await call();
      _say(done);
      if (mounted) setState(() => _reload++);
    } catch (e) {
      _say(errorMessageFor(e, fallback: 'সিদ্ধান্তটা পাঠানো গেল না। আবার চেষ্টা করুন।'));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  void _say(String text) {
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(text)));
  }

  @override
  Widget build(BuildContext context) => PaperPage<LibraryDocument>(
        key: ValueKey(_reload),
        title: 'কাগজ',
        titleOf: (d) => d.name,
        load: () => widget.api.one(widget.id),
        failed: 'কাগজটা আনা গেল না।',
        onLoaded: (d) => RecentPapers.note(kind: 'document', id: widget.id, number: d.no),
        body: (context, d) {
          final file = d.file;
          final sig = d.signature;
          return [
            if (_busy) const LinearProgressIndicator(),
            FactRow('নম্বর', d.no),
            if (d.typeName.isNotEmpty) FactRow('ধরন', d.typeName),
            if (d.folderName.isNotEmpty) FactRow('ফোল্ডার', d.folderName),
            FactRow('গোপনীয়তা', levelWords(d.level), colour: _levelColour(d.level)),
            FactRow('অবস্থা', documentStatusWords(d.status)),
            if (d.documentDate != null) FactRow('তারিখ', dayOf(d.documentDate)),
            if (d.expiryDate != null) FactRow('মেয়াদ', dayOf(d.expiryDate)),
            if (d.owner.isNotEmpty) FactRow('মালিক', d.owner),
            if (d.tags.isNotEmpty) FactRow('ট্যাগ', d.tags.join(', ')),
            if (d.description.isNotEmpty) FactRow('বিবরণ', d.description),
            const SectionTitle('ফাইল'),
            if (file == null)
              const Text('এই কাগজে এখনো কোনো ফাইল নেই।', style: TextStyle(color: AppColors.onSurfaceMuted))
            else ...[
              FactRow('নাম', file.name),
              FactRow('ভার্সন', file.version),
              Wrap(
                spacing: AppSpacing.sm,
                children: [
                  if (file.inline)
                    FilledButton.icon(
                      key: const ValueKey('doc-view'),
                      onPressed: _busy ? null : () => _open(d, download: false),
                      icon: const Icon(Icons.visibility_outlined),
                      label: const Text('দেখুন'),
                    ),
                  if (file.download)
                    OutlinedButton.icon(
                      key: const ValueKey('doc-download'),
                      onPressed: _busy ? null : () => _open(d, download: true),
                      icon: const Icon(Icons.download_outlined),
                      label: const Text('নামান / পাঠান'),
                    ),
                ],
              ),
              if (!file.inline && !file.download)
                const Text('এই ফাইলটা ফোনে দেখা যায় না, আর নামানোর অধিকার আপনার নেই।',
                    style: TextStyle(color: AppColors.onSurfaceMuted)),
            ],
            if (sig != null) ...[
              const SectionTitle('সই'),
              FactRow('অবস্থা', signatureWords(sig.state),
                  colour: sig.state == 'signed' ? AppColors.success : sig.state == 'pending' ? AppColors.warning : null),
              for (final s in sig.signed)
                FactRow(s.by, '${dayOf(s.at)}${s.current ? '' : ' · পুরনো ভার্সনে'}'),
              if (sig.approvalId != null)
                Card(
                  key: const ValueKey('doc-sign-card'),
                  color: AppColors.warningSurface,
                  child: Padding(
                    padding: const EdgeInsets.all(AppSpacing.md),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        const Text('এই কাগজে আপনার সই চাওয়া হয়েছে। ফাইলটা দেখে সই দিন।',
                            style: TextStyle(fontWeight: FontWeight.w600)),
                        const SizedBox(height: AppSpacing.sm),
                        FilledButton.icon(
                          key: const ValueKey('doc-sign'),
                          onPressed: _busy ? null : () => _sign(sig.approvalId!),
                          icon: const Icon(Icons.draw_outlined),
                          label: const Text('সই দিন'),
                        ),
                        TextButton(
                          key: const ValueKey('doc-refuse'),
                          onPressed: _busy ? null : () => _refuse(sig.approvalId!),
                          child: const Text('সই দেব না'),
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

/// PDF পাতার ভিতরে, ছবি পর্দায়, বাকি সব পাঠানোর শিটে
Future<void> _defaultOpener(BuildContext context, LibraryFile file, Uint8List bytes, String title) async {
  if (file.isPdf) {
    await PdfViewPage.open(context, pdf: bytes, title: title);
    return;
  }
  if (file.isImage) {
    await Navigator.of(context).push(MaterialPageRoute<void>(
      builder: (_) => Scaffold(
        appBar: AppBar(title: Text(title, overflow: TextOverflow.ellipsis)),
        body: InteractiveViewer(child: Center(child: Image.memory(bytes))),
      ),
    ));
    return;
  }
  final dir = await getTemporaryDirectory();
  final name = file.name.isEmpty ? 'document' : file.name.replaceAll(RegExp(r'[\\/]'), '_');
  final path = '${dir.path}/$name';
  await File(path).writeAsBytes(bytes, flush: true);
  await Share.shareXFiles([XFile(path, mimeType: file.mime.isEmpty ? null : file.mime, name: name)]);
}
