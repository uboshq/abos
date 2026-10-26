import 'package:abos_mobile/core/widgets/empty_state.dart';
import 'package:abos_mobile/features/executive/owner_alerts_screen.dart';
import 'package:abos_mobile/features/executive/owner_analysis_screen.dart';
import 'package:abos_mobile/features/executive/owner_compare_screen.dart';
import 'package:abos_mobile/features/executive/owner_history_screen.dart';
import 'package:abos_mobile/features/executive/owner_pages_api.dart';
import 'package:abos_mobile/features/executive/owner_reports_screen.dart';
import 'package:abos_mobile/features/executive/sister_links_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// ⭐ মালিকের কেন্দ্রের বাকি ছয় পাতা, ফোনের নিজের পর্দায় — মালিক, ১৭ অক্টোবর ২০২৬: "100% app e, app er nijer style e, sob Bangla,
/// protita list e total"।
///
/// <p>দাবি: প্রতিটা পর্দা সার্ভারের সংখ্যা যেমন আসে তেমন আঁকে — টাকা লাখ-কোটির ভাগে, ঢাকা ঘর "••••" (শূন্য নয়), না থাকা "—";
/// বাছাই বদলালে ঠিক সেই প্রশ্নটা আবার পাঠায়; টানলে নতুন করে আনে; মোটের পট্টি সার্ভারের গোটা তালিকার, পুরনো সার্ভার না পাঠালে পট্টিই নেই;
/// নিজের রিপোর্ট ফোনের সাধারণ রিপোর্টের পর্দায় খোলে; ভুল হলে বাংলায় বলে।

const _alpha = '0199c1d2-0000-7000-8000-000000000001';
const _beta = '0199c1d2-0000-7000-8000-000000000002';

class _Fake implements OwnerPagesApi {
  final List<String> asked = [];
  Object? fail;
  bool oldServer = false;
  bool noRows = false;

  void _ask(String what) {
    asked.add(what);
    if (fail != null) throw fail!;
  }

  @override
  Future<OwnerCompare> compare({required String mode, String? company, required String against, required String grain, required String figure}) async {
    _ask('compare:$mode:${company ?? '-'}:$against:$grain:$figure');
    return OwnerCompare.fromJson({
      'mode': mode,
      'against': against,
      'company': mode == 'branches' ? (company ?? _alpha) : company,
      'now': {'from': '2026-10-01', 'to': '2026-10-19'},
      'was': {'from': '2026-09-01', 'to': '2026-09-19'},
      'companies': [
        {'id': _alpha, 'name': 'টিডি ডিপো'},
        {'id': _beta, 'name': 'ফ্রেশ মার্ট'},
      ],
      'columns': [
        {'key': 'sales', 'label': 'Sales', 'trend': true},
        {'key': 'receivable', 'label': 'Receivable', 'trend': false},
        {'key': 'profit', 'label': 'Profit', 'trend': true},
      ],
      'rows': [
        {
          'id': _alpha,
          'name': 'টিডি ডিপো',
          'company': _alpha,
          'branch': null,
          'now': {'sales': '1250000.0000', 'receivable': '40000.0000', 'profit': 'hidden'},
          'was': {'sales': '1000000.0000', 'receivable': null, 'profit': 'hidden'},
          'change': {'sales': '25.00', 'receivable': null, 'profit': null},
        },
      ],
      'grain': grain,
      'figure': figure,
      'trend': [
        {'label': 'অক্টো ২০২৬', 'from': '2026-10-01', 'to': '2026-10-19', 'value': '1250000.0000', 'last_year': '0'},
      ],
    });
  }

