import 'package:abos_mobile/core/auth/auth_user.dart';
import 'package:abos_mobile/core/menu/menu_repository.dart';
import 'package:abos_mobile/core/menu/module_gate.dart';
import 'package:abos_mobile/core/navigation/nav_catalog.dart';
import 'package:abos_mobile/core/records/money.dart';
import 'package:abos_mobile/core/widgets/empty_state.dart';
import 'package:abos_mobile/features/executive/executive_screen.dart';
import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// ⭐ মালিকের কেন্দ্র, ফোনে — মালিক, ১৩ অক্টোবর ২০২৬: "app e baki module & menu"।
/// আটটা সংখ্যা সার্ভারের, ঢাকা ঘর "••••" (শূন্য নয়), কোম্পানি খুললে শাখা; সময় বদলালে নতুন করে আনে; দরজা কেবল চাবিতে।
class _FakeExecutive implements ExecutiveApi {
  _FakeExecutive(this.answer);

  ExecutiveToday Function(String period) answer;
  final List<String> asked = [];
  Object? fail;

  @override
  Future<ExecutiveToday> today(String period) async {
    asked.add(period);
    if (fail != null) throw fail!;
    return answer(period);
  }
}

Map<String, dynamic> _payload({String sales = '125000.0000', bool companies = true}) => {
      'period': 'today',
      'figures': [
        {'key': 'sales', 'label': 'Sales', 'value': sales, 'money': true, 'partial': false},
        {'key': 'collections', 'label': 'Collections', 'value': '40000.0000', 'money': true, 'partial': false},
        {'key': 'receivable', 'label': 'x', 'value': 'hidden', 'money': true, 'partial': true},
        {'key': 'signatures', 'label': 'x', 'value': '3.0000', 'money': false, 'partial': false},
      ],
      'companies': companies
          ? [
              {
                'id': '0199c1d2-0000-7000-8000-000000000001',
                'name': 'টিডি ডিপো',
                'values': {'sales': '125000.0000', 'collections': '40000.0000', 'signatures': '3.0000'},
                'rows': [
                  {
                    'id': '0199c1d2-0000-7000-8000-000000000002',
                    'name': 'নেত্রকোনা',
                    'values': {'sales': '25000.0000', 'signatures': null},
                  },
                ],
                'unsplit': null,
              },
            ]
          : [],
      'alerts': [
        {
          'kind': 'overdue',
          'label': 'Overdue dues',
          'total': 4,
          'companies': [
            {'name': 'টিডি ডিপো', 'count': 4},
            {'name': 'ফ্যামিলি মার্ট', 'count': null},
          ],
        },
        {'kind': 'stock_low', 'label': 'x', 'total': 0, 'companies': []},
      ],
      'waiting': [
        {'company': 'টিডি ডিপো', 'label': 'ভাউচার JV-0007', 'amount': '5000.0000', 'requested_at': '2026-10-13 09:00:00'},
      ],
      'credit': {
        'totals': {'exposure': '800000.0000', 'overdue': '120000.0000', 'blocked': '2', 'pending_requests': '1'},
        'partial': false,
      },
    };

Future<_FakeExecutive> _open(WidgetTester tester, {Map<String, dynamic>? body}) async {
  final api = _FakeExecutive((_) => ExecutiveToday.fromJson(body ?? _payload()));
  await tester.pumpWidget(MaterialApp(home: ExecutiveScreen(api: api)));
  await tester.pumpAndSettle();
  return api;
}

