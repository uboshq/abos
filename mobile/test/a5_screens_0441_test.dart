import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/theme/app_colors.dart';
import 'package:abos_mobile/core/theme/app_palette.dart';
import 'package:abos_mobile/core/theme/app_theme.dart';
import 'package:abos_mobile/features/home/app_status_chip_stand_in.dart';
import 'package:abos_mobile/features/home/owner_home_cards.dart';

import 'support/main_screens.dart' show setScreenSize;
import 'support/screens_0441.dart';

/// ⭐ ০.৪.৪১-এর দুই পর্দা — মালিকের হোম আর আদায়ের পাতা, মালিকের অনুমোদিত পুরো-পাতার নমুনা (২০ অক্টোবর ২০২৬, পাতা ২)।
///
/// দাবি: না-আসা অঙ্ক "—" (৳0.00 নয়) · মোট = সারির যোগফল · নাম "দোকান · এলাকা · শাখা" · চাপার জায়গা ৪৮dp · ২০০% অক্ষরে
/// ভাঙে না · অন্ধকারে থিমের রং · TalkBack বাংলায় টাকাসহ পড়ে · শেষ সারি FAB-এর নিচে ঢাকা পড়ে না।
void main() {
  Future<void> pump(WidgetTester tester, Widget body,
      {bool dark = false, double textScale = 1, Size size = const Size(360, 2400)}) async {
    setScreenSize(tester, size);
    // ⓘ আগের পর্দা ফেলে নতুন করে — নইলে TodayPanel আগের হিসাবই রাখে
    await tester.pumpWidget(const SizedBox());
    await tester.pumpWidget(framed(body, dark: dark, textScale: textScale));
    await tester.pumpAndSettle();
  }

  String textOf(WidgetTester tester, Key key) => tester.widget<Text>(find.byKey(key)).data!;

  group('মালিকের হোম', () {
    testWidgets('the card shows a dash for an absent figure, never ৳0.00; a sent zero stays ৳0.00', (tester) async {
      await pump(tester, ownerHomeBody(today: ownerToday(withCash: false)));
      expect(textOf(tester, const ValueKey('owner-money-cash-value')), '—');
      expect(textOf(tester, const ValueKey('owner-money-bank-value')), '৳11,66,320.00');
      expect(textOf(tester, const ValueKey('owner-money-in-value')), '+৳1,70,000.00');
      expect(textOf(tester, const ValueKey('owner-money-dues-value')), '৳8,45,210.00');
      expect(textOf(tester, const ValueKey('owner-money-total-value')), '৳11,66,320.00');
      expect(
          find.descendant(of: find.byKey(const ValueKey('owner-money-card')), matching: find.text('৳0.00')), findsNothing);

      await pump(tester, ownerHomeBody(today: ownerToday()));
      expect(textOf(tester, const ValueKey('owner-money-cash-value')), '৳0.00', reason: 'সার্ভার শূন্য পাঠালে শূন্য');
    });

    testWidgets('today\'s sales: the total equals the sum of the branch rows; without rows, the server\'s own total',
        (tester) async {
      await pump(tester, ownerHomeBody());
      expect(find.byKey(const ValueKey('branch-sale-ময়মনসিংহ')), findsOneWidget);
      expect(find.byKey(const ValueKey('branch-sale-নেত্রকোনা')), findsOneWidget);
      expect(
          find.descendant(of: find.byKey(const ValueKey('branch-sales-total')), matching: find.text('৳1,62,700.00')),
          findsOneWidget);

      final today = ownerToday();
      (today['salesByBranch'] as List).add({'branch': 'জামালপুর', 'amount': '0.10'});
      (today['salesByBranch'] as List).add({'branch': 'শেরপুর', 'amount': '0.20'});
      await pump(tester, ownerHomeBody(today: today));
      expect(
          find.descendant(of: find.byKey(const ValueKey('branch-sales-total')), matching: find.text('৳1,62,700.30')),
          findsOneWidget,
          reason: 'পয়সায় যোগ — ভগ্নাংশের ভুল নেই');

      await pump(tester, ownerHomeBody(today: ownerToday(withBranches: false)));
      expect(find.byKey(const ValueKey('branch-sale-ময়মনসিংহ')), findsNothing, reason: 'শাখার সারি বানানো হয় না');
      expect(
          find.descendant(of: find.byKey(const ValueKey('branch-sales-total')), matching: find.text('৳1,62,700.00')),
          findsOneWidget);
    });

    testWidgets('waiting papers: number · party with point and branch, the amount, a chip each', (tester) async {
      await pump(tester, ownerHomeBody());
      expect(find.text('সইয়ের অপেক্ষায় · 3টি'), findsOneWidget);
      expect(find.text('RCV-0190 · রহিম স্টোর · কেন্দুয়া · ময়মনসিংহ'), findsOneWidget);
      expect(find.textContaining('৳4,87,900.00'), findsOneWidget);
      expect(find.byType(AppStatusChip), findsNWidgets(3));
      expect(find.text('ধাপ 2'), findsOneWidget);
    });

    testWidgets('TalkBack reads the figures in Bengali words, and says an absent one is not known', (tester) async {
      final handle = tester.ensureSemantics();
      await pump(tester, ownerHomeBody(today: ownerToday(withCash: false)));
      expect(find.bySemanticsLabel('হাতে ও ব্যাংকে মোট, ১১ লাখ ৬৬ হাজার ৩২০ টাকা'), findsOneWidget);
      expect(find.bySemanticsLabel('হাতে নগদ, জানা নেই'), findsOneWidget);
      expect(find.bySemanticsLabel('মোট, ১ লাখ ৬২ হাজার ৭০০ টাকা'), findsOneWidget);
      expect(find.bySemanticsLabel(RegExp(r'^RCV-0190 · .*৫০ হাজার টাকা, অবস্থা: সইয়ের অপেক্ষায়, ধাপ 1$')), findsOneWidget);
      handle.dispose();
    });
  });

  group('আদায়ের পাতা', () {
    testWidgets('totals equal the rows: the collections bar and the shop dues card', (tester) async {
      await pump(tester, srCollectBody());
      expect(
          find.descendant(of: find.byKey(const ValueKey('totals-all')), matching: find.text('৳50,500.00')), findsOneWidget);
      expect(
          find.descendant(of: find.byKey(const ValueKey('shop-dues-total')), matching: find.text('৳69,975.57')),
          findsOneWidget);
      expect(find.text('মোট · 2টি দোকান'), findsOneWidget);
    });

    testWidgets('names carry point and branch, in the collections and in the dues', (tester) async {
      await pump(tester, srCollectBody());
      expect(find.text('মুসকান এন্টারপ্রাইজ · ফুলবাড়িয়া · ময়মনসিংহ'), findsNWidgets(2));
      expect(find.text('রহিম স্টোর · কেন্দুয়া · ময়মনসিংহ'), findsNWidgets(2));
      expect(find.text('নূর ট্রেডার্স · ত্রিশাল · ময়মনসিংহ'), findsOneWidget);
    });

    testWidgets('a chip on every row, read as "অবস্থা: পাকা"; the last row clears the FAB', (tester) async {
      final handle = tester.ensureSemantics();
      await pump(tester, srCollectBody());
      expect(find.byType(AppStatusChip), findsNWidgets(3));
      expect(find.bySemanticsLabel('অবস্থা: পাকা'), findsNWidgets(3));
      final list = tester.widget<ListView>(find.byType(ListView).first);
      expect((list.padding! as EdgeInsets).bottom, greaterThanOrEqualTo(88), reason: 'FAB ৫৬dp + ফাঁক');
      handle.dispose();
    });

    testWidgets('a shop due is read in Bengali words', (tester) async {
      final handle = tester.ensureSemantics();
      await pump(tester, srCollectBody());
      expect(find.bySemanticsLabel('মুসকান এন্টারপ্রাইজ · ফুলবাড়িয়া · ময়মনসিংহ, ৪৭ হাজার ৮৩৫ টাকা ৫৭ পয়সা'), findsOneWidget);
      handle.dispose();
    });
  });

  group('দুই পর্দা', () {
    final screens = <String, Widget Function()>{'owner home': ownerHomeBody, 'collections': srCollectBody};

    for (final s in screens.entries) {
      testWidgets('${s.key}: every tap target is 48dp or more (Android tap-target guideline)', (tester) async {
        final handle = tester.ensureSemantics();
        await pump(tester, s.value(), size: const Size(360, 2400));
        await expectLater(tester, meetsGuideline(androidTapTargetGuideline));
        // ⓘ আমার সারিগুলো নিজেরাও: টাকার লাইন আর কাগজের সারি
        for (final e in find.byType(AmountLine).evaluate()) {
          expect(tester.getSize(find.byWidget(e.widget)).height, greaterThanOrEqualTo(48));
        }
        handle.dispose();
      });

      for (final width in [360.0, 412.0]) {
        testWidgets('${s.key}: at 200% text on ${width.toInt()}dp nothing overflows', (tester) async {
          await pump(tester, s.value(), textScale: 2, size: Size(width, 3000));
          expect(tester.takeException(), isNull);
        });
      }

      testWidgets('${s.key}: in dark mode the theme\'s own colours, readable', (tester) async {
        await pump(tester, s.value(), dark: true);
        final ctx = tester.element(find.byType(AppStatusChip).first);
        final p = ctx.palette;
        final cs = AppTheme.dark().colorScheme;
        expect(Theme.of(ctx).brightness, Brightness.dark);
        final chips = find.byType(AppStatusChip);
        for (var i = 0; i < chips.evaluate().length; i++) {
          final box = tester.widget<Container>(find.descendant(of: chips.at(i), matching: find.byType(Container)).first);
          final bg = (box.decoration! as BoxDecoration).color!;
          final fg = tester.widget<Text>(find.descendant(of: chips.at(i), matching: find.byType(Text))).style!.color!;
          expect([p.successSurface, p.warningSurface], contains(bg));
          expect(AppColors.contrast(fg, bg), greaterThanOrEqualTo(4.5));
        }
        if (s.key == 'owner home') {
          final card = tester.widget<Card>(find.byKey(const ValueKey('owner-money-card')));
          expect(card.color, cs.primaryContainer);
          final total = tester.widget<Text>(find.byKey(const ValueKey('owner-money-total-value')));
          expect(AppColors.contrast(total.style!.color!, card.color!), greaterThanOrEqualTo(4.5));
        }
      });
    }

    testWidgets('owner home in light: the money card is the theme\'s primary with its own ink, 4.5:1', (tester) async {
      await pump(tester, ownerHomeBody());
      final cs = AppTheme.light().colorScheme;
      final card = tester.widget<Card>(find.byKey(const ValueKey('owner-money-card')));
      expect(card.color, cs.primary);
      final total = tester.widget<Text>(find.byKey(const ValueKey('owner-money-total-value')));
      expect(total.style!.color, cs.onPrimary);
      expect(AppColors.contrast(cs.onPrimary, cs.primary), greaterThanOrEqualTo(4.5));
    });
  });
}
