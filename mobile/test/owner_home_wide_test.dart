import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/widgets/day_part.dart';
import 'package:abos_mobile/features/home/owner_home_cards.dart';

import 'support/main_screens.dart' show setScreenSize;
import 'support/screens_0441.dart';

/// ⭐ মালিকের হোম, বড় পর্দা আর "আজ"-এর লাইন (fe, ২০ অক্টোবর ২০২৬):
/// - Material 3-এর "expanded" জানালায় (৮৪০dp বা বেশি) টাকার কার্ড পুরো চওড়ায়, তার নিচে সইয়ের অপেক্ষা আর আজকের বিল পাশাপাশি;
///   ছোট পর্দায় একটার নিচে আরেকটা।
/// - একা "আজ" শিরোনাম নেই — "আজ · হিসাব 20/10/2026 সকাল 10:00 পর্যন্ত" এক লাইনে।
void main() {
  Future<void> pump(WidgetTester tester, Size size) async {
    setScreenSize(tester, size);
    await tester.pumpWidget(framed(ownerHomeBody()));
    await tester.pumpAndSettle();
  }

  testWidgets('a wide window puts the waiting papers and the bills side by side under the full-width money card',
      (tester) async {
    await pump(tester, const Size(1280, 800));
    final waiting = tester.getRect(find.byType(WaitingPapersCard));
    final bills = tester.getRect(find.byKey(const ValueKey('branch-sales')));
    final money = tester.getRect(find.byType(OwnerMoneyCard));
    final pair = tester.getRect(find.byKey(const ValueKey('home-wide-pair')));
    expect(waiting.top, bills.top, reason: 'এক সারিতে');
    expect(waiting.right, lessThan(bills.left), reason: 'বাঁয়ে সইয়ের অপেক্ষা, ডানে বিল');
    expect(waiting.width, closeTo(bills.width, 1), reason: 'সমান দুই কলাম');
    expect(money.width, closeTo(pair.width, 1), reason: 'টাকার কার্ড পুরো চওড়ায়, জোড়া তার নিচে একই চওড়ায়');
    expect(money.bottom, lessThanOrEqualTo(pair.top));
  });

  testWidgets('a phone and a portrait tablet (under 840dp) keep one card under the other', (tester) async {
    for (final size in const [Size(412, 915), Size(800, 1280)]) {
      await pump(tester, size);
      expect(find.byKey(const ValueKey('home-wide-pair')), findsNothing, reason: '$size');
      final bills = tester.getRect(find.byKey(const ValueKey('branch-sales')));
      expect(bills.width, greaterThan(size.width - 64), reason: '$size: পুরো চওড়ায়');
    }
  });

  testWidgets('"আজ" sits on the as-of line, not alone above it', (tester) async {
    await pump(tester, const Size(412, 915));
    final asOf = banglaStamp(DateTime.parse('2026-10-20T10:30:00+06:00').toLocal());
    expect(find.text('আজ · হিসাব $asOf পর্যন্ত', findRichText: true), findsOneWidget);
    expect(find.text('আজ'), findsNothing, reason: '⛔ একা "আজ" শিরোনাম');
  });
}