  @override
  Future<OwnerAnalysis> analysis(String period) async {
    _ask('analysis:$period');
    return OwnerAnalysis.fromJson({
      'period': period,
      'from': '2026-10-01',
      'to': '2026-10-19',
      'many': true,
      'ageing': {
        'buckets': [
          {'key': 'bucket_current', 'label': 'x', 'value': '100000.0000'},
          {'key': 'bucket_30', 'label': 'x', 'value': '20000.0000'},
          {'key': 'bucket_60', 'label': 'x', 'value': '0.0000'},
          {'key': 'bucket_90', 'label': 'x', 'value': '0.0000'},
          {'key': 'outstanding', 'label': 'x', 'value': '120000.0000'},
        ],
        'refused': ['ফ্রেশ মার্ট'],
      },
      'lists': [
        {
          'key': 'customers',
          'label': 'Top 10 customers',
          'kind': 'money',
          'rows': [
            {'name': 'রহিম ট্রেডার্স', 'company_name': 'টিডি ডিপো', 'value': '4500.0000'},
          ],
          'refused': [],
        },
        {
          'key': 'losing',
          'label': 'x',
          'kind': 'money',
          'rows': [
            {'name': 'করিম স্টোর', 'company_name': 'টিডি ডিপো', 'value': 'hidden'},
          ],
          'refused': [],
        },
        {
          'key': 'thin',
          'label': 'x',
          'kind': 'percent',
          'rows': [
            {'name': 'কসমস বিস্কুট', 'company_name': 'টিডি ডিপো', 'value': '-8.5000'},
          ],
          'refused': [],
        },
        {'key': 'dead', 'label': 'x', 'kind': 'money', 'rows': [], 'refused': []},
      ],
      'can_see_cost': false,
      'report': {
        'key': 'executive.profit_by_customer',
        'title': 'Profit by customer',
        'hint': 'english hint',
        'module': 'executive',
        'filters': ['date_range', 'branch'],
        'allowed': true,
      },
    });
  }

  @override
  Future<OwnerAlerts> alerts() async {
    _ask('alerts');
    return OwnerAlerts.fromJson({
      'companies': [
        {'id': _alpha, 'name': 'টিডি ডিপো', 'current': true},
        {'id': _beta, 'name': 'ফ্রেশ মার্ট', 'current': false},
      ],
      'alerts': [
        {
          'kind': 'overdue',
          'label': 'Overdue',
          'total': 1234,
          'companies': [
            {'id': _alpha, 'name': 'টিডি ডিপো', 'count': 1234},
            {'id': _beta, 'name': 'ফ্রেশ মার্ট', 'count': null},
          ],
        },
        {
          'kind': 'stock_dead',
          'label': 'Dead',
          'total': 0,
          'companies': [
            {'id': _alpha, 'name': 'টিডি ডিপো', 'count': 0},
            {'id': _beta, 'name': 'ফ্রেশ মার্ট', 'count': 0},
          ],
        },
      ],
      'waiting': [
        {'company': 'টিডি ডিপো', 'label': 'বিল INV-1', 'amount': '250000.0000', 'requested_at': '2026-10-18 10:00:00'},
      ],
    });
  }

  @override
  Future<OwnerHistory> history({String? date, required String figure}) async {
    _ask('history:${date ?? '-'}:$figure');
    return OwnerHistory.fromJson({
      'date': date ?? '2026-09-19',
      'figure': figure,
      'figures': [
        {'key': 'sales', 'label': 'x', 'money': true},
        {'key': 'receivable', 'label': 'x', 'money': true},
        {'key': 'signatures', 'label': 'x', 'money': false},
      ],
      'rows': noRows
          ? []
          : [
              {
                'company': _alpha,
                'company_name': 'টিডি ডিপো',
                'branch': '0199c1d2-0000-7000-8000-00000000000b',
                'name': 'নেত্রকোনা',
                'whole': false,
                'values': {'sales': '5000.0000', 'receivable': '30000.0000', 'signatures': null},
              },
              {
                'company': _alpha,
                'company_name': 'টিডি ডিপো',
                'branch': null,
                'name': 'টিডি ডিপো — মোট',
                'whole': true,
                'values': {'sales': '9000.0000', 'receivable': '1234567.0000', 'signatures': '3', 'fund': 'hidden'},
              },
            ],
      'series': [
        {'day': '2026-09-18', 'value': '1200000.0000', 'month_before': null},
        {'day': '2026-09-19', 'value': '1234567.0000', 'month_before': '1100000.0000'},
      ],
    });
  }

  @override
  Future<OwnerReports> reports() async {
    _ask('reports');
    return OwnerReports.fromJson({
      'own': [
        {
          'key': 'executive.profit_by_customer',
          'title': 'Profit by customer',
          'hint': 'english',
          'module': 'executive',
          'filters': ['date_range'],
          'allowed': true,
        },
      ],
      'views': [
        {'name': 'বড় ক্রেতা', 'company': 'টিডি ডিপো'},
        {'name': 'মাসের বিক্রি', 'company': 'ফ্রেশ মার্ট'},
      ],
      'can_schedule': false,
    });
  }

