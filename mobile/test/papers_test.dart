import 'package:abos_mobile/core/auth/session_profile.dart';
import 'package:abos_mobile/core/menu/module_gate.dart';
import 'package:abos_mobile/core/navigation/paper_kinds.dart';
import 'package:abos_mobile/core/navigation/recent_papers.dart';
import 'package:abos_mobile/core/records/money.dart';
import 'package:abos_mobile/core/router/native_for_web.dart';
import 'package:abos_mobile/core/widgets/empty_state.dart';
import 'package:abos_mobile/features/books/books_widgets.dart';
import 'package:abos_mobile/features/papers/paper_screens.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// ⭐ মালিক, ১৭ অক্টোবর ২০২৬: *"mobile er ta mobile app style e zate bujazay sei vabe koro, direct webpage app e bujajayna"*।
/// ক্রয়, বিক্রয়ের বাকি কাগজ আর স্থানান্তর — ফোনের নিজের তালিকা ও পাতা; ওয়েবের পথ থেকে সেই পর্দা।
class _FakePapers implements PaperApi {
  _FakePapers({this.dated = true, this.money = true, this.print = true, this.empty = false});

  final bool empty;
  final bool dated;
  final bool money;
  final bool print;
  final List<String> asked = [];

  @override
  Future<PaperPage> list(String key,
      {String? from, String? to, String q = '', String? status, String? branch, int page = 1}) async {
    asked.add('$key|$from|$to|$q|$status|$branch');
    return PaperPage.fromJson({
      'type': key,
      'filters': {
        'dated': dated,
        'branches': true,
        'searchable': true,
        'statuses': [
          {'code': 'draft', 'label': 'খসড়া'},
          {'code': 'confirmed', 'label': 'নিশ্চিত'},
        ],
      },
      'rows': [
        if (!empty) {
          'id': 'po-1',
          'no': 'PO-0007',
          'date': '2026-10-17',
          'date_label': '17/10/2026',
          'status': 'draft',
          'status_label': 'খসড়া',
          'branch': 'ময়মনসিংহ',
          'party': 'আকিজ ফুড',
          'note': null,
          'total': money ? '125000.00' : null,
        },
      ],
      'count': 3,
      'page_sum': money ? '125000.00' : null,
      'sum': money ? '130000.00' : null,
      'next_page': null,
    });
  }

  @override
  Future<PaperDetail> one(String key, String id) async => PaperDetail.fromJson({
        'id': id,
        'no': 'PO-0007',
        'status': 'draft',
        'status_label': 'খসড়া',
        'branch': 'ময়মনসিংহ',
        'party': 'আকিজ ফুড',
        'facts': [
          {'label': 'সরবরাহকারী', 'value': 'আকিজ ফুড'},
          {'label': 'তারিখ', 'value': '17/10/2026'},
        ],
        'columns': [
          {'key': 'product', 'label': 'পণ্য', 'numeric': false},
          {'key': 'qty', 'label': 'পরিমাণ', 'numeric': true},
          if (money) ...[
            {'key': 'rate', 'label': 'দর', 'numeric': true},
            {'key': 'amount', 'label': 'অঙ্ক', 'numeric': true},
          ],
        ],
        'rows': [
          {'product': 'P-1 - কসমস বিস্কুট', 'qty': '10.00 কার্টন', if (money) 'rate': '12,500.00', if (money) 'amount': '1,25,000.00'},
        ],
        'totals': money ? {'amount': '1,25,000.00'} : <String, String>{},
        'signatures': [
          {
            'status': 'pending',
            'status_label': 'অপেক্ষমাণ',
            'level': 1,
            'steps': ['ব্যবস্থাপক', 'মালিক'],
            'requested_by': 'রহিম',
            'requested_at': '17/10/2026 10:00',
            'decisions': [
              {'level': 1, 'by': 'করিম', 'decision': 'approved', 'decision_label': 'অনুমোদিত', 'remarks': 'ঠিক আছে', 'at': '17/10/2026 11:00'},
            ],
            'can_sign': true,
            'approval_id': 'ap-1',
          },
        ],
        'print_type': print ? 'PurchaseOrder' : null,
      });
}

Future<List<OrgRef>> _twoBranches() async => const [
      OrgRef(publicId: 'b-mms', code: 'MMS', name: 'ময়মনসিংহ'),
      OrgRef(publicId: 'b-ntk', code: 'NTK', name: 'নেত্রকোনা'),
    ];

