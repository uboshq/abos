import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/widgets/customer_line.dart';
import 'package:abos_mobile/core/approvals/approvals_api.dart';
import 'package:abos_mobile/core/records/approval_record.dart';
import 'package:abos_mobile/core/theme/app_colors.dart';
import 'package:abos_mobile/core/theme/app_theme.dart';
import 'package:abos_mobile/core/status/app_status_chip.dart';
import 'package:abos_mobile/core/status/paper_state.dart';
import 'package:abos_mobile/features/books/money_in_screens.dart';
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
          find.descendant(of: find.byKey(const ValueKey('owner-money-cash')), matching: find.text('৳0.00')), findsNothing);
      // ⓘ ০.৪.৪০-এর অঙ্ক ফেরত (২০ অক্টোবর ২০২৬): সার্ভারের পাঠানো শূন্য MFS আর পথে ৳0.00-ই দেখায়
      expect(textOf(tester, const ValueKey('owner-money-mfs-value')), '৳0.00');
      expect(textOf(tester, const ValueKey('owner-money-transit-value')), '৳0.00');

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

    testWidgets('the branch card says what the figure is (gross bills, not net sales) and names a branchless row', (tester) async {
      // ⓘ fe, ২০ অক্টোবর ২০২৬: অঙ্কটা ফেরত বাদ না দেওয়া মোট বিল — "আজ বিল হয়েছে"; IFRS 15-এ "বিক্রি" মানে ফেরত বাদে নিট
      final today = ownerToday();
      today['salesByBranch'] = <Object?>[...(today['salesByBranch'] as List), <String, Object?>{'branch_id': null, 'branch': null, 'amount': '0.50'}];
      await pump(tester, ownerHomeBody(today: today));
      expect(find.text('আজ বিল হয়েছে'), findsOneWidget);
      expect(find.text('আজকের বিক্রি'), findsNothing, reason: '⛔ মোট বিলকে "বিক্রি" বলা — ফেরত বাদ যায়নি');
      expect(find.byKey(const ValueKey('branch-sale-শাখা নেই')), findsOneWidget, reason: '⛔ শাখাহীন বিলের সারির নাম নেই');
      expect(
          find.descendant(of: find.byKey(const ValueKey('branch-sales-total')), matching: find.text('৳1,62,700.50')),
          findsOneWidget,
          reason: 'শাখাহীন সারিও মোটে গোনা');
    });

    testWidgets('waiting papers: number · party with point and branch, the amount, a chip each', (tester) async {
      await pump(tester, ownerHomeBody());
      expect(find.text('অনুমোদনের অপেক্ষায় · 3টি'), findsOneWidget);
      // ⓘ নমুনার মতো: প্রথম লাইনে নম্বর · নাম, নিচে পয়েন্ট · শাখা · টাকা
      expect(find.byWidgetPredicate((w) => w is NumberAndName && w.number == 'RCV-0190' && w.name == 'রহিম স্টোর'), findsOneWidget);
      expect(find.text('কেন্দুয়া · ময়মনসিংহ · ৳50,000.00'), findsOneWidget);
      expect(find.text('ময়মনসিংহ · ৳4,87,900.00'), findsOneWidget);
      final chips = tester.widgetList<AppStatusChip>(find.byType(AppStatusChip)).toList();
      expect(chips, hasLength(3));
      expect(chips.every((c) => c.state == PaperState.waiting && c.dense), isTrue, reason: 'সারিতে a6-এর compact চিপ');
      // ⚠️ সার্ভার মোট ধাপ পাঠায় না → "অনুমোদনের অপেক্ষায়"; পাঠালে "2-এর 1"
      expect(find.descendant(of: find.byType(AppStatusChip), matching: find.textContaining('অনুমোদনের অপেক্ষায়', findRichText: true)), findsNWidgets(3));
    });

    testWidgets('waiting papers: when the server sends the number of levels, the chip says "2-এর 1"', (tester) async {
      await pump(tester, ownerHomeBody(loadWaiting: () async => waitingWithLevels));
      expect(find.textContaining('2-এর 1', findRichText: true), findsOneWidget);
    });

    // ⓘ কার্ডে প্রথম তিনটে সারি — তিন ধরনের একটা করে
    testWidgets('chip v4: each waiting row tells the chip its paper kind (claim, stock, money)', (tester) async {
      await pump(tester, ownerHomeBody(loadWaiting: () async => const ApprovalPage(rows: [
            ApprovalRecord({'id': 'k1', 'documentType': 'Collection', 'documentNo': 'RCV-1', 'amount': '10.0000', 'currentLevel': 1}),
            ApprovalRecord({'id': 'k2', 'documentType': 'ExpenseClaim', 'documentNo': 'EXP-1', 'amount': '10.0000', 'currentLevel': 1}),
            ApprovalRecord({'id': 'k3', 'documentType': 'DeliveryChallan', 'documentNo': 'CH-1', 'amount': '10.0000', 'currentLevel': 1}),
          ])));
      PaperKind kindIn(String id) => tester
          .widget<AppStatusChip>(find.descendant(of: find.byKey(ValueKey('waiting-$id')), matching: find.byType(AppStatusChip)))
          .kind;
      expect(kindIn('k1'), PaperKind.money);
      expect(kindIn('k2'), PaperKind.claim);
      expect(kindIn('k3'), PaperKind.stock);
    });

    test('paperKindOf: claims, stock papers and money papers by the documentType the server sends', () {
      expect(paperKindOf('ExpenseClaim'), PaperKind.claim);
      expect(paperKindOf('expense_claim'), PaperKind.claim);
      for (final t in ['Challan', 'DeliveryChallan', 'DeliveryOrder', 'StockTransfer', 'GoodsReceipt']) {
        expect(paperKindOf(t), PaperKind.stock, reason: t);
      }
      for (final t in ['Collection', 'PurchaseBill', 'Voucher', 'Invoice', '', null]) {
        expect(paperKindOf(t), PaperKind.money, reason: '$t');
      }
    });

    testWidgets('a row reads number · party on the left and the chip on the right, on one row', (tester) async {
      await pump(tester, ownerHomeBody());
      final row = find.byKey(const ValueKey('waiting-a1'));
      final line = tester.getRect(find.descendant(of: row, matching: find.byWidgetPredicate((w) => w is NumberAndName && w.number == 'RCV-0190' && w.name == 'রহিম স্টোর')));
      final chip = tester.getRect(find.descendant(of: row, matching: find.byType(AppStatusChip)));
      expect(chip.left, greaterThan(line.left));
      expect(chip.right, closeTo(tester.getRect(row).right, 1), reason: 'চিপ ডানের কিনারে');
      expect(chip.top, lessThan(tester.getRect(row).bottom));
      expect(chip.bottom, greaterThan(tester.getRect(row).top));
    });

    testWidgets('TalkBack reads the figures in Bengali words, and says an absent one is not known', (tester) async {
      final handle = tester.ensureSemantics();
      await pump(tester, ownerHomeBody(today: ownerToday(withCash: false)));
      expect(find.bySemanticsLabel('হাতে ও ব্যাংকে মোট, ১১ লাখ ৬৬ হাজার ৩২০ টাকা'), findsOneWidget);
      expect(find.bySemanticsLabel('হাতে নগদ, জানা নেই'), findsOneWidget);
      expect(find.bySemanticsLabel('মোট, ১ লাখ ৬২ হাজার ৭০০ টাকা'), findsOneWidget);
      expect(find.bySemanticsLabel('RCV-0190 · রহিম স্টোর · কেন্দুয়া · ময়মনসিংহ, ৫০ হাজার টাকা'), findsOneWidget);
      expect(find.bySemanticsLabel('অবস্থা: অনুমোদনের অপেক্ষায়'), findsNWidgets(3), reason: 'a6-এর চিপ নিজেই পুরো বাক্য বলে');
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
      final handle = tester.ensureSemantics();
      await pump(tester, srCollectBody());
      // আদায়: প্রথম লাইনে নম্বর · নাম, নিচে পয়েন্ট · শাখা; TalkBack পুরো নাম বলে
      expect(find.byWidgetPredicate((w) => w is NumberAndName && w.number == 'RCV-0191' && w.name == 'মুসকান এন্টারপ্রাইজ'), findsOneWidget);
      expect(find.textContaining('ফুলবাড়িয়া · ময়মনসিংহ ·'), findsOneWidget);
      expect(find.bySemanticsLabel(RegExp(r'^RCV-0193 · নূর ট্রেডার্স · ত্রিশাল · ময়মনসিংহ, ')), findsOneWidget);
      // বকেয়া: এক লাইনে পুরো নাম
      expect(find.text('মুসকান এন্টারপ্রাইজ · ফুলবাড়িয়া · ময়মনসিংহ'), findsOneWidget);
      expect(find.text('রহিম স্টোর · কেন্দুয়া · ময়মনসিংহ'), findsOneWidget);
      handle.dispose();
    });

    testWidgets('a chip on every row, read as "অবস্থা: পোস্ট হয়েছে" (chip v5, the word fe chose); the FAB sits in the bottom bar, below the list', (tester) async {
      final handle = tester.ensureSemantics();
      // ⓘ "＋ নতুন আদায়" সহ — বোতাম নিচের বারে (০.৪.৪৮), তালিকার নিচে আর ৮৮dp ফাঁক লাগে না
      await pump(tester, srCollectBody(canReportDeposit: true));
      final chips = tester.widgetList<AppStatusChip>(find.byType(AppStatusChip)).toList();
      expect(chips, hasLength(3));
      expect(chips.every((c) => c.state == PaperState.posted && c.dense && c.kind == PaperKind.money), isTrue,
          reason: 'চিপ v4: আদায় টাকার কাগজ');
      expect(find.bySemanticsLabel('অবস্থা: পোস্ট হয়েছে, খাতায় উঠেছে'), findsNWidgets(3));
      final fab = find.byKey(const ValueKey('money-in-deposit-report'));
      expect(find.ancestor(of: fab, matching: find.byKey(const ValueKey('action-bar'))), findsOneWidget,
          reason: 'বোতাম নিচের বারে, তালিকার ওপরে ভাসে না');
      expect(tester.getRect(fab).top, greaterThanOrEqualTo(tester.getRect(find.byType(ListView).first).bottom),
          reason: 'তালিকা বোতামের ওপরেই শেষ');
      handle.dispose();
    });

    testWidgets('a collection still on the phone shows first, chip "পাঠানো বাকি"', (tester) async {
      await pump(tester,
          srCollectBody(unsent: () => const [UnsentCollection('মুসকান এন্টারপ্রাইজ · ফুলবাড়িয়া · ময়মনসিংহ', 30000)]));
      final row = find.byKey(const ValueKey('money-in-unsent-0'));
      final chip = tester.widget<AppStatusChip>(find.descendant(of: row, matching: find.byType(AppStatusChip)));
      expect(chip.state, PaperState.unsent);
      expect(chip.kind, PaperKind.money);
      expect(find.descendant(of: row, matching: find.textContaining('পাঠানো বাকি', findRichText: true)), findsOneWidget);
      expect(tester.getTopLeft(row).dy, lessThan(tester.getTopLeft(find.byKey(const ValueKey('money-in-RCV-0191'))).dy));
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
          if (s.key == 'owner home') {
            // ⓘ বড় অক্ষরে টাকার ঘর এক কলামে — অঙ্ক মাঝখানে ভাঙে না
            final cash = tester.getTopLeft(find.byKey(const ValueKey('owner-money-cash')));
            final bank = tester.getTopLeft(find.byKey(const ValueKey('owner-money-bank')));
            expect(bank.dx, cash.dx);
            expect(bank.dy, greaterThan(cash.dy));
          }
        });
      }

      testWidgets('${s.key}: in dark mode the theme\'s own colours, readable', (tester) async {
        await pump(tester, s.value(), dark: true);
        final ctx = tester.element(find.byType(AppStatusChip).first);
        final cs = AppTheme.dark().colorScheme;
        expect(Theme.of(ctx).brightness, Brightness.dark);
        final chips = find.byType(AppStatusChip);
        for (var i = 0; i < chips.evaluate().length; i++) {
          final chip = tester.widget<AppStatusChip>(chips.at(i));
          final dark = chip.state.spec.colours.of(Brightness.dark);
          final box = tester.widget<Container>(find.descendant(of: chips.at(i), matching: find.byType(Container)).first);
          expect((box.decoration! as BoxDecoration).color, dark.background, reason: 'অন্ধকারের চিপ-রং');
          expect(AppColors.contrast(dark.foreground, dark.background), greaterThanOrEqualTo(4.5));
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
