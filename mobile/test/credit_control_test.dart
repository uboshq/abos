import 'package:abos_mobile/core/auth/auth_user.dart';
import 'package:abos_mobile/core/menu/menu_repository.dart';
import 'package:abos_mobile/core/menu/module_gate.dart';
import 'package:abos_mobile/core/navigation/nav_catalog.dart';
import 'package:abos_mobile/core/records/approval_record.dart';
import 'package:abos_mobile/core/records/money.dart';
import 'package:abos_mobile/core/widgets/empty_state.dart';
import 'package:abos_mobile/features/credit_control/credit_screens.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// ⭐ বাকি নিয়ন্ত্রণ, ফোনে — মালিক, ১৩ অক্টোবর ২০২৬: "app e baki module & menu"।
/// ড্যাশবোর্ড আর তালিকা সার্ভারের; গ্রাহকের পাতায় ঝুঁকি কারণসহ; অনুরোধ আর বাকি বন্ধ তোলা কেবল সার্ভার যখন বলে।
class _FakeCredit implements CreditControlApi {
  _FakeCredit({this.customerBody});

  Map<String, dynamic>? customerBody;
  final List<String> lists = [];
  final List<Map<String, String?>> requests = [];
  final List<String> lifts = [];

  @override
  Future<CreditDashboardFigures> dashboard() async => CreditDashboardFigures.fromJson({
        'on': true,
        'totals': {
          'limit': '900000.0000',
          'exposure': '650000.0000',
          'available': '250000.0000',
          'overdue': '120000.0000',
          'used_percent': '72.2',
          'over_limit': 2,
          'overdue_stopped': 1,
          'blocked': 1,
          'pending_requests': 3,
        },
      });

  @override
  Future<CreditListPage> list(String list, {String q = '', int page = 1}) async {
    lists.add('$list|$q');
    if (list == 'over-limit') return const CreditListPage(rows: []);
    return CreditListPage.fromJson({
      'rows': [
        if (list == 'high-risk')
          {
            'customer': 'c-late',
            'code': 'C-0042',
            'name': 'করিম স্টোর',
            'level': 'চরম',
            'reasons': 'সবচেয়ে পুরনো বিল মেয়াদের ৭০ দিন পরেও বাকি',
            'exposure': '15000.0000',
          },
        if (list == 'overdue-bills')
          {
            'customer': 'c-late',
            'code': 'C-0042',
            'name': 'করিম স্টোর',
            'document_no': 'INV-0091',
            'days_overdue': '70.0000',
            'unpaid': '15000.0000',
          },
      ],
      'last_page': 1,
      'total_rows': 1,
    });
  }

  @override
  Future<CustomerCredit> customer(String id) async => CustomerCredit.fromJson(customerBody ?? _customer());

  @override
  Future<String> requestLimit(String id,
      {required String kind, required String amount, String? until, required String reason}) async {
    requests.add({'id': id, 'kind': kind, 'amount': amount, 'until': until, 'reason': reason});
    return 'অনুরোধ মালিকের সইয়ের জন্য পাঠানো হলো — সই পড়ার আগে সীমা বদলায় না।';
  }

  @override
  Future<String> liftBlock(String id, String reason) async {
    lifts.add('$id|$reason');
    return 'বাকি বন্ধ তোলা হলো।';
  }
}