void main() {
  testWidgets('the list asks this month, shows each paper, the grand total and this page\'s total', (tester) async {
    final api = _FakePapers();
    await tester.pumpWidget(MaterialApp(
        home: PaperListScreen(paperKey: 'purchase_order', api: api, branches: _twoBranches, today: DateTime(2026, 10, 17))));
    await tester.pumpAndSettle();

    expect(find.text('ক্রয়াদেশ'), findsOneWidget);
    expect(api.asked.first, 'purchase_order|2026-10-01|2026-10-17||null|null');
    expect(find.text('PO-0007'), findsOneWidget);
    expect(find.textContaining('আকিজ ফুড'), findsOneWidget);
    expect(find.text(Money.taka('125000')), findsWidgets);
    expect(find.text('সর্বমোট'), findsOneWidget);
    expect(find.text(Money.taka('130000')), findsOneWidget);
    expect(find.byKey(const ValueKey('paper-page-sum')), findsOneWidget);
    expect(find.byType(DateRangeBar), findsOneWidget);
  });

  testWidgets('status and branch chips come from the server and go back to it', (tester) async {
    final api = _FakePapers();
    await tester.pumpWidget(MaterialApp(
        home: PaperListScreen(paperKey: 'purchase_order', api: api, branches: _twoBranches, today: DateTime(2026, 10, 17))));
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const ValueKey('paper-status-confirmed')));
    await tester.pumpAndSettle();
    expect(api.asked.last, endsWith('|confirmed|null'));

    await tester.tap(find.byKey(const ValueKey('paper-branch-b-ntk')));
    await tester.pumpAndSettle();
    expect(api.asked.last, endsWith('|confirmed|b-ntk'));

    await tester.enterText(find.byKey(const ValueKey('paper-search')), 'PO-7');
    await tester.testTextInput.receiveAction(TextInputAction.search);
    await tester.pumpAndSettle();
    expect(api.asked.last, contains('|PO-7|'));
  });

  testWidgets('a paper without money shows no totals, and an undated one no date bar', (tester) async {
    final api = _FakePapers(dated: false, money: false);
    await tester.pumpWidget(MaterialApp(
        home: PaperListScreen(paperKey: 'purchase_contract', api: api, branches: _twoBranches, today: DateTime(2026, 10, 17))));
    await tester.pumpAndSettle();

    expect(find.text('সর্বমোট'), findsNothing);
    expect(find.byKey(const ValueKey('paper-page-sum')), findsNothing);
    expect(find.byType(DateRangeBar), findsNothing, reason: 'তারিখহীন কাগজে তারিখের পট্টি');
    expect(find.text('3টি'), findsOneWidget);
  });

  testWidgets('nothing in the range is an empty state that a pull refreshes', (tester) async {
    final api = _FakePapers(empty: true);
    await tester.pumpWidget(MaterialApp(
        home: PaperListScreen(paperKey: 'inventory_transfer', api: api, branches: _twoBranches, today: DateTime(2026, 10, 17))));
    await tester.pumpAndSettle();

    expect(find.text('কোনো মাল স্থানান্তর নেই'), findsOneWidget);
    expect(find.ancestor(of: find.byType(EmptyState), matching: find.byType(RefreshIndicator)), findsOneWidget);
    final before = api.asked.length;
    await tester.fling(find.byType(ListView), const Offset(0, 400), 1000);
    await tester.pumpAndSettle();
    expect(api.asked.length, greaterThan(before), reason: 'টেনে নামালে আবার আনা হয়নি');
  });

  testWidgets('a paper\'s page: facts, lines, total, the signatures and print', (tester) async {
    await tester.pumpWidget(MaterialApp(home: PaperScreen(paperKey: 'purchase_order', id: 'po-1', api: _FakePapers())));
    await tester.pumpAndSettle();

    expect(find.text('ক্রয়াদেশ · PO-0007'), findsOneWidget);
    expect(find.text('সরবরাহকারী'), findsOneWidget);
    expect(find.text('P-1 - কসমস বিস্কুট'), findsOneWidget);
    expect(find.textContaining('পরিমাণ 10.00 কার্টন'), findsOneWidget);
    expect(find.text('মোট অঙ্ক'), findsOneWidget);
    expect(find.text('অপেক্ষমাণ'), findsOneWidget);
    expect(find.textContaining('ব্যবস্থাপক → মালিক'), findsOneWidget);
    expect(find.textContaining('অনুমোদিত · করিম'), findsOneWidget);
    expect(find.byKey(const ValueKey('paper-sign')), findsOneWidget);
    expect(find.byKey(const ValueKey('paper-print')), findsOneWidget);
  });

  testWidgets('a paper the web cannot print has no print button, and no prices without the key', (tester) async {
    await tester.pumpWidget(
        MaterialApp(home: PaperScreen(paperKey: 'purchase_requisition', id: 'pr-1', api: _FakePapers(print: false, money: false))));
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('paper-print')), findsNothing);
    expect(find.byKey(const ValueKey('paper-totals')), findsNothing);
    expect(find.textContaining('দর'), findsNothing);
  });

  test('the web paths open the phone\'s own list, the four price lists by their target', () {
    for (final entry in NativeForWeb.papers.entries) {
      final screen = NativeForWeb.screenFor(entry.key, title: 'x');
      expect(screen, isA<ModuleGateView>(), reason: entry.key);
      expect(((screen! as ModuleGateView).child as PaperListScreen).paperKey, entry.value, reason: entry.key);
    }
    for (final target in NativeForWeb.priceLists) {
      final screen = NativeForWeb.screenFor('/sales/price-lists?target=$target') as ModuleGateView;
      expect((screen.child as PaperListScreen).paperKey, 'sales_price_list_$target');
    }
    expect(NativeForWeb.screenFor('/sales/price-lists?target=nonsense'), isNull);
    expect(NativeForWeb.screenFor('/purchase/bills/create'), isNull, reason: 'লেখার ফর্ম ফোনের পর্দায় নয়');
  });

  test('every paper kind reopens from recents behind its module\'s switch', () {
    // ⓘ ১৭টা ক্রয়-বিক্রয়-মজুদের, তিনটা হিসাব আর অর্থের (১৭ অক্টোবর ২০২৬)
    expect(PaperKinds.all, hasLength(20));
    for (final key in PaperKinds.all.keys) {
      expect(RecentPapers.kinds[key], PaperKinds.all[key], reason: key);
      expect(ModuleGate.moduleOfPath.containsKey(PaperKinds.all[key]!.$1), isTrue, reason: key);
      expect(RecentPapers.listPathOf(RecentPapers.path(key, 'x')), PaperKinds.all[key]!.$1);
    }
  });
}
