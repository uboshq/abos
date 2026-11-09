import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:printing/printing.dart';

import '../../core/locale/l10n.dart';
import '../../core/theme/app_palette.dart';
import '../../core/theme/app_spacing.dart';

/// ⭐ সংযুক্তি অ্যাপের ভেতরেই — মালিক, ৮ নভেম্বর ২০২৬: *"sonzukti gulo dawnloads nakorei vew er bebosta app ei rakte hobe"*;
/// নমুনা "অনুমোদনের নতুন ইনবক্স" v4, ছবি ২ আর ৩ ("ok")।
///
/// <p>কাগজের পাতায় প্রতিটা সংযুক্তির ছোট ছবি (PDF-এর প্রথম পাতা, ছবি নিজেই); চাপলে অন্ধকার পুরো পর্দায় খোলে — দুই আঙুলে বড়,
/// পাশে সরালে পরের ফাইল, ঘোরানো। ⓘ ফাইল কেবল স্মৃতিতে থাকে (বাইট), ফোনে কোথাও জমা হয় না।
/// ⚠️ Excel/Word এখন অ্যাপে দেখা যায় না — সার্ভার PDF বানালে যাবে (নমুনার "নতুন বানাতে হবে"); ততদিন ছোট ছবির জায়গায় ধরন আর
/// স্পষ্ট লেখা, চুপচাপ ফাঁকা নয়। PowerPoint অ্যাপে খোলে না — সেটাও স্পষ্ট লেখা।
enum AttachmentKind { pdf, image, office, slides, other }

AttachmentKind attachmentKindOf(String name) {
  final lower = name.toLowerCase();
  if (lower.endsWith('.pdf')) return AttachmentKind.pdf;
  if (RegExp(r'\.(jpe?g|png|webp|gif|bmp|heic)$').hasMatch(lower)) return AttachmentKind.image;
  if (RegExp(r'\.(xlsx?|docx?|csv|ods|odt)$').hasMatch(lower)) return AttachmentKind.office;
  if (RegExp(r'\.(pptx?|odp)$').hasMatch(lower)) return AttachmentKind.slides;
  return AttachmentKind.other;
}

/// ফাইলের ধরনের ছোট চিহ্ন — "PDF", "JPG", "XLSX"
String attachmentBadge(String name) {
  final dot = name.lastIndexOf('.');
  return dot < 0 || dot == name.length - 1 ? '•' : name.substring(dot + 1).toUpperCase();
}

/// তিন কলামে ছোট ছবি — নমুনার "সংযুক্তি · 3টা  চাপলে অ্যাপেই খোলে"
class AttachmentThumbs extends StatelessWidget {
  const AttachmentThumbs({super.key, required this.files, required this.load, required this.onOpen});

  /// (id, নাম, আকার)
  final List<(int, String, String)> files;
  final Future<Uint8List> Function(int id) load;
  final void Function(int index) onOpen;

  @override
  Widget build(BuildContext context) => GridView.count(
        crossAxisCount: 3,
        shrinkWrap: true,
        physics: const NeverScrollableScrollPhysics(),
        mainAxisSpacing: AppSpacing.sm,
        crossAxisSpacing: AppSpacing.sm,
        childAspectRatio: 0.82,
        children: [
          for (final (i, (id, name, size)) in files.indexed)
            InkWell(
              key: ValueKey('approval-thumb-$id'),
              borderRadius: BorderRadius.circular(10),
              onTap: () => onOpen(i),
              child: Column(children: [
                Expanded(child: _Thumb(id: id, name: name, load: load)),
                const SizedBox(height: 4),
                Text(size.isEmpty ? name : '$name · $size',
                    maxLines: 1, overflow: TextOverflow.ellipsis, style: TextStyle(fontSize: 11.5, color: context.palette.onSurfaceMuted)),
              ]),
            ),
        ],
      );
}

class _Thumb extends StatefulWidget {
  const _Thumb({required this.id, required this.name, required this.load});

  final int id;
  final String name;
  final Future<Uint8List> Function(int id) load;

  @override
  State<_Thumb> createState() => _ThumbState();
}

class _ThumbState extends State<_Thumb> {
  late final Future<Uint8List?> _picture = _make();