Map<String, dynamic> _customer({bool canRequest = true, bool mayLift = false, bool blocked = false}) => {
      'id': 'c-late',
      'code': 'C-0042',
      'name': 'করিম স্টোর',
      'credit_days': 15,
      'position': {
        'limit': '10000.0000',
        'base_limit': '10000.0000',
        'temp_limit': null,
        'temp_until': null,
        'due': '15000.0000',
        'held': '0.0000',
        'uncleared': '0.0000',
        'exposure': '15000.0000',
        'available': '0.0000',
        'used_percent': '150.00',
        'state': 'over_limit',
        'state_label': 'Over limit',
        'stop': null,
      },
      'risk': {
        'level': 'critical',
        'label': 'Critical',
        'score': 115,
        'reasons': ['সবচেয়ে পুরনো বিল মেয়াদের ৭০ দিন পরেও বাকি', 'গত ১৮০ দিনে ২টা চেক ফেরত'],
      },
      'block': blocked ? {'reason': 'চেক ফেরত — BC-1', 'bounced_cheque': true} : null,
      'bills': [
        {'no': 'INV-0091', 'unpaid': '15000.0000', 'due_on': '2026-08-04', 'days_overdue': 70},
      ],
      'requests': [
        {
          'kind': 'limit',
          'current_limit': '10000.0000',
          'requested_limit': '40000.0000',
          'reason': 'ঈদ',
          'status': 'pending',
          'requested_by': 'হিসাবরক্ষক',
          'requested_at': '2026-10-12T10:00:00+06:00',
        },
      ],
      'can_request': canRequest,
      'may_lift': mayLift,
    };

