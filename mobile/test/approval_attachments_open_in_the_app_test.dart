import 'dart:typed_data';

import 'package:abos_mobile/features/approvals/approval_attachments.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';


/// ⭐ সংযুক্তি অ্যাপের ভেতরেই — মালিক, ৮ নভেম্বর ২০২৬: *"sonzukti gulo dawnloads nakorei vew er bebosta app ei rakte hobe"*; নমুনা v4,
/// ছবি ২ আর ৩। দাবি: প্রতিটা সংযুক্তির ছোট ছবির ঘর ধরনসহ; চাপলে অ্যাপের ভেতরে পুরো পর্দা, "১ / ৩", পাশে সরালে পরের ফাইল;
/// Excel/Word আর PowerPoint চুপচাপ ফাঁকা নয় — স্পষ্ট লেখা; একই ফাইল একবারই আনা হয়।
void main() {
  const files = <(int, String, String)>[
    (41, 'ব্যাংক-অ্যাডভাইস.pdf', '120 KB'),
    (42, 'বিল.jpg', '80 KB'),
    (43, 'হিসাব.xlsx', '30 KB'),
    (44, 'সভা.pptx', '2 MB'),
  ];

  test('each file is known by its kind and badge', () {
    expect(attachmentKindOf('a.PDF'), AttachmentKind.pdf);
    expect(attachmentKindOf('b.jpeg'), AttachmentKind.image);
    expect(attachmentKindOf('c.xlsx'), AttachmentKind.office);
    expect(attachmentKindOf('d.docx'), AttachmentKind.office);
    expect(attachmentKindOf('e.pptx'), AttachmentKind.slides);
    expect(attachmentKindOf('f.zip'), AttachmentKind.other);
    expect(attachmentBadge('হিসাব.xlsx'), 'XLSX');
  });

  testWidgets('a thumb per file opens the in-app viewer at that file; a swipe moves to the next; office files say so plainly', (tester) async {
    final asked = <int>[];
    Future<Uint8List> load(int id) async {
      asked.add(id);
      return Uint8List(0);
    }

    await tester.pumpWidget(MaterialApp(home: Scaffold(
      body: Builder(
        builder: (context) => AttachmentThumbs(
          files: files,
          load: load,
          onOpen: (i) => AttachmentViewer.open(context, files: files, start: i, load: load),
        ),
      ),
    )));
    await tester.pumpAndSettle();

    for (final (id, _, _) in files) {
      expect(find.byKey(ValueKey('approval-thumb-$id')), findsOneWidget, reason: '⛔ সংযুক্তি $id-এর ছোট ছবির ঘর নেই');
    }
    expect(find.text('XLSX'), findsOneWidget, reason: '⛔ ছোট ছবিতে ফাইলের ধরন নেই');
    expect(asked.toSet(), {41, 42}, reason: '⛔ কেবল PDF আর ছবির ছোট ছবি আনা হয় — Excel/PowerPoint আনা হল, বা PDF/ছবি আনা হল না');

    await tester.tap(find.byKey(const ValueKey('approval-thumb-43')));
    // ⓘ নির্দিষ্ট সময় ধরে — PDF-এর পাতা আঁকার ঘুরন্ত চিহ্ন কখনো "থিতু" হয় না, তাই pumpAndSettle সময় পেরিয়ে দাবির আগেই ভাঙত
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 600));
    expect(find.byKey(const ValueKey('attachment-viewer')), findsOneWidget, reason: '⛔ সংযুক্তি চাপলে অ্যাপের ভেতরে খোলে না');
    expect(tester.widget<Text>(find.byKey(const ValueKey('attachment-viewer-count'))).data, '3 / 4', reason: '⛔ যে ফাইলে চাপা হল সেখান থেকে শুরু নয়');
    expect(find.byKey(const ValueKey('attachment-viewer-cannot-43')), findsOneWidget, reason: '⛔ Excel ফাইলে কী হবে বলে না — চুপচাপ ফাঁকা');

    await tester.fling(find.byType(PageView), const Offset(-500, 0), 1500);
    await tester.pumpAndSettle();
    expect(tester.widget<Text>(find.byKey(const ValueKey('attachment-viewer-count'))).data, '4 / 4', reason: '⛔ পাশে সরালে পরের ফাইল আসে না');
    expect(tester.widget<Text>(find.byKey(const ValueKey('attachment-viewer-name'))).data, 'সভা.pptx');
    expect(find.byKey(const ValueKey('attachment-viewer-cannot-44')), findsOneWidget, reason: '⛔ PowerPoint খোলে না — সেটা বলে না');

    await tester.tap(find.byKey(const ValueKey('attachment-viewer-close')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('attachment-viewer')), findsNothing);
  });
}
