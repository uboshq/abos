import 'package:abos_mobile/features/sales_plan/sales_plan_api.dart';
import 'package:abos_mobile/features/sales_plan/sales_plan_screens.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// ⭐ বিক্রয়ের পরিকল্পনার পাঁচটা পাতা, ফোনের নিজের পর্দায় (মালিক, ১৭ অক্টোবর ২০২৬: *"100% in the app, in the app's own style,
/// all Bangla, totals on every list"*)।
///
/// <p>দাবি: প্রতিটা তালিকার মাথায় **সার্ভারের** গোটা তালিকার মোট (পাতার দুই সারির যোগ নয়), টাকা লাখ-কোটির কমায়; মাস বদলালে নতুন
/// মাস চাওয়া হয়; খোঁজা আর ছাঁকনি সার্ভারে যায়; রুট আর স্কিম চাপলে নিজের পাতা; খালি আর ভুলের পর্দা বাংলায়; "আরও দেখুন" পরের পাতা আনে।

class _FakeApi implements SalesPlanApi {
  _FakeApi({this.empty = false, this.fail = false});

  final bool empty;
  final bool fail;
  final asked = <String>[];

  void _check() {
    if (fail) throw Exception('boom');
  }

  @override
  Future<PlanRow> targets({required String month, int page = 1}) async {
    asked.add('targets:$month:$page');
    _check();
    if (empty) return const PlanRow({'rows': [], 'count': 0, 'totals': {'target': '0.00', 'achieved': '0.00', 'with_target': 0}});
    return PlanRow({
      'month': month,
      'rows': [
        if (page == 1) {'id': 'u1', 'name': 'করিম', 'target': '250000.00', 'achieved': '212500.00', 'percent': '85.0'},
        if (page == 1) {'id': 'u2', 'name': 'রহিম', 'target': null, 'achieved': '1500.00', 'percent': null},
        if (page == 2) {'id': 'u3', 'name': 'সালাম', 'target': '1000.00', 'achieved': '0.00', 'percent': '0.0'},
      ],
      'count': 51,
      'totals': {'target': '1234567.50', 'achieved': '900000.00', 'percent': '72.9', 'with_target': 40},
      'next_page': page == 1 ? 2 : null,
    });
  }

  @override
  Future<PlanRow> dealerTargets({required String month, String q = '', bool onlySet = false, int page = 1}) async {
    asked.add('dealer:$month:$q:$onlySet');
    _check();
    return const PlanRow({
      'rows': [
        {'id': 'c1', 'code': 'C-0001', 'name': 'রহিম ট্রেডার্স', 'point': 'কেন্দুয়া বাজার', 'target': '5000.00', 'closes_on': '2026-10-31',
          'achieved': '200.00', 'remaining': '4800.00'},
        {'id': 'c2', 'code': 'C-0002', 'name': 'করিম স্টোর্স', 'point': null, 'target': null, 'closes_on': null, 'achieved': '0.00', 'remaining': null},
      ],
      'count': 120,
      'totals': {'target': '5700.00', 'achieved': '200.00', 'remaining': '5500.00', 'with_target': 2},
    });
  }

  @override
  Future<PlanRow> routes({required String month, String q = '', int page = 1}) async {
    asked.add('routes:$month:$q');
    _check();
    if (empty) {
      return const PlanRow({'rows': [], 'count': 0, 'unrouted': 0, 'totals': {'sales': '0.00', 'collections': '0.00', 'outstanding': '0.00',
        'target': '0.00', 'achieved': '0.00', 'customers': 0}});
    }
    return const PlanRow({
      'rows': [
        {'id': 'r1', 'code': 'RT-1', 'name': 'কেন্দুয়া শনিবার', 'point': 'কেন্দুয়া বাজার', 'customers': 2, 'salespeople': ['আলম'],
          'sales': '700.00', 'collections': '200.00', 'outstanding': '500.00', 'target': '4321.00', 'achieved': '700.00', 'percent': '16.2'},
      ],
      'count': 7,
      'unrouted': 3,
      'totals': {'customers': 9, 'sales': '1234567.00', 'collections': '200.00', 'outstanding': '500.00', 'target': '4321.00',
        'achieved': '700.00', 'percent': '16.2'},
    });
  }

  @override
  Future<PlanRow> route(String id, {required String month, int page = 1}) async {
    asked.add('route:$id:$month');
    _check();
    return const PlanRow({
      'route': {'id': 'r1', 'code': 'RT-1', 'name': 'কেন্দুয়া শনিবার', 'point': 'কেন্দুয়া বাজার'},
      'from': '2026-10-01',
      'to': '2026-10-19',
      'figures': {'customers': 1, 'opening': '0.00', 'sales': '500.00', 'returns': '0.00', 'collections': '200.00', 'other': '0.00',
        'outstanding': '300.00'},
      'target': {'target': '4321.00', 'achieved': '500.00', 'percent': '11.6'},
      'customers': [
        {'id': 'c1', 'code': 'C-0001', 'name': 'রহিম ট্রেডার্স', 'point': 'কেন্দুয়া বাজার', 'phone': '+8801811000001', 'opening': '0.00',
          'sales': '500.00', 'returns': '0.00', 'collections': '200.00', 'other': '0.00', 'outstanding': '300.00'},
      ],
      'customers_count': 1,
      'schedule': [
        {'who': 'আলম', 'weekday': 6, 'weekday_label': 'শনিবার', 'from': '2026-09-01', 'to': null, 'active': true},
      ],
    });
  }