  @override
  Future<SisterLinksPage> links(int page) async {
    _ask('links:$page');
    return SisterLinksPage.fromJson({
      'links': [
        {
          'id': '0199c1d2-0000-7000-8000-0000000000a$page',
          'company': 'টিডি ডিপো',
          'sister': 'ফ্রেশ মার্ট',
          'type': page == 1 ? 'customer' : 'supplier',
          'type_label': 'Customer',
          'party': page == 1 ? 'আলম স্টোর' : 'ফ্রেশ সাপ্লাই',
        },
      ],
      if (!oldServer) 'total': 120,
      'next_page': page == 1 ? 2 : null,
    });
  }
}

Future<void> _show(WidgetTester tester, Widget screen) async {
  // ⓘ লম্বা পর্দা — তালিকার নিচের কার্ডও আঁকা থাকে (ListView না-দেখা সারি বানায় না)
  tester.view.physicalSize = const Size(1080, 6000);
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);
  await tester.pumpWidget(MaterialApp(home: screen));
  await tester.pumpAndSettle();
}

Future<void> _tap(WidgetTester tester, Finder finder) async {
  await tester.ensureVisible(finder);
  await tester.pumpAndSettle();
  await tester.tap(finder);
  await tester.pumpAndSettle();
}

/// টেনে নবায়ন — পর্দার নিজের [RefreshIndicator] যা ডাকে, তা-ই (আর সেটা একটা গড়ানো তালিকার উপরে, যাতে টানা চলে)
Future<void> _pull(WidgetTester tester) async {
  final refresh = find.byType(RefreshIndicator);
  expect(find.descendant(of: refresh, matching: find.byType(ListView)), findsOneWidget, reason: '⛔ টানার নিচে গড়ানো তালিকা নেই');
  await tester.widget<RefreshIndicator>(refresh).onRefresh();
  await tester.pumpAndSettle();
}

