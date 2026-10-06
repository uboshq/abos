import 'package:abos_mobile/core/records/notice_bar.dart';
import 'package:abos_mobile/features/home/notice_ticker.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// ⭐ ওয়েবের চলমান নোটিশ, ফোনে (0.4.16, মালিক ৬ অক্টোবর ২০২৬) — কিছু না থাকলে চুপ, থাকলে চলে, চাপলে পুরোটা।
Future<void> _pump(
    WidgetTester tester, Future<List<NoticeBarItem>> Function() fetch,
    {double width = 360, bool still = false}) async {
  tester.view.physicalSize = Size(width, 800);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
  await tester.pumpWidget(MaterialApp(
    home: MediaQuery(
      data: MediaQueryData(size: Size(width, 800), disableAnimations: still),
      child: Scaffold(body: Column(children: [NoticeTicker(fetch: fetch)])),
    ),
  ));
  await tester.pump();
  await tester.pump(const Duration(milliseconds: 50));
}

const _long = [
  NoticeBarItem(
      id: 'n1',
      title: 'আগামীকাল শুক্রবার গুদাম বন্ধ থাকবে, মাল তোলা হবে শনিবার সকালে'),
  NoticeBarItem(id: 'n2', title: 'নতুন দামের তালিকা সোমবার থেকে চালু'),
];

double _shift(WidgetTester tester) => tester
    .widget<Transform>(find.descendant(
        of: find.byKey(const ValueKey('notice-ticker')),
        matching: find.byType(Transform)))
    .transform
    .getTranslation()
    .x;

void main() {
  testWidgets('nothing on the bar: the line stays quiet, no empty strip',
      (tester) async {
    await _pump(tester, () async => const []);
    expect(find.byKey(const ValueKey('notice-ticker')), findsNothing);
    expect(find.byKey(const ValueKey('notice-ticker-quiet')), findsOneWidget);
  });

  testWidgets('no signal or an older server: quiet, not a crash',
      (tester) async {
    await _pump(tester, () async => throw Exception('offline'));
    expect(find.byKey(const ValueKey('notice-ticker')), findsNothing);
  });

  testWidgets(
      'the web\'s titles run in the web\'s order, and a tap shows them whole',
      (tester) async {
    await _pump(tester, () async => _long);

    final line = tester
        .widgetList<Text>(find.byKey(const ValueKey('notice-ticker-text')))
        .first
        .data!;
    expect(line.indexOf('গুদাম বন্ধ'), lessThan(line.indexOf('দামের তালিকা')),
        reason: '⛔ ক্রম ওয়েবের নয় — জরুরিটা আগে আসে সার্ভার থেকে');

    final before = _shift(tester);
    await tester.pump(const Duration(seconds: 2));
    expect(_shift(tester), lessThan(before), reason: '⛔ লম্বা লাইন চলল না');

    await tester.tap(find.byKey(const ValueKey('notice-ticker')));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 500));
    expect(find.byKey(const ValueKey('notice-ticker-sheet')), findsOneWidget);
    for (final n in _long) {
      expect(
          find.descendant(
              of: find.byKey(const ValueKey('notice-ticker-sheet')),
              matching: find.text(n.title)),
          findsOneWidget);
    }
  });

  testWidgets('a short line that fits stands still', (tester) async {
    await _pump(tester, () async => const [NoticeBarItem(title: 'ছুটি')],
        width: 800);
    expect(find.byKey(const ValueKey('notice-ticker')), findsOneWidget);
    expect(find.byType(OverflowBox), findsNothing,
        reason: 'ধরে যাওয়া লাইন চলছে');
  });

  testWidgets('with motion switched off on the phone, the line does not move',
      (tester) async {
    await _pump(tester, () async => _long, still: true);
    expect(find.byKey(const ValueKey('notice-ticker')), findsOneWidget);
    expect(find.byType(OverflowBox), findsNothing,
        reason: '⛔ নড়াচড়া বন্ধ ফোনেও পট্টি চলল (ওয়েবের motion-safe)');
  });
}