  Future<Uint8List?> _make() async {
    final kind = attachmentKindOf(widget.name);
    if (kind != AttachmentKind.pdf && kind != AttachmentKind.image) return null;
    try {
      final bytes = await widget.load(widget.id);
      if (bytes.isEmpty) return null;
      if (kind == AttachmentKind.image) return bytes;
      // ⓘ PDF-এর প্রথম পাতা, কম রেজোলিউশনে — ছোট ছবির জন্য যথেষ্ট, স্মৃতি বাঁচে
      await for (final page in Printing.raster(bytes, pages: const [0], dpi: 36)) {
        return await page.toPng();
      }
    } catch (_) {
      return null;
    }
    return null;
  }

  @override
  Widget build(BuildContext context) {
    final palette = context.palette;
    final badge = attachmentBadge(widget.name);
    final colour = switch (attachmentKindOf(widget.name)) {
      AttachmentKind.pdf => const Color(0xFFC62828),
      AttachmentKind.image => const Color(0xFF0D9488),
      AttachmentKind.office => const Color(0xFF15803D),
      _ => palette.onSurfaceMuted,
    };
    return ClipRRect(
      borderRadius: BorderRadius.circular(10),
      child: DecoratedBox(
        decoration: BoxDecoration(border: Border.all(color: palette.border), borderRadius: BorderRadius.circular(10), color: palette.surface),
        child: Stack(fit: StackFit.expand, children: [
          FutureBuilder<Uint8List?>(
            future: _picture,
            builder: (context, snap) => snap.data == null
                ? Center(child: Icon(Icons.insert_drive_file_outlined, color: palette.onSurfaceMuted))
                : Image.memory(snap.data!, fit: BoxFit.cover, gaplessPlayback: true,
                    // ⓘ ভাঙা ছবি — চিহ্ন দেখায়, পর্দা ভাঙে না
                    errorBuilder: (_, __, ___) => Center(child: Icon(Icons.broken_image_outlined, color: palette.onSurfaceMuted))),
          ),
          Positioned(
            right: 5,
            bottom: 5,
            child: Container(
              padding: const EdgeInsets.symmetric(horizontal: 5, vertical: 1),
              decoration: BoxDecoration(color: colour, borderRadius: BorderRadius.circular(5)),
              child: Text(badge, style: const TextStyle(color: Colors.white, fontSize: 10, fontWeight: FontWeight.w700)),
            ),
          ),
        ]),
      ),
    );
  }
}

/// ⭐ পুরো পর্দার দেখার জায়গা — নমুনার ছবি ৩: অন্ধকার পট, মাথায় "✕ নাম  ১ / ৩", মাঝে পাতা (দুই আঙুলে বড়), পাশে সরালে পরের ফাইল,
/// নিচে ছোট ফালি আর "↻ ঘোরান"। ফোনে কিছু জমা হয় না।
class AttachmentViewer extends StatefulWidget {
  const AttachmentViewer({super.key, required this.files, required this.start, required this.load});

  final List<(int, String, String)> files;
  final int start;
  final Future<Uint8List> Function(int id) load;

  static Future<void> open(BuildContext context, {required List<(int, String, String)> files, required int start, required Future<Uint8List> Function(int id) load}) =>
      Navigator.of(context).push(MaterialPageRoute<void>(builder: (_) => AttachmentViewer(files: files, start: start, load: load)));

  @override
  State<AttachmentViewer> createState() => _AttachmentViewerState();
}

class _AttachmentViewerState extends State<AttachmentViewer> {
  late final PageController _pages = PageController(initialPage: widget.start);
  late int _at = widget.start;
  int _turns = 0;