void main() {
  group('তুলনা', () {
    testWidgets('সংখ্যা যেমন আসে — টাকা লাখের ভাগে, ঢাকা ঘর ••••, বদল ▲; বাছাই বদলালে ঠিক সেই প্রশ্ন', (tester) async {
      final api = _Fake();
      await _show(tester, OwnerCompareScreen(api: api));

      expect(api.asked, ['compare:companies:-:previous_month:monthly:sales']);
      expect(find.text('৳12,50,000.00'), findsWidgets, reason: '⛔ টাকা লাখ-কোটির ভাগে নয়');
      expect(find.text('তখন ৳10,00,000.00'), findsOneWidget);
      expect(find.text('▲ 25.00%'), findsOneWidget);
      expect(find.text('••••'), findsOneWidget, reason: '⛔ ঢাকা লাভ শূন্য বা খালি দেখাল');
      expect(find.text('তখন —'), findsOneWidget, reason: '⛔ রাতের হিসাব না থাকা "তখন" — হওয়ার কথা');
      expect(find.text('বিক্রি'), findsWidgets, reason: '⛔ সংখ্যার নাম বাংলায় নয়');
      expect(find.byKey(const ValueKey('compare-trend')), findsOneWidget);

      await _tap(tester, find.byKey(const ValueKey('mode-branches')));
      expect(api.asked.last, 'compare:branches:-:previous_month:monthly:sales');
      // ⓘ সার্ভার প্রথম কোম্পানি বেছেছে — চিপ সেটাই দেখায়, আবার আনায় না
      expect(api.asked, hasLength(2), reason: '⛔ সার্ভারের বাছাই ফিরে এসে আরেকবার আনাল');
      expect(tester.widget<ChoiceChip>(find.byKey(const ValueKey('company-$_alpha'))).selected, isTrue);

      await _tap(tester, find.byKey(const ValueKey('company-$_beta')));
      expect(api.asked.last, 'compare:branches:$_beta:previous_month:monthly:sales');

      await _tap(tester, find.byKey(const ValueKey('against-last_year')));
      expect(api.asked.last, 'compare:branches:$_beta:last_year:monthly:sales');

      // ⓘ ধারার সংখ্যা কেবল সময়ের — বাকি (জের) চিপে নেই
      expect(find.byKey(const ValueKey('figure-receivable')), findsNothing);
      await _tap(tester, find.byKey(const ValueKey('figure-profit')));
      await _tap(tester, find.byKey(const ValueKey('grain-daily')));
      expect(api.asked.last, 'compare:branches:$_beta:last_year:daily:profit');
    });

    testWidgets('টানলে নতুন করে আনে; ভুল হলে বাংলায় বলে', (tester) async {
      final api = _Fake()..fail = Exception('boom');
      await _show(tester, OwnerCompareScreen(api: api));
      expect(find.text('তুলনার সংখ্যা আনা গেল না।'), findsOneWidget);

      api.fail = null;
      await _pull(tester);
      expect(api.asked, hasLength(2), reason: '⛔ টানলে আবার আনেনি');
      expect(find.byKey(const ValueKey('compare-row-$_alpha')), findsOneWidget);
    });
  });

  group('বিশ্লেষণ', () {
    testWidgets('বাকির বয়স আর মোট বাকির পট্টি, সাত তালিকা, ঢাকা লাভ ••••, ঋণাত্মক মার্জিন; রিপোর্ট ফোনের পর্দায়', (tester) async {
      final api = _Fake();
      await _show(tester, OwnerAnalysisScreen(api: api, reportScreen: (card) => Text('রিপোর্ট খুলল: ${card.key} · ${card.shownTitle}')));

      expect(api.asked, ['analysis:month']);
      expect(find.descendant(of: find.byKey(const ValueKey('analysis-outstanding')), matching: find.text('৳1,20,000.00')), findsOneWidget,
          reason: '⛔ মোট বাকির পট্টি সার্ভারের যোগ নয়');
      expect(find.text('চলতি বাকি'), findsOneWidget);
      expect(find.text('দেখার অনুমতি নেই: ফ্রেশ মার্ট'), findsOneWidget);
      expect(find.text('সেরা 10 ক্রেতা'), findsOneWidget, reason: '⛔ তালিকার নাম বাংলায় নয়');
      expect(find.text('৳4,500.00'), findsOneWidget);
      expect(find.text('••••'), findsOneWidget);
      expect(find.text('-8.50%'), findsOneWidget);
      expect(find.text('এই সময়ে কিছু নেই।'), findsOneWidget);
      expect(find.text('খরচ দেখার অনুমতি নেই — লাভ আর মার্জিনের ঘর ঢাকা থাকবে।'), findsOneWidget);

      await _tap(tester, find.byKey(const ValueKey('analysis-report')));
      expect(find.text('রিপোর্ট খুলল: executive.profit_by_customer · ক্রেতা ধরে লাভ'), findsOneWidget);
    });

    testWidgets('সময় বদলালে সেই সময়টাই চায়', (tester) async {
      final api = _Fake();
      await _show(tester, OwnerAnalysisScreen(api: api));
      await _tap(tester, find.byKey(const ValueKey('period-year')));
      expect(api.asked, ['analysis:month', 'analysis:year']);
    });
  });

  group('সতর্কতা', () {
    testWidgets('গ্রুপের মোট, কোম্পানির ভাগ, চাবি নেই = "অনুমতি নেই" (শূন্য নয়); অনুমোদনের অপেক্ষার গোনা', (tester) async {
      final api = _Fake();
      await _show(tester, OwnerAlertsScreen(api: api));

      expect(tester.widget<Text>(find.byKey(const ValueKey('alert-total-overdue'))).data, '1,234', reason: '⛔ গ্রুপের মোট ভাগসহ নয়');
      expect(find.text('মেয়াদ পেরোনো বাকি'), findsOneWidget);
      expect(find.text('অনুমতি নেই'), findsOneWidget);
      expect(find.text('গ্রুপের মোট'), findsNWidgets(2));
      expect(find.text('কিছু নেই'), findsOneWidget);
      expect(find.text('অনুমোদনের অপেক্ষায় (1টা)'), findsOneWidget);
      expect(find.text('৳2,50,000.00'), findsOneWidget);

      await _pull(tester);
      expect(api.asked, ['alerts', 'alerts']);
    });
  });

  group('আগের দিনের হিসাব', () {
    testWidgets('শাখা আর গোটা কোম্পানির সারি, বাছা সংখ্যা ডানে; সংখ্যা বদলালে সেটাই চায়', (tester) async {
      final api = _Fake();
      await _show(tester, OwnerHistoryScreen(api: api));

      expect(api.asked, ['history:-:receivable']);
      expect(find.text('কোন দিন: 19/09/2026'), findsOneWidget, reason: '⛔ সার্ভারের বাছা দিন চিপে নেই');
      expect(find.text('৳12,34,567.00'), findsOneWidget);
      expect(find.text('টিডি ডিপো — মোট'), findsOneWidget);
      expect(find.byKey(const ValueKey('history-trend')), findsOneWidget);

      await _tap(tester, find.text('টিডি ডিপো — মোট'));
      expect(find.text('••••'), findsOneWidget, reason: '⛔ ঢাকা তহবিল শূন্য দেখাল');
      expect(find.text('3'), findsOneWidget, reason: '⛔ অনুমোদনের গোনা টাকা হয়ে গেল');

      await _tap(tester, find.byKey(const ValueKey('figure-sales')));
      expect(api.asked.last, 'history:-:sales');
    });

    testWidgets('ঐ দিনের রাতের হিসাব নেই — বলে, টানা যায়', (tester) async {
      final api = _Fake()..noRows = true;
      await _show(tester, OwnerHistoryScreen(api: api));
      expect(find.byType(EmptyState), findsOneWidget);
      await _pull(tester);
      expect(api.asked, hasLength(2));
    });
  });

  group('রিপোর্ট', () {
    testWidgets('রিপোর্ট সেন্টার আর নিজের রিপোর্ট ফোনের পর্দায়; সংরক্ষিত দৃশ্যের গোনা', (tester) async {
      final api = _Fake();
      await _show(
          tester,
          OwnerReportsScreen(
            api: api,
            reportScreen: (card) => Text('রিপোর্ট: ${card.summary['key']} · ${card.summary['title']}'),
            centreScreen: () => const Text('সব রিপোর্ট'),
          ));

      expect(find.text('ক্রেতা ধরে লাভ'), findsOneWidget, reason: '⛔ নিজের রিপোর্টের নাম বাংলায় নয়');
      expect(find.text('আমার সংরক্ষিত দৃশ্য (2টা)'), findsOneWidget);
      expect(find.text('বড় ক্রেতা'), findsOneWidget);
      expect(find.text('নির্ধারিত রিপোর্ট বসানোর অনুমতি নেই।'), findsOneWidget);

      await _tap(tester, find.byKey(const ValueKey('reports-own-executive.profit_by_customer')));
      expect(find.text('রিপোর্ট: executive.profit_by_customer · ক্রেতা ধরে লাভ'), findsOneWidget);

      Navigator.of(tester.element(find.text('রিপোর্ট: executive.profit_by_customer · ক্রেতা ধরে লাভ'))).pop();
      await tester.pumpAndSettle();
      await _tap(tester, find.byKey(const ValueKey('reports-centre')));
      expect(find.text('সব রিপোর্ট'), findsOneWidget);
    });
  });

  group('ভাই-কোম্পানির পক্ষ', () {
    testWidgets('মোট জোড়া সার্ভারের গোটা তালিকার (পাতার নয়); আরও দেখুন পরের পাতা আনে; কেবল দেখা', (tester) async {
      final api = _Fake();
      await _show(tester, SisterLinksScreen(api: api));

      expect(find.descendant(of: find.byKey(const ValueKey('links-total')), matching: find.text('120টা')), findsOneWidget,
          reason: '⛔ মোট জোড়া এই পাতার গোনা দেখাল');
      expect(find.text('আলম স্টোর · ক্রেতা'), findsOneWidget, reason: '⛔ পক্ষের ধরন বাংলায় নয়');
      expect(find.text('টিডি ডিপো → আসলে আমাদের ফ্রেশ মার্ট'), findsOneWidget);
      expect(find.text('জোড়া দেওয়া আর তোলা ওয়েবের পাতায় — এখানে কেবল দেখা।'), findsOneWidget);

      await _tap(tester, find.byKey(const ValueKey('books-more')));
      expect(api.asked, ['links:1', 'links:2']);
      expect(find.text('ফ্রেশ সাপ্লাই · সরবরাহকারী'), findsOneWidget);
      expect(find.byKey(const ValueKey('books-more')), findsNothing);
    });

    testWidgets('পুরনো সার্ভার গোনা না পাঠালে পট্টিই নেই — এক পাতার গোনা মিথ্যা মোট', (tester) async {
      final api = _Fake()..oldServer = true;
      await _show(tester, SisterLinksScreen(api: api));
      expect(find.byKey(const ValueKey('links-total')), findsNothing);
    });
  });
}
