import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'package:abos_mobile/core/approvals/approvals_api.dart';
import 'package:abos_mobile/core/auth/auth_controller.dart';
import 'package:abos_mobile/core/auth/auth_state.dart';
import 'package:abos_mobile/core/auth/auth_user.dart';
import 'package:abos_mobile/core/navigation/nav_state.dart';
import 'package:abos_mobile/core/navigation/top_bar/top_bar_context.dart';
import 'package:abos_mobile/core/books/books_api.dart';
import 'package:abos_mobile/core/records/approval_record.dart';
import 'package:abos_mobile/core/records/customer_record.dart';
import 'package:abos_mobile/core/records/today_record.dart';
import 'package:abos_mobile/core/theme/app_theme.dart';
import 'package:abos_mobile/features/books/money_in_screens.dart';
import 'package:abos_mobile/features/home/home_dashboard.dart';

/// ⓘ ০.৪.৪১-এর দুই পর্দার নমুনা তথ্য — মালিকের অনুমোদিত পুরো-পাতার নমুনার একই নাম আর অঙ্ক (২০ অক্টোবর ২০২৬, পাতা ২)।

/// মালিকের "আজ" — নমুনার অঙ্ক। ⚠️ `salesByBranch` আজকের সার্ভার পাঠায় না; এখানে নমুনার মতো দেখাতে দেওয়া।
///
/// ⓘ বাড়তি চাবি (০.৪.৪০-এর অঙ্ক ফেরত, ২০ অক্টোবর ২০২৬) — না দিলে আগের মতোই: [outflow] `outflow.amount`, [payable]
/// `payable` ব্লক, [principals]/[principalsTotal], [mfsAccounts] `money.mfsAccounts`, [sales] বিক্রির ব্লক বদলে, আর
/// [mfs]/[transit]/[moneyTotal] টাকার বাক্সের অঙ্ক।
Map<String, dynamic> ownerToday({
  bool withCash = true,
  bool withBranches = true,
  String? outflow,
  String? stockValue,
  Map<String, dynamic>? payable,
  List<Map<String, dynamic>>? principals,
  Map<String, dynamic>? principalsTotal,
  List<Map<String, dynamic>>? mfsAccounts,
  Map<String, dynamic>? sales,
  String mfs = '0.0000',
  String transit = '0.0000',
  String moneyTotal = '1166320.0000',
}) =>
    {
      'date': '2026-10-20',
      'company': 'ADI Wholesale',
      'branch': 'সব শাখা',
      'asOf': '2026-10-20T10:30:00+06:00',
      'sales': sales ?? {'amount': '162700.0000', 'count': 9},
      'collections': {'amount': '170000.0000', 'count': 6},
      'dues': {'amount': '845210.0000', 'shops': 41},
      'inflow': {'amount': '170000.0000'},
      if (outflow != null) 'outflow': {'amount': outflow},
      if (stockValue != null) 'stockValue': {'amount': stockValue, 'basis': 'cost'},
      if (payable != null) 'payable': payable,
      if (principals != null) 'principals': principals,
      if (principalsTotal != null) 'principalsTotal': principalsTotal,
      'money': {
        'amount': moneyTotal,
        if (withCash) 'cash': '0.0000',
        'mfs': mfs,
        'bank': '1166320.0000',
        'transit': transit,
        if (mfsAccounts != null) 'mfsAccounts': mfsAccounts,
      },
      'approvals': {'pending': 3},
      if (withBranches)
        'salesByBranch': [
          {'branch': 'ময়মনসিংহ', 'amount': '124500.0000'},
          {'branch': 'নেত্রকোনা', 'amount': '38200.0000'},
        ],
    };

const waitingPapers = ApprovalPage(rows: [
  ApprovalRecord({
    'id': 'a1', 'documentType': 'Collection', 'documentNo': 'RCV-0190', 'amount': '50000.0000', 'currentLevel': 1,
    'party': 'রহিম স্টোর · কেন্দুয়া · ময়মনসিংহ',
  }),
  ApprovalRecord({
    'id': 'a2', 'documentType': 'PurchaseBill', 'documentNo': 'PBL-0010', 'amount': '487900.0000', 'currentLevel': 2,
    'party': 'ACI লিমিটেড · ময়মনসিংহ',
  }),
  ApprovalRecord({
    'id': 'a3', 'documentType': 'ExpenseClaim', 'documentNo': 'EXP-0044', 'amount': '2200.0000', 'currentLevel': 1,
    'party': 'গাড়ির ভাড়া · নেত্রকোনা',
  }),
]);

const waitingWithLevels = ApprovalPage(rows: [
  ApprovalRecord({
    'id': 'a1', 'documentType': 'Collection', 'documentNo': 'RCV-0190', 'amount': '50000.0000', 'currentLevel': 1, 'levels': 2,
    'party': 'রহিম স্টোর · কেন্দুয়া · ময়মনসিংহ',
  }),
]);

const ownerUser = AuthUser(id: 'u1', name: 'আল-আমিন শুভ', email: 'o@x', roles: ['Owner'], permissions: []);