  @override
  void dispose() {
    _pages.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final words = context.l10n;
    final (_, name, _) = widget.files[_at];
    return Theme(
      data: ThemeData.dark(),
      child: Scaffold(
        key: const ValueKey('attachment-viewer'),
        backgroundColor: const Color(0xFF0B0F14),
        appBar: AppBar(
          backgroundColor: const Color(0xFF0B0F14),
          leading: IconButton(key: const ValueKey('attachment-viewer-close'), icon: const Icon(Icons.close), onPressed: () => Navigator.of(context).pop()),
          title: Text(name, key: const ValueKey('attachment-viewer-name'), maxLines: 1, overflow: TextOverflow.ellipsis),
          actions: [
            Center(
              child: Padding(
                padding: const EdgeInsets.only(right: AppSpacing.md),
                child: Text('${_at + 1} / ${widget.files.length}', key: const ValueKey('attachment-viewer-count')),
              ),
            ),
          ],
        ),
        body: Column(children: [
          Expanded(
            child: PageView.builder(
              controller: _pages,
              itemCount: widget.files.length,
              onPageChanged: (i) => setState(() {
                _at = i;
                _turns = 0;
              }),
              itemBuilder: (_, i) => RotatedBox(
                quarterTurns: i == _at ? _turns : 0,
                child: _Page(id: widget.files[i].$1, name: widget.files[i].$2, load: widget.load),
              ),
            ),
          ),
          Padding(
            padding: const EdgeInsets.symmetric(vertical: AppSpacing.xs),
            child: Text(words.attachmentViewerHint, style: const TextStyle(fontSize: 12, color: Color(0xBFE8EEF4))),
          ),
          SafeArea(
            top: false,
            child: Padding(
              padding: const EdgeInsets.fromLTRB(AppSpacing.md, 0, AppSpacing.md, AppSpacing.sm),
              child: Row(children: [
                Expanded(
                  child: OutlinedButton.icon(
                    key: const ValueKey('attachment-viewer-rotate'),
                    onPressed: () => setState(() => _turns = (_turns + 1) % 4),
                    icon: const Icon(Icons.rotate_right),
                    label: Text(words.attachmentViewerRotate),
                  ),
                ),
              ]),
            ),
          ),
        ]),
      ),
    );
  }
}

class _Page extends StatefulWidget {
  const _Page({required this.id, required this.name, required this.load});

  final int id;
  final String name;
  final Future<Uint8List> Function(int id) load;

  @override
  State<_Page> createState() => _PageState();
}

class _PageState extends State<_Page> {
  late final AttachmentKind _kind = attachmentKindOf(widget.name);
  late final Future<Uint8List>? _bytes = _kind == AttachmentKind.pdf || _kind == AttachmentKind.image ? widget.load(widget.id) : null;

  @override
  Widget build(BuildContext context) {
    final words = context.l10n;
    if (_bytes == null) {
      final say = switch (_kind) {
        AttachmentKind.office => words.attachmentViewerOffice,
        AttachmentKind.slides => words.attachmentViewerSlides,
        _ => words.attachmentViewerOther,
      };
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(AppSpacing.lg),
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            const Icon(Icons.description_outlined, size: 56, color: Color(0xBFE8EEF4)),
            const SizedBox(height: AppSpacing.sm),
            Text(say, key: ValueKey('attachment-viewer-cannot-${widget.id}'), textAlign: TextAlign.center, style: const TextStyle(color: Color(0xFFE8EEF4))),
          ]),
        ),
      );
    }
    return FutureBuilder<Uint8List>(
      future: _bytes,
      builder: (context, snap) {
        if (snap.hasError) {
          return Center(child: Text(words.approvalsAttachmentFailed, style: const TextStyle(color: Color(0xFFE8EEF4))));
        }
        final bytes = snap.data;
        if (bytes == null) return const Center(child: CircularProgressIndicator());
        if (_kind == AttachmentKind.image) {
          return InteractiveViewer(
            key: ValueKey('attachment-viewer-image-${widget.id}'),
            maxScale: 6,
            child: Center(child: Image.memory(bytes, errorBuilder: (_, __, ___) => Text(words.approvalsAttachmentFailed))),
          );
        }
        return PdfPreview(
          key: ValueKey('attachment-viewer-pdf-${widget.id}'),
          build: (_) async => bytes,
          allowPrinting: false,
          allowSharing: false,
          canChangePageFormat: false,
          canChangeOrientation: false,
          canDebug: false,
          useActions: false,
          scrollViewDecoration: const BoxDecoration(color: Color(0xFF0B0F14)),
        );
      },
    );
  }
}