  @override
  Future<PlanRow> schemes({String q = '', String status = '', int page = 1}) async {
    asked.add('schemes:$q:$status');
    _check();
    return PlanRow({
      'rows': [
        if (status.isEmpty || status == 'draft')
          {'id': 's1', 'code': 'EID', 'name': 'ঈদের স্কিম', 'basis_label': 'ধাপে ধাপে', 'applies_to_label': 'সব বিক্রয়',
            'valid_from': '2026-10-01', 'valid_to': '2026-10-31', 'status': 'draft', 'status_label': 'খসড়া', 'live': false, 'rules': 2},
      ],
      'count': status.isEmpty ? 4 : 1,
      'by_status': [
        {'status': 'draft', 'label': 'খসড়া', 'count': 1},
        {'status': 'active', 'label': 'চালু', 'count': 3},
      ],
    });
  }

  @override
  Future<PlanRow> scheme(String id) async {
    asked.add('scheme:$id');
    _check();
    return const PlanRow({
      'id': 's1', 'code': 'EID', 'name': 'ঈদের স্কিম', 'status_label': 'খসড়া', 'basis_label': 'ধাপে ধাপে', 'applies_to_label': 'একটা ব্র্যান্ড',
      'target': 'প্রাণ', 'valid_from': '2026-10-01', 'valid_to': '2026-10-31', 'notes': '',
      'rules': [
        {'role': 'SR', 'slab_from': '0.00', 'slab_to': '10000.00', 'fixed_amount': null, 'rate_percent': '2.5', 'level': 1},
        {'role': 'SR', 'slab_from': '10000.00', 'slab_to': null, 'fixed_amount': '500.00', 'rate_percent': null, 'level': 1},
      ],
    });
  }

  @override
  Future<PlanRow> commissions({String q = '', String status = '', int page = 1}) async {
    asked.add('commissions:$q:$status');
    _check();
    return const PlanRow({
      'rows': [
        {'id': 'k1', 'document_no': 'COM-1', 'date': '2026-10-19', 'customer': 'রহিম ট্রেডার্স', 'point': 'কেন্দুয়া বাজার', 'supplier': 'প্রাণ',
          'invoice_no': null, 'base_amount': '10000.00', 'rate': '5%', 'rate_amount': null, 'amount': '1000.00', 'status': 'pending',
          'status_label': 'অপেক্ষায়', 'decision_reason': '', 'narration': ''},
      ],
      'count': 3,
      'totals': {'amount': '1750.00', 'pending': '1450.00'},
      'by_status': [
        {'status': 'pending', 'label': 'অপেক্ষায়', 'count': 2, 'amount': '1450.00'},
        {'status': 'settled', 'label': 'সমন্বয় হয়েছে', 'count': 1, 'amount': '300.00'},
        {'status': 'rejected', 'label': 'মানা হয়নি', 'count': 0, 'amount': '0.00'},
      ],
    });
  }
}

Future<void> _show(WidgetTester tester, Widget screen) async {
  await tester.pumpWidget(MaterialApp(home: screen));
  await tester.pumpAndSettle();
}

Finder get _strip => find.byKey(const ValueKey('books-total'));

