import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/accounts/net_worth_api.dart';
import 'package:abos_mobile/core/records/today_record.dart';
import 'package:abos_mobile/features/home/home_dashboard.dart';
import 'package:abos_mobile/features/home/owner_home_cards.dart';

import 'support/main_screens.dart' show setScreenSize;
import 'support/screens_0441.dart';

/// ⭐ নিট সম্পদ বিবরণী, ফোনে (মালিক, ২৫ অক্টোবর ২০২৬: "mobile app er taw kore felo"; "Cash mfs bank card er niche daw")।
///
/// দাবি: কার্ডটা টাকার কার্ডের ঠিক নিচে · সার্ভারের নিট অঙ্কই লাখের কমায় · মালিক নন (৪০৪ → null) হলে কার্ড নেই ·
/// দুই দিক না মিললে লাল কথা · চাপলে পুরো বিবরণী, চার ঘর তাদের খাতসহ আর দুই দিক।
class _FakeNetWorth implements NetWorthApi {
  _FakeNetWorth({this.owner = true, this.agrees = true});

  final bool owner;
  final bool agrees;

  @override
  Future<NetWorthSummary?> summary() async => owner
      ? NetWorthSummary({'net': '12345678.5000', 'this_year': '250000.0000', 'as_of': '2026-10-25', 'agrees': agrees})
      : null;

  @override
  Future<NetWorthStatementRecord> statement() async => NetWorthStatementRecord({
        'company': {'code': 'ADI', 'name': 'ADI Wholesale'},
        'as_of': '2026-10-25',
        'agrees': agrees,
        'figures': {
          'cash': '1000000.0000', 'receivable': '5000000.0000', 'stock': '8000000.0000', 'other_assets': '345678.5000',
          'assets': '14345678.5000', 'payable': '1900000.0000', 'other_liabilities': '100000.0000',
          'net': '12345678.5000', 'capital': '10000000.0000', 'drawings': '404321.5000', 'earlier': '2500000.0000',
          'this_year': '250000.0000', 'equity': '12345678.5000',
        },
        'accounts': {
          'cash': [
            {'id': 1, 'code': '1111', 'name': 'প্রধান নগদ', 'amount': '400000.0000'},
            {'id': 2, 'code': '1121', 'name': 'ব্যাংক', 'amount': '600000.0000'},
          ],
          'stock': [
            {'id': 3, 'code': '1120', 'name': 'মজুদ', 'amount': '8000000.0000'},
          ],
        },
      });
}

void main() {
  Future<void> pump(WidgetTester tester, NetWorthApi api) async {
    setScreenSize(tester, const Size(360, 3200));
    await tester.pumpWidget(const SizedBox());
    await tester.pumpWidget(framed(Scaffold(
      body: HomeDashboard(
        user: ownerUser,
        items: const [],
        headerNamesOrg: true,
        fetchToday: () async => TodayRecord(ownerToday()),
        lastKnownToday: () => null,
        loadWaiting: () async => waitingPapers,
        onOpen: (_, __) {},
        netWorth: api,
      ),
    )));
    await tester.pumpAndSettle();
  }

  testWidgets('the owner sees the server net figure right under the cash · MFS · bank card', (tester) async {
    await pump(tester, _FakeNetWorth());

    final card = find.byKey(const ValueKey('net-worth-card'));
    expect(card, findsOneWidget, reason: '⛔ মালিকের হোমে নিট সম্পদ বিবরণীর কার্ড নেই');
    expect(find.descendant(of: card, matching: find.textContaining('1,23,45,678.50')), findsOneWidget,
        reason: '⛔ কার্ডের নিট অঙ্ক সার্ভারের অঙ্ক নয়, বা লাখের কমায় নয়');
    expect(find.descendant(of: card, matching: find.text('দুই দিক মেলে — খাতা ঠিক আছে')), findsOneWidget);

    // ⓘ টাকার কার্ডের ঠিক নিচে: মাঝে আর কোনো কার্ড নেই
    final money = find.byType(OwnerMoneyCard);
    expect(money, findsOneWidget, reason: 'প্রস্তুতি: টাকার কার্ড নেই');
    final moneyBottom = tester.getBottomLeft(money).dy;
    final cardTop = tester.getTopLeft(card).dy;
    expect(cardTop, greaterThanOrEqualTo(moneyBottom));
    final waiting = find.byKey(const ValueKey('waiting-papers'));
    expect(tester.getTopLeft(waiting).dy, greaterThan(cardTop),
        reason: '⛔ নিট সম্পদের কার্ড টাকার কার্ডের ঠিক নিচে নয় — মাঝে অন্য কার্ড');
  });

  testWidgets('anyone but the owner (the server says 404) sees no card at all', (tester) async {
    await pump(tester, _FakeNetWorth(owner: false));

    expect(find.byKey(const ValueKey('net-worth-card')), findsNothing,
        reason: '⛔ মালিক নন এমন কারো হোমে নিট সম্পদ বিবরণীর কার্ড');
    expect(find.textContaining('নিট সম্পদ'), findsNothing);
  });

  testWidgets('when the two sides differ the card says the books hold an error', (tester) async {
    await pump(tester, _FakeNetWorth(agrees: false));

    expect(find.text('দুই দিক মেলে না — খাতায় ভুল আছে'), findsOneWidget,
        reason: '⛔ দুই দিক না মিললেও কার্ড "খাতা ঠিক আছে" বলে');
  });

  testWidgets('tapping the card opens the full statement with its tiles, accounts and both sides', (tester) async {
    await pump(tester, _FakeNetWorth());

    await tester.tap(find.byKey(const ValueKey('net-worth-open')));
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('net-worth-net-box')), findsOneWidget, reason: '⛔ চাপলে পুরো বিবরণী খোলে না');
    expect(tester.widget<Text>(find.byKey(const ValueKey('net-worth-net'))).data, '৳1,23,45,678.50');
    // ⓘ নিট অঙ্ক তিন জায়গায়: বড় বাক্স, সম্পদ − দায়ের শেষ, মূলধন + লাভের শেষ — দুই দিক মেলে
    expect(find.text('৳1,23,45,678.50'), findsNWidgets(3));
    for (final tile in ['cash', 'receivable', 'stock', 'other_assets']) {
      expect(find.byKey(ValueKey('net-worth-tile-$tile')), findsOneWidget, reason: '⛔ "$tile" ঘর নেই');
    }
    expect(find.textContaining('1111 · প্রধান নগদ'), findsOneWidget, reason: '⛔ ঘরের নিচে খাত নেই');
    expect(find.byKey(const ValueKey('net-worth-position')), findsOneWidget);
    expect(find.byKey(const ValueKey('net-worth-equity')), findsOneWidget);
    expect(find.textContaining('19,00,000.00'), findsWidgets, reason: '⛔ দেনা বাদের সারি নেই');
  });
}
