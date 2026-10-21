import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/widgets/paged_list.dart';
import 'package:abos_mobile/features/home/home_shell.dart' show avatarImage;

/// ⭐ লম্বা তালিকা আটকায় না — ২০০০ সারিতেও কেবল পর্দায় যতটা আসে ততটা বানায় (Android vitals: ধীর ফ্রেম;
/// পরিকল্পনা ৪, অংশ ২; ২০ অক্টোবর ২০২৬)।
void main() {
  testWidgets('a 2000-row list builds only the rows on screen, and still reaches the last one', (tester) async {
    tester.view.physicalSize = const Size(390, 844);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    var built = 0;
    final rows = [for (var i = 1; i <= 2000; i++) 'INV-$i'];

    await tester.pumpWidget(MaterialApp(
      home: Scaffold(
        body: PagedList<String>(
          load: (_) async => (rows, null),
          item: (_, r) {
            built++;
            return SizedBox(height: 72, child: Text(r, key: ValueKey(r)));
          },
          emptyTitle: 'কিছু নেই',
          failed: 'আনা গেল না',
          header: const [Text('মোট', key: ValueKey('head'))],
        ),
      ),
    ));
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('head')), findsOneWidget, reason: 'মাথা আগের মতো উপরে');
    expect(find.text('INV-1'), findsOneWidget);
    expect(built, lessThan(40), reason: '⛔ ২০০০ সারির সবগুলো একসাথে বানানো হয়েছে ($built)');

    await tester.scrollUntilVisible(find.text('INV-2000'), 5000, scrollable: find.byType(Scrollable).first);
    expect(find.text('INV-2000'), findsOneWidget);
  });

  test("a person's photo opens at the size it is shown, not the size it was taken", () {
    final image = avatarImage('https://erp.adi.com.bd/storage/a.jpg');
    expect(image, isA<ResizeImage>());
    expect((image! as ResizeImage).width, lessThanOrEqualTo(160));
    expect(avatarImage(null), isNull);
    // ⓘ মানুষের ছবি যেখানেই (ওপরের বার, ছবির মেনু, প্রোফাইল) — সব এই এক পথে; গোটা অ্যাপে কাঁচা NetworkImage কেবল avatarImage-এর ভেতরে
    final raw = <String>[];
    for (final f in Directory('lib').listSync(recursive: true).whereType<File>().where((f) => f.path.endsWith('.dart'))) {
      final n = RegExp(r'NetworkImage\(').allMatches(f.readAsStringSync()).length;
      for (var i = 0; i < n; i++) {
        raw.add(f.path.replaceAll(r'\', '/'));
      }
    }
    // ⓘ ২০ অক্টোবর ২০২৬ (a6): avatarImage-এর ভেতরেও আর NetworkImage নেই — টোকেনসহ FaceImage (test/profile/face_image_test.dart)
    expect(raw, isEmpty, reason: '⛔ মানুষের ছবি পুরো মাপে, টোকেন আর pinning ছাড়া খোলা হচ্ছে');
  });
}