Widget ownerHomeBody(
        {Map<String, dynamic>? today,
        Future<ApprovalPage> Function()? loadWaiting,
        void Function(String path, String label)? onOpen,
        AuthUser user = ownerUser}) =>
    Scaffold(
      body: HomeDashboard(
        user: user,
        items: const [],
        headerNamesOrg: true,
        fetchToday: () async => TodayRecord(today ?? ownerToday()),
        lastKnownToday: () => null,
        loadWaiting: loadWaiting ?? () async => waitingPapers,
        onOpen: onOpen ?? (_, __) {},
      ),
    );

/// নমুনার আদায় — নাম সার্ভারের মতো `customer` · `customer_point` · `customer_branch` থেকে (MoneyInRow.fromJson → withPoint)
final collectionRows = [
  MoneyInRow.fromJson(const {'id': 'c1', 'no': 'RCV-0191', 'date': '2026-10-20', 'customer': 'মুসকান এন্টারপ্রাইজ',
      'customer_point': 'ফুলবাড়িয়া', 'customer_branch': 'ময়মনসিংহ', 'account': 'প্রধান নগদ', 'method': 'cash', 'amount': '30000.0000'}),
  MoneyInRow.fromJson(const {'id': 'c2', 'no': 'RCV-0192', 'date': '2026-10-20', 'customer': 'রহিম স্টোর',
      'customer_point': 'কেন্দুয়া', 'customer_branch': 'ময়মনসিংহ', 'account': 'প্রধান নগদ', 'method': 'cash', 'amount': '12500.0000'}),
  MoneyInRow.fromJson(const {'id': 'c3', 'no': 'RCV-0193', 'date': '2026-10-20', 'customer': 'নূর ট্রেডার্স',
      'customer_point': 'ত্রিশাল', 'customer_branch': 'ময়মনসিংহ', 'account': 'বিকাশ', 'method': 'mfs', 'amount': '8000.0000'}),
];

/// নমুনার দোকানের বকেয়া — নাম [CustomerRecord.label] থেকে ("নাম · পয়েন্ট · শাখা")
List<ShopDue> sampleDues() => [
      ShopDue(const CustomerRecord({'id': 's1', 'nameBn': 'মুসকান এন্টারপ্রাইজ', 'pointName': 'ফুলবাড়িয়া', 'branchName': 'ময়মনসিংহ'}).label,
          47835.57),
      ShopDue(const CustomerRecord({'id': 's2', 'nameBn': 'রহিম স্টোর', 'pointName': 'কেন্দুয়া', 'branchName': 'ময়মনসিংহ'}).label, 22140),
    ];

class SampleBooks implements BooksApi {
  SampleBooks({List<MoneyInRow>? rows}) : rows = rows ?? collectionRows;

  final List<MoneyInRow> rows;

  @override
  Future<MoneyInPage> moneyIn({required String from, required String to, String? q, String? method, int page = 1}) async =>
      MoneyInPage(rows: rows, total: rows.fold<double>(0, (s, r) => s + r.amount), count: rows.length);

  @override
  dynamic noSuchMethod(Invocation invocation) => throw UnimplementedError();
}

Widget srCollectBody(
        {List<ShopDue> Function()? dues, BooksApi? api, List<UnsentCollection> Function()? unsent, bool canReportDeposit = false}) =>
    MoneyInListScreen(
        api: api ?? SampleBooks(),
        today: DateTime(2026, 10, 20),
        dues: dues ?? sampleDues,
        unsent: unsent ?? () => const [],
        canReportDeposit: canReportDeposit);

class _SignedIn extends AuthController {
  _SignedIn(AuthUser user) {
    state = AuthState.signedIn(user);
  }
}

/// নমুনার বার — "ADI Wholesale · ময়মনসিংহ" (পুরো-পাতার নমুনা, পাতা ২)
TopBarContext sampleTopBar({String? Function(String path)? moduleOf}) => TopBarContext(
      company: 'ADI Wholesale',
      companyCode: 'ADI',
      branch: 'ময়মনসিংহ',
      moduleOf: moduleOf,
      search: (_) async {},
      openNotifications: (_) async {},
      openMenu: (_, __) async {},
      messages: (_) async {},
    );

/// ঢোকা মানুষ আর হোমের লেখা বার — ভেতরের পর্দার বার ([AbosScreenBar]) তখন আসল নীল বার আঁকে
Widget inSession(Widget app, {AuthUser user = ownerUser, TopBarContext? top, int unread = 3}) => ProviderScope(
      overrides: [
        authStateProvider.overrideWith((ref) => _SignedIn(user)),
        topBarContextProvider.overrideWith((ref) => top ?? sampleTopBar()),
        navBadgesProvider.overrideWith((ref) => NavBadges(unread: unread)),
      ],
      child: app,
    );

/// অ্যাপের নিজের থিমে, ঠিক মাপে আর অক্ষরের মাপে
Widget framed(Widget child, {bool dark = false, double textScale = 1, ThemeData Function(ThemeData)? font}) {
  final light = AppTheme.light();
  final darkTheme = AppTheme.dark();
  return MaterialApp(
    debugShowCheckedModeBanner: false,
    theme: font == null ? light : font(light),
    darkTheme: font == null ? darkTheme : font(darkTheme),
    themeMode: dark ? ThemeMode.dark : ThemeMode.light,
    builder: (context, inner) =>
        MediaQuery(data: MediaQuery.of(context).copyWith(textScaler: TextScaler.linear(textScale)), child: inner!),
    home: child,
  );
}