void main() {
  testWidgets('the headline figures are the server\'s, and a figure without its key is masked, never zero',
      (tester) async {
    await _open(tester);

    expect(find.text('মালিকের কেন্দ্র'), findsOneWidget);
    expect(find.descendant(of: find.byKey(const ValueKey('figure-sales')), matching: find.text(Money.taka('125000'))),
        findsOneWidget);
    expect(find.descendant(of: find.byKey(const ValueKey('figure-receivable')), matching: find.text('••••')),
        findsOneWidget);
    expect(find.descendant(of: find.byKey(const ValueKey('figure-signatures')), matching: find.text('3')),
        findsOneWidget, reason: 'signatures are a count, not taka');
    expect(find.text('কিছু কোম্পানি বাদ — অনুমতি নেই'), findsOneWidget);
    // ⓘ লেবেল ফোনের বাংলায়, সার্ভারের ইংরেজি নয়
    expect(find.text('Sales'), findsNothing);
    expect(find.text('বিক্রি'), findsOneWidget);
    expect(find.byKey(const ValueKey('executive-credit')), findsOneWidget);
  });

  testWidgets('a company opens to its branches; a figure not split by branch reads as a dash', (tester) async {
    await _open(tester);

    await tester.scrollUntilVisible(find.text('টিডি ডিপো').first, 200);
    await tester.tap(find.byKey(const ValueKey('company-0199c1d2-0000-7000-8000-000000000001')));
    await tester.pumpAndSettle();

    expect(find.text('নেত্রকোনা'), findsOneWidget);
    expect(find.text(Money.taka('25000')), findsOneWidget);
    expect(find.text('—'), findsWidgets);
  });

  testWidgets('alerts in Bengali with each company\'s count, and "no key" said as such', (tester) async {
    await _open(tester);
    await tester.scrollUntilVisible(find.byKey(const ValueKey('alert-overdue')), 200);

    expect(find.text('মেয়াদ পেরোনো বাকি'), findsOneWidget);
    expect(find.text('টিডি ডিপো: 4 · ফ্যামিলি মার্ট: অনুমতি নেই'), findsOneWidget);
    expect(find.text('Overdue dues'), findsNothing);
  });

  testWidgets('choosing the month asks the server for the month', (tester) async {
    final api = await _open(tester);
    expect(api.asked, ['today']);

    await tester.tap(find.byKey(const ValueKey('period-month')));
    await tester.pumpAndSettle();
    expect(api.asked, ['today', 'month']);
  });

  testWidgets('no company in reach is an empty state that a pull refreshes', (tester) async {
    await _open(tester, body: _payload(companies: false));

    expect(find.text('দেখার মতো কোনো কোম্পানি নেই'), findsOneWidget);
    expect(find.ancestor(of: find.byType(EmptyState), matching: find.byType(RefreshIndicator)), findsOneWidget);
  });

  testWidgets('a refusal is said in Bengali', (tester) async {
    final api = _FakeExecutive((_) => ExecutiveToday.fromJson(_payload()))
      ..fail = DioException(
        requestOptions: RequestOptions(path: '/executive/today'),
        type: DioExceptionType.connectionError,
      );
    await tester.pumpWidget(MaterialApp(home: ExecutiveScreen(api: api)));
    await tester.pumpAndSettle();

    expect(find.textContaining('সংযোগ নেই'), findsOneWidget);
  });

  group('the door', () {
    AuthUser user(List<String> permissions) =>
        AuthUser(id: 'u', name: 'x', email: 'x@x', roles: const [], permissions: permissions);

    test('the tile comes only with executive.view, under মালিকের কেন্দ্র', () {
      final without = const MenuRepository().ordered(const [], user(const ['sales.order.view']));
      expect(without.map((i) => i.routeName), isNot(contains('owner-centre')));

      final withKey = const MenuRepository().ordered(const [], user(const ['executive.view']));
      expect(withKey.map((i) => i.routeName), contains('owner-centre'));
      final tree = NavTree.from(withKey);
      expect(tree.module('executive')!.screens.map((r) => r.path), ['owner-centre']);
    });

    test('the company\'s phone switch closes it', () {
      expect(ModuleGate.allows({'sales'}, 'owner-centre'), isFalse);
      expect(ModuleGate.allows({'executive'}, 'owner-centre'), isTrue);
    });
  });
}
