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
    // ⓘ মাথা আর প্রোফাইল — দুই জায়গাই এই পথে; কাঁচা NetworkImage আর নেই
    final shell = File('lib/features/home/home_shell.dart').readAsStringSync();
    expect(RegExp(r'NetworkImage\(').allMatches(shell).length, 1, reason: 'কেবল avatarImage-এর ভেতরে');
    expect(RegExp(r'avatarImage\(avatarUrl\)').allMatches(shell).length, 2);
  });
}