void main() {
  testWidgets('the desk shows the dashboard and the high-risk list; a row opens the customer', (tester) async {
    final api = _FakeCredit();
    await tester.pumpWidget(MaterialApp(home: CreditDeskScreen(api: api)));
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('credit-exposure')), findsOneWidget);
    expect(find.text(Money.taka('650000')), findsOneWidget);
    expect(find.text('72.2%'), findsOneWidget);
    expect(find.text('৩টা'), findsNothing);
    expect(find.text('3টা'), findsOneWidget);
    expect(api.lists, ['high-risk|']);
    expect(find.text('করিম স্টোর'), findsOneWidget);
    expect(find.textContaining('ঝুঁকি: চরম'), findsOneWidget);
  });

  testWidgets('another list is asked by name; an empty one says so and pulls', (tester) async {
    final api = _FakeCredit();
    await tester.pumpWidget(MaterialApp(home: CreditDeskScreen(api: api)));
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const ValueKey('credit-list-overdue-bills')));
    await tester.pumpAndSettle();
    expect(api.lists.last, 'overdue-bills|');
    expect(find.textContaining('মেয়াদ পেরিয়েছে 70 দিন'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('credit-list-over-limit')));
    await tester.pumpAndSettle();
    expect(find.text('কেউ সীমা পার করেননি'), findsOneWidget);
    expect(find.ancestor(of: find.byType(EmptyState), matching: find.byType(RefreshIndicator)), findsOneWidget);
  });

  testWidgets('a search goes to the server', (tester) async {
    final api = _FakeCredit();
    await tester.pumpWidget(MaterialApp(home: CreditDeskScreen(api: api)));
    await tester.pumpAndSettle();

    await tester.enterText(find.byKey(const ValueKey('credit-search')), 'করিম');
    await tester.testTextInput.receiveAction(TextInputAction.search);
    await tester.pumpAndSettle();
    expect(api.lists.last, 'high-risk|করিম');
  });

  testWidgets('a customer\'s page: position, the risk with its reasons in Bengali, bills and requests', (tester) async {
    await tester.pumpWidget(MaterialApp(home: CustomerCreditScreen(customerId: 'c-late', api: _FakeCredit())));
    await tester.pumpAndSettle();

    expect(find.text('সীমা পার'), findsOneWidget);
    expect(find.text('Over limit'), findsNothing);
    expect(find.text('চরম'), findsOneWidget);
    expect(find.text('• গত ১৮০ দিনে ২টা চেক ফেরত'), findsOneWidget);
    await tester.scrollUntilVisible(find.text('INV-0091'), 200);
    expect(find.text('INV-0091'), findsOneWidget);
    await tester.scrollUntilVisible(find.text('সইয়ের অপেক্ষায়'), 200);
    expect(find.text('সইয়ের অপেক্ষায়'), findsOneWidget);
    expect(find.byKey(const ValueKey('credit-lift')), findsNothing, reason: 'not blocked');
  });

  testWidgets('a limit request needs an amount and a reason, then goes with what was written', (tester) async {
    final api = _FakeCredit();
    await tester.pumpWidget(MaterialApp(home: CustomerCreditScreen(customerId: 'c-late', api: api)));
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const ValueKey('credit-request')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('request-send')));
    await tester.pumpAndSettle();
    expect(find.text('চাওয়া সীমা লিখুন।'), findsOneWidget);
    expect(api.requests, isEmpty);

    await tester.enterText(find.byKey(const ValueKey('request-amount')), '40000');
    await tester.enterText(find.byKey(const ValueKey('request-reason')), 'ঈদের মৌসুম');
    await tester.tap(find.byKey(const ValueKey('request-send')));
    await tester.pumpAndSettle();

    expect(api.requests.single, {'id': 'c-late', 'kind': 'limit', 'amount': '40000', 'until': null, 'reason': 'ঈদের মৌসুম'});
    expect(find.textContaining('মালিকের সইয়ের জন্য পাঠানো হলো'), findsOneWidget);
  });

  testWidgets('without the request key there is no request button', (tester) async {
    await tester.pumpWidget(MaterialApp(
        home: CustomerCreditScreen(customerId: 'c-late', api: _FakeCredit(customerBody: _customer(canRequest: false)))));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('credit-request')), findsNothing);
  });

  testWidgets('a bounced-cheque block shows its reason; the lift button only when the server says may_lift', (tester) async {
    final api = _FakeCredit(customerBody: _customer(blocked: true));
    await tester.pumpWidget(MaterialApp(home: CustomerCreditScreen(customerId: 'c-late', api: api)));
    await tester.pumpAndSettle();
    expect(find.text('চেক ফেরত — BC-1'), findsOneWidget);
    expect(find.text('চেক ফেরতের বন্ধ — খোলেন কেবল মালিক'), findsOneWidget);
    expect(find.byKey(const ValueKey('credit-lift')), findsNothing);

    api.customerBody = _customer(blocked: true, mayLift: true);
    await tester.pumpWidget(MaterialApp(home: CustomerCreditScreen(key: UniqueKey(), customerId: 'c-late', api: api)));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('credit-lift')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('lift-send')));
    await tester.pumpAndSettle();
    expect(api.lifts, isEmpty, reason: 'a reason is required');

    await tester.enterText(find.byKey(const ValueKey('lift-reason')), 'মালিক দেখেছেন');
    await tester.tap(find.byKey(const ValueKey('lift-send')));
    await tester.pumpAndSettle();
    expect(api.lifts, ['c-late|মালিক দেখেছেন']);
  });

  group('the door', () {
    AuthUser user(List<String> permissions) =>
        AuthUser(id: 'u', name: 'x', email: 'x@x', roles: const [], permissions: permissions);

    test('the tile comes only with credit_control.view, in its own module', () {
      expect(const MenuRepository().ordered(const [], user(const ['customer.view'])).map((i) => i.routeName),
          isNot(contains('credit')));
      final items = const MenuRepository().ordered(const [], user(const ['credit_control.view']));
      expect(NavTree.from(items).module('credit_control')!.screens.map((r) => r.path), ['credit']);
    });

    test('the company\'s phone switch closes it, the customer page link included', () {
      expect(ModuleGate.allows({'customer'}, 'credit'), isFalse);
      expect(ModuleGate.allows({'credit_control'}, 'credit/c-late'), isTrue);
    });

    test('a limit request reads by name in the signing inbox', () {
      const approval = ApprovalRecord({
        'id': 'a1',
        'documentType': 'CustomerCreditRequest',
        'amount': null,
        'currentLevel': 1,
      });
      expect(approval.documentTypeLabel, 'বাকির সীমা বাড়ানোর অনুরোধ');
    });
  });
}