void main() {
  final today = DateTime(2026, 10, 19);

  testWidgets('the target board shows the server whole-team total in lakh, not a page sum, and pages on', (tester) async {
    final api = _FakeApi();
    await _show(tester, SalesTargetsScreen(api: api, today: today));

    expect(find.text('অক্টোবর 2026'), findsOneWidget);
    expect(find.descendant(of: _strip, matching: find.text('৳12,34,567.50')), findsOneWidget, reason: '⛔ মোট সার্ভারের, লাখ-কোটির কমায়');
    expect(find.descendant(of: _strip, matching: find.text('· 51 টি')), findsOneWidget);
    expect(find.text('72.9%'), findsOneWidget);
    expect(find.text('85.0%'), findsOneWidget);
    expect(find.textContaining('লক্ষ্য — · অর্জন ৳1,500.00'), findsOneWidget, reason: 'লক্ষ্য নেই মানে "—", শূন্য নয়');

    await tester.scrollUntilVisible(find.byKey(const ValueKey('books-more')), 200);
    await tester.tap(find.byKey(const ValueKey('books-more')));
    await tester.pumpAndSettle();
    expect(api.asked, contains('targets:2026-10:2'));
    expect(find.text('সালাম'), findsOneWidget);

    await tester.drag(find.byType(ListView), const Offset(0, 2000));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('month-previous')));
    await tester.pumpAndSettle();
    expect(find.text('সেপ্টেম্বর 2026'), findsOneWidget);
    expect(api.asked.last, 'targets:2026-09:1');
  });

  testWidgets('dealer targets send the search and the only-set filter and show the point beside the dealer', (tester) async {
    final api = _FakeApi();
    await _show(tester, DealerTargetsScreen(api: api, today: today));

    expect(find.descendant(of: _strip, matching: find.text('৳5,700.00')), findsOneWidget);
    expect(find.text('৳5,500.00'), findsOneWidget, reason: 'মোট বাকি');
    expect(find.text('C-0001 · রহিম ট্রেডার্স · কেন্দুয়া বাজার'), findsOneWidget);
    expect(find.textContaining('বাকি ৳4,800.00 · শেষ 31/10/2026'), findsOneWidget);

    await tester.enterText(find.byKey(const ValueKey('dealer-target-search')), 'রহিম');
    await tester.testTextInput.receiveAction(TextInputAction.search);
    await tester.pumpAndSettle();
    expect(api.asked.last, 'dealer:2026-10:রহিম:false');

    await tester.tap(find.byKey(const ValueKey('dealer-target-only-set')));
    await tester.pumpAndSettle();
    expect(api.asked.last, 'dealer:2026-10:রহিম:true');
  });

  testWidgets('the route book shows its whole totals and the unrouted warning, and a route opens its own page', (tester) async {
    final api = _FakeApi();
    await _show(tester, RouteBookScreen(api: api, today: today));

    expect(find.descendant(of: _strip, matching: find.text('৳12,34,567.00')), findsOneWidget);
    expect(find.byKey(const ValueKey('route-unrouted')), findsOneWidget);
    expect(find.textContaining('আলম'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('route-r1')));
    await tester.pumpAndSettle();
    expect(api.asked.last, 'route:r1:2026-10');
    expect(find.descendant(of: _strip, matching: find.text('৳300.00')), findsOneWidget, reason: 'শেষ জের — রুটের গোটা অঙ্ক');
    expect(find.text('শনিবার · আলম'), findsOneWidget);
    expect(find.text('চালু'), findsOneWidget);
    expect(find.textContaining('01/09/2026 থেকে চলছে'), findsOneWidget);
    await tester.scrollUntilVisible(find.byKey(const ValueKey('route-dealer-c1')), 200);
    expect(find.text('C-0001 · রহিম ট্রেডার্স · কেন্দুয়া বাজার'), findsOneWidget);
  });

  testWidgets('schemes filter by status from the server counts and open their steps', (tester) async {
    final api = _FakeApi();
    await _show(tester, SchemeListScreen(api: api));

    expect(find.text('মোট 4 টি স্কিম · বসানো ও বদল ওয়েবে'), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('scheme-status-active')));
    await tester.pumpAndSettle();
    expect(api.asked.last, 'schemes::active');
    expect(find.text('কোনো স্কিম নেই'), findsOneWidget);
    expect(find.byKey(const ValueKey('scheme-status-draft')), findsOneWidget, reason: 'একটা অবস্থা বাছলে বাকি চিপ হারায় না');

    await tester.tap(find.byKey(const ValueKey('scheme-status-all')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('scheme-s1')));
    await tester.pumpAndSettle();
    expect(api.asked.last, 'scheme:s1');
    expect(find.text('একটা ব্র্যান্ড · প্রাণ'), findsOneWidget);
    expect(find.text('2.5%'), findsOneWidget);
    expect(find.text('৳500.00'), findsOneWidget);
    expect(find.text('৳10,000.00 থেকে উপরে'), findsOneWidget);
  });

  testWidgets('commissions show the grand total, what is still owed and each state', (tester) async {
    final api = _FakeApi();
    await _show(tester, CommissionListScreen(api: api));

    expect(find.descendant(of: _strip, matching: find.text('৳1,750.00')), findsOneWidget);
    expect(find.text('৳1,450.00'), findsWidgets);
    expect(find.text('সমন্বয় হয়েছে · 1টি'), findsOneWidget);
    expect(find.textContaining('মানা হয়নি · 0টি'), findsNothing, reason: 'শূন্য অবস্থা মোটে নয়');
    expect(find.text('COM-1 · রহিম ট্রেডার্স · কেন্দুয়া বাজার'), findsOneWidget);
    expect(find.text('ভিত্তি ৳10,000.00 · হার 5%'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('commission-status-settled')));
    await tester.pumpAndSettle();
    expect(api.asked.last, 'commissions::settled');
  });

  testWidgets('an empty list and a failed list speak Bangla and can be pulled again', (tester) async {
    await _show(tester, RouteBookScreen(api: _FakeApi(empty: true), today: today));
    expect(find.text('কোনো রুট নেই'), findsOneWidget);

    final failing = _FakeApi(fail: true);
    await _show(tester, SalesTargetsScreen(api: failing, today: today));
    expect(find.byKey(const ValueKey('books-error')), findsOneWidget);
    expect(_strip, findsNothing, reason: '⛔ ভুলের পর্দায় কোনো মোট নয়');

    await tester.drag(find.byType(ListView), const Offset(0, 400));
    await tester.pumpAndSettle();
    expect(failing.asked.where((a) => a.startsWith('targets:')).length, 2, reason: 'টানলে আবার চায়');
  });
}
