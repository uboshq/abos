import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/auth/auth_user.dart';
import 'package:abos_mobile/core/menu/module_gate.dart';

import 'support/main_screens.dart' show setScreenSize;
import 'support/screens_0441.dart';

/// ⭐ ০.৪.৪০-এর মালিকের হোমের অঙ্ক ফেরত (মালিক, ২০ অক্টোবর ২০২৬: "MFS অ্যাপের হোমে দেখায় না, আগে দেখাত")।
///
/// দাবি: MFS আর পথে শূন্যেও দেখায় · মোট = নগদ + MFS + ব্যাংক, পথে নয় · না-আসা অঙ্ক "—" · "আজ গেছে" কেবল চাবি এলে ·
/// MFS চাপলে খাতের তালিকা আর মোট · দেনা হাতধারসহ · প্রিন্সিপালের কমিশন (বড় তিন, সবার মোট) · শাখার কার্ডে বিক্রির লাইন ·
/// ০.৪.৪০-এর প্রতিটা অঙ্ক হোমে · ২০০% অক্ষরে ৩৬০dp-তে ভাঙে না।
void main() {
  Future<void> pump(WidgetTester tester, Widget body, {double textScale = 1, Size size = const Size(360, 3200)}) async {
    setScreenSize(tester, size);
    await tester.pumpWidget(const SizedBox());
    await tester.pumpWidget(framed(body, textScale: textScale));
    await tester.pumpAndSettle();
  }

  /// মালিক, প্রিন্সিপালের কমিশনের চাবিসহ — মেনুতে পাতাটা আছে
  const reporter =
      AuthUser(id: 'u1', name: 'আল-আমিন শুভ', email: 'o@x', roles: ['Owner'], permissions: ['supplier.report']);

  String textOf(WidgetTester tester, Key key) => tester.widget<Text>(find.byKey(key)).data!;

  const principals = [
    {'name': 'ACI', 'period': '', 'basisRate': '2%', 'inflow': '300000.0000', 'commission': '6000.0000', 'paid': '0', 'balance': '50000.0000'},
    {'name': 'স্কয়ার', 'period': '', 'basisRate': '2%', 'inflow': '120000.0000', 'commission': '2400.0000', 'paid': '0', 'balance': '-80000.0000'},
    {'name': 'প্রাণ', 'period': '', 'basisRate': '2%', 'inflow': '90000.0000', 'commission': '1800.0000', 'paid': '0', 'balance': '20000.0000'},
    {'name': 'বসুন্ধরা', 'period': '', 'basisRate': '2%', 'inflow': '5000.0000', 'commission': '100.0000', 'paid': '0', 'balance': '1000.0000'},
  ];
  const mfsAccounts = [
    {'name': 'বিকাশ', 'balance': '1234.56'},
    {'name': 'নগদ', 'balance': '78.90'},
  ];

  /// ০.৪.৪০-এর মতো পুরো হিসাব — সব চাবি
  Map<String, dynamic> full() => ownerToday(
        outflow: '45000.0000',
        stockValue: '2345678.9000',
        payable: {'amount': '1250000.0000', 'handLoans': '200000.0000'},
        principals: principals,
        mfsAccounts: mfsAccounts,
        sales: {
          'amount': '162700.0000', 'count': 9, 'billed': '170000.0000', 'returned': '7300.0000', 'vat': '24405.0000',
        },
        mfs: '1313.4600',
        transit: '5000.0000',
        moneyTotal: '1167633.4600',
      );

  // ⭐ শাখার কার্ডের নাম সার্ভারের — নিট পাঠালে "নিট বিক্রি", পুরনো সার্ভারে "আজ বিল হয়েছে"; নাম আর অঙ্ক এক কথা বলে
  testWidgets('the branch card is named by the server: net sales say so, an old server says bills', (tester) async {
    final net = ownerToday();
    net['sales'] = {...(net['sales'] as Map), 'label': 'নিট বিক্রি'};
    await pump(tester, ownerHomeBody(today: net));
    expect(find.descendant(of: find.byKey(const ValueKey('branch-sales')), matching: find.text('নিট বিক্রি')), findsOneWidget);

    await tester.pumpWidget(const SizedBox());
    await pump(tester, ownerHomeBody(today: ownerToday()));
    expect(find.descendant(of: find.byKey(const ValueKey('branch-sales')), matching: find.text('আজ বিল হয়েছে')), findsOneWidget);
  });

  testWidgets('1 · MFS and on-the-road show even at zero; the total is cash + MFS + bank, never + transit',
      (tester) async {
    await pump(tester, ownerHomeBody(today: ownerToday()));
    expect(textOf(tester, const ValueKey('owner-money-mfs-value')), '৳0.00');
    expect(textOf(tester, const ValueKey('owner-money-transit-value')), '৳0.00');
    expect(find.text('MFS'), findsOneWidget);
    expect(find.text('পথে'), findsOneWidget);

    await pump(tester, ownerHomeBody(today: full()));
    expect(textOf(tester, const ValueKey('owner-money-transit-value')), '৳5,000.00');
    expect(textOf(tester, const ValueKey('owner-money-total-value')), '৳11,67,633.46',
        reason: '০ + ১,৩১৩.৪৬ + ১১,৬৬,৩২০ — পথের ৳5,000.00 মোটে নেই');
  });

  testWidgets('2 · an absent figure is "—"; no outflow key means no "আজ গেছে" cell; a sent one has a minus',
      (tester) async {
    final today = ownerToday(withCash: false);
    (today['money'] as Map).remove('mfs');
    await pump(tester, ownerHomeBody(today: today));
    expect(textOf(tester, const ValueKey('owner-money-cash-value')), '—');
    expect(textOf(tester, const ValueKey('owner-money-mfs-value')), '—');
    expect(find.text('আজ গেছে'), findsNothing);
    expect(find.byKey(const ValueKey('owner-money-out')), findsNothing, reason: '⛔ পুরনো সার্ভারে "—"-ওয়ালা ঘর');

    await pump(tester, ownerHomeBody(today: ownerToday(outflow: '45000.0000')));
    expect(find.text('আজ গেছে'), findsOneWidget);
    expect(textOf(tester, const ValueKey('owner-money-out-value')), '-৳45,000.00');
  });

  testWidgets('3 · tapping MFS opens its accounts with a total equal to the rows; without the list it is not tappable',
      (tester) async {
    await pump(tester, ownerHomeBody(today: full()));
    expect(tester.getSize(find.byKey(const ValueKey('owner-money-mfs-tap'))).height, greaterThanOrEqualTo(48));
    await tester.tap(find.byKey(const ValueKey('owner-money-mfs-tap')));
    await tester.pumpAndSettle();
    expect(find.text('MFS খাত'), findsOneWidget);
    final sheet = find.byKey(const ValueKey('mfs-accounts-sheet'));
    for (final (name, amount) in [('বিকাশ', '৳1,234.56'), ('নগদ', '৳78.90')]) {
      expect(find.descendant(of: sheet, matching: find.text(name)), findsOneWidget);
      expect(find.descendant(of: sheet, matching: find.text(amount)), findsOneWidget);
    }
    expect(
        find.descendant(of: find.byKey(const ValueKey('mfs-accounts-total')), matching: find.text('৳1,313.46')),
        findsOneWidget);

    await pump(tester, ownerHomeBody(today: ownerToday(mfs: '1313.4600')));
    expect(find.byKey(const ValueKey('owner-money-mfs-tap')), findsNothing);
    await tester.tap(find.byKey(const ValueKey('owner-money-mfs')));
    await tester.pumpAndSettle();
    expect(find.text('MFS খাত'), findsNothing, reason: 'পুরনো সার্ভার: খাতের তালিকা নেই, চাপলে কিছু খোলে না');
  });

  testWidgets('4 · the payable card shows with its hand loans, and is absent without the key', (tester) async {
    await pump(tester, ownerHomeBody(today: full()));
    expect(find.byKey(const ValueKey('payable-card')), findsOneWidget);
    expect(find.text('দেনা'), findsOneWidget);
    expect(textOf(tester, const ValueKey('payable-value')), '৳12,50,000.00');
    expect(textOf(tester, const ValueKey('payable-hand-loans')), 'হাতধার ৳2,00,000.00 সহ');

    await pump(tester, ownerHomeBody(today: ownerToday(payable: {'amount': '1000.0000', 'handLoans': '0'})));
    expect(find.byKey(const ValueKey('payable-card')), findsOneWidget);
    expect(find.byKey(const ValueKey('payable-hand-loans')), findsNothing, reason: 'হাতধার শূন্য — লাইন নেই');

    await pump(tester, ownerHomeBody(today: ownerToday()));
    expect(find.byKey(const ValueKey('payable-card')), findsNothing);
  });

  testWidgets('5 · principals: the 3 largest by |balance|, the total of all; absent without the key; "সব দেখুন" opens',
      (tester) async {
    final opened = <String>[];
    await pump(tester, ownerHomeBody(today: full(), user: reporter, onOpen: (path, _) => opened.add(path)));
    final card = find.byKey(const ValueKey('principals-card'));
    expect(card, findsOneWidget);
    expect(find.descendant(of: find.byKey(const ValueKey('principal-row-0')), matching: find.text('স্কয়ার')), findsOneWidget);
    expect(find.descendant(of: find.byKey(const ValueKey('principal-row-1')), matching: find.text('ACI')), findsOneWidget);
    expect(find.descendant(of: find.byKey(const ValueKey('principal-row-2')), matching: find.text('প্রাণ')), findsOneWidget);
    expect(find.descendant(of: card, matching: find.text('বসুন্ধরা')), findsNothing, reason: 'কেবল বড় তিন');
    expect(find.text('এসেছে ৳3,00,000.00 · কমিশন ৳6,000.00'), findsOneWidget);
    expect(find.text('কোম্পানির কাছে পাব: ৳80,000.00'), findsOneWidget);
    expect(find.text('দিতে হবে: ৳50,000.00'), findsOneWidget);
    // ⛔ সবার মোট: ৫০,০০০ − ৮০,০০০ + ২০,০০০ + ১,০০০ = −৯,০০০ (দেখানো তিনের যোগ −১০,০০০)
    expect(textOf(tester, const ValueKey('principals-total-value')), 'কোম্পানির কাছে পাব: ৳9,000.00');

    await tester.tap(find.descendant(of: card, matching: find.text('সব দেখুন')));
    expect(opened, ['principal-commission']);

    // ⓘ সার্ভারের মোট এলে সেটাই — নিজের যোগ থেকে আলাদা হলেও
    final withTotal = full()..['principalsTotal'] = {'inflow': '515000', 'commission': '10300', 'paid': '0', 'balance': '-9500.0000'};
    await pump(tester, ownerHomeBody(today: withTotal));
    expect(textOf(tester, const ValueKey('principals-total-value')), 'কোম্পানির কাছে পাব: ৳9,500.00');

    await pump(tester, ownerHomeBody(today: ownerToday()));
    expect(find.byKey(const ValueKey('principals-card')), findsNothing);
    await pump(tester, ownerHomeBody(today: ownerToday(principals: const [])));
    expect(find.byKey(const ValueKey('principals-card')), findsNothing);
  });

  testWidgets('5b · "সব দেখুন" only when the menu would show the screen: supplier.report and the purchase switch on',
      (tester) async {
    Finder action() =>
        find.descendant(of: find.byKey(const ValueKey('principals-card')), matching: find.text('সব দেখুন'));
    Widget scoped(Widget body, Set<String>? on) =>
        ProviderScope(overrides: [phoneModulesProvider.overrideWith((ref) => on)], child: body);

    // চাবি আর ক্রয় চালু — বোতাম আছে, চাপলে পাতায়
    final opened = <String>[];
    await pump(tester,
        scoped(ownerHomeBody(today: full(), user: reporter, onOpen: (p, _) => opened.add(p)), {'sales', 'purchase'}));
    expect(action(), findsOneWidget);
    await tester.tap(action());
    expect(opened, ['principal-commission']);

    // ক্রয়ের ফোনের সুইচ বন্ধ — কার্ড থাকে, বোতাম নয়
    await pump(tester, scoped(ownerHomeBody(today: full(), user: reporter), {'sales'}));
    expect(find.byKey(const ValueKey('principals-card')), findsOneWidget);
    expect(action(), findsNothing);

    // `supplier.report` চাবি নেই — কার্ড থাকে, বোতাম নয়
    await pump(tester, scoped(ownerHomeBody(today: full()), {'sales', 'purchase'}));
    expect(find.byKey(const ValueKey('principals-card')), findsOneWidget);
    expect(action(), findsNothing);
  });

  testWidgets('6 · the branch card carries the sales detail line', (tester) async {
    await pump(tester, ownerHomeBody(today: full()));
    expect(textOf(tester, const ValueKey('branch-sales-detail')), 'বিল ৳1,70,000.00 · ফেরত ৳7,300.00 · VAT ৳24,405.00');
    await pump(tester, ownerHomeBody(today: ownerToday()));
    expect(textOf(tester, const ValueKey('branch-sales-detail')), '9 টি বিক্রয়');
  });

  // ⭐ মজুদের মূল্য — বকেয়ার পাশে, খরচ-দামে; সার্ভার না পাঠালে (চাবি নেই, SR, পুরনো সার্ভার) ঘরই নেই
  testWidgets('stock value sits beside the dues at cost, and is absent when the server sends none', (tester) async {
    await pump(tester, ownerHomeBody(today: full()));
    final dues = tester.getRect(find.byKey(const ValueKey('owner-money-dues')));
    final stock = tester.getRect(find.byKey(const ValueKey('owner-money-stock')));
    expect(stock.top, dues.top, reason: 'বকেয়ার একই সারিতে');
    expect(stock.left, greaterThan(dues.right), reason: 'বকেয়ার ঠিক পাশে');
    expect(find.descendant(of: find.byKey(const ValueKey('owner-money-stock')), matching: find.text('খরচ-দামে')),
        findsOneWidget);

    await pump(tester, ownerHomeBody(today: ownerToday(outflow: '45000.0000')));
    expect(find.byKey(const ValueKey('owner-money-stock')), findsNothing);
    expect(find.text('মজুদের মূল্য'), findsNothing, reason: '⛔ সার্ভার না পাঠালে "—" নয়, ঘরই নেই');
  });

  testWidgets('stock value opens the stock page only for one who has it in the menu', (tester) async {
    Widget scoped(Widget body, Set<String>? on) =>
        ProviderScope(overrides: [phoneModulesProvider.overrideWith((ref) => on)], child: body);
    const stockKeeper =
        AuthUser(id: 'u1', name: 'আল-আমিন শুভ', email: 'o@x', roles: ['Owner'], permissions: ['inventory.stock.view']);
    final opened = <String>[];
    await pump(tester,
        scoped(ownerHomeBody(today: full(), user: stockKeeper, onOpen: (p, _) => opened.add(p)), {'sales', 'inventory'}));
    await tester.tap(find.byKey(const ValueKey('owner-money-stock-tap')));
    await tester.pumpAndSettle();
    expect(opened, ['stock']);

    await pump(tester, scoped(ownerHomeBody(today: full()), {'sales', 'inventory'}));
    expect(find.byKey(const ValueKey('owner-money-stock-tap')), findsNothing, reason: 'মজুদের চাবি নেই — কেবল অঙ্ক');
    expect(find.byKey(const ValueKey('owner-money-stock')), findsOneWidget);
  });

  testWidgets('7 · regression guard: every 0.4.40 figure is on the home with a full payload', (tester) async {
    await pump(tester, ownerHomeBody(today: full()));
    const expected = [
      // বিক্রি আর তার লাইন
      '৳1,62,700.00',
      'বিল ৳1,70,000.00 · ফেরত ৳7,300.00 · VAT ৳24,405.00',
      // আজকের ইনফ্লো আর আদায়
      '+৳1,70,000.00',
      'আদায় ৳1,70,000.00',
      // পাওনা আর দোকান
      '৳8,45,210.00',
      '41 টি দোকান',
      // দেনা আর হাতধার
      '৳12,50,000.00',
      'হাতধার ৳2,00,000.00 সহ',
      // হাতে ও ব্যাংকে মোট, নগদ · MFS · ব্যাংক · পথে
      '৳11,67,633.46',
      '৳0.00',
      '৳1,313.46',
      '৳11,66,320.00',
      '৳5,000.00',
      // মজুদের মূল্য, খরচ-দামে (মালিক, ২১ অক্টোবর ২০২৬ — বকেয়ার পাশে)
      'মজুদের মূল্য',
      '৳23,45,678.90',
      'খরচ-দামে',
      // প্রিন্সিপালের কমিশন
      'প্রিন্সিপালের কমিশন',
      'স্কয়ার',
      'ACI',
      'প্রাণ',
      'কোম্পানির কাছে পাব: ৳80,000.00',
      'দিতে হবে: ৳50,000.00',
      'দিতে হবে: ৳20,000.00',
      'কোম্পানির কাছে পাব: ৳9,000.00',
    ];
    for (final text in expected) {
      expect(find.text(text), findsWidgets, reason: '০.৪.৪০-এর অঙ্ক হারিয়েছে: $text');
    }
  });

  for (final size in const [Size(360, 4000), Size(1280, 4000)]) {
    testWidgets('8 · at 200% text on ${size.width.toInt()}dp the full payload does not overflow', (tester) async {
      await pump(tester, ownerHomeBody(today: full()), textScale: 2, size: size);
      expect(tester.takeException(), isNull);
      expect(find.byKey(const ValueKey('payable-card')), findsOneWidget);
      expect(find.byKey(const ValueKey('principals-card')), findsOneWidget);
      await tester.tap(find.byKey(const ValueKey('owner-money-mfs-tap')));
      await tester.pumpAndSettle();
      expect(tester.takeException(), isNull, reason: 'MFS-এর তালিকাও');
    });
  }

  testWidgets('the payable and principals cards sit side by side at 840dp and over, stacked on a phone',
      (tester) async {
    await pump(tester, ownerHomeBody(today: full()), size: const Size(1280, 2000));
    final pay = tester.getRect(find.byKey(const ValueKey('payable-card')));
    final pri = tester.getRect(find.byKey(const ValueKey('principals-card')));
    expect(pay.top, pri.top);
    expect(pay.right, lessThan(pri.left));
    await pump(tester, ownerHomeBody(today: full()));
    expect(find.byKey(const ValueKey('home-wide-pair-books')), findsNothing);
    expect(tester.getRect(find.byKey(const ValueKey('principals-card'))).top,
        greaterThan(tester.getRect(find.byKey(const ValueKey('payable-card'))).bottom - 1));
  });

  testWidgets('every tap target on the full home is 48dp or more', (tester) async {
    final handle = tester.ensureSemantics();
    await pump(tester, ownerHomeBody(today: full()));
    await expectLater(tester, meetsGuideline(androidTapTargetGuideline));
    expect(find.bySemanticsLabel('MFS, ১ হাজার ৩১৩ টাকা ৪৬ পয়সা'), findsOneWidget);
    handle.dispose();
  });
}
