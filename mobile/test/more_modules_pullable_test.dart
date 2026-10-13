import 'dart:typed_data';

import 'package:abos_mobile/core/documents_library/library_api.dart';
import 'package:abos_mobile/core/finance/finance_api.dart';
import 'package:abos_mobile/core/hr/self_service_api.dart';
import 'package:abos_mobile/core/promotion/offers_api.dart';
import 'package:abos_mobile/core/widgets/empty_state.dart';
import 'package:abos_mobile/features/documents/document_screens.dart';
import 'package:abos_mobile/features/finance/finance_screens.dart';
import 'package:abos_mobile/features/promotion/offers_screen.dart';
import 'package:abos_mobile/features/self_service/self_service_screens.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// ⭐ বাকি মডিউলের প্রতিটা খালি পর্দা টেনে নবায়ন হয় — "কিছু নেই" লেখার নিচে টানলে আবার জিজ্ঞেস করে
/// (test/pull_to_refresh_test.dart-এর একই দাবি; তালিকাগুলো [PagedList]-এ, তাই ওখানকার ফাইল-খোঁজায় পড়ে না)।
class _Empty implements FinanceApi, DocumentLibraryApi, OffersApi, SelfServiceApi {
  int calls = 0;

  @override
  Future<FinancePage<Deposit>> deposits({String tab = 'active', int page = 1}) async {
    calls++;
    return const FinancePage([]);
  }

  @override
  Future<FinancePage<BankLoan>> loans({String tab = 'open', int page = 1}) async => const FinancePage([]);

  @override
  Future<FinancePage<Rent>> rents({String side = 'pay', String tab = 'running', int page = 1}) async => const FinancePage([]);

  @override
  Future<HandLoanBook> handLoans() async => const HandLoanBook(rows: []);

  @override
  Future<Deposit> deposit(String id) => throw UnimplementedError();

  @override
  Future<BankLoan> loan(String id) => throw UnimplementedError();

  @override
  Future<Rent> rent(String side, String id) => throw UnimplementedError();

  @override
  Future<HandLoan> handLoan(String id) => throw UnimplementedError();

  @override
  Future<LibraryPage> list({String view = 'center', String q = '', int page = 1}) async => const LibraryPage([]);

  @override
  Future<LibraryDocument> one(String id) => throw UnimplementedError();

  @override
  Future<Uint8List> file(String id, {bool download = false}) => throw UnimplementedError();

  @override
  Future<void> sign(String approvalId, {String? remarks}) => throw UnimplementedError();

  @override
  Future<void> refuse(String approvalId, {required String remarks}) => throw UnimplementedError();

  @override
  Future<OffersPage> running() async => const OffersPage(enabled: true, rows: []);

  @override
  Future<LeaveBook> leave() async => const LeaveBook(employee: true);

  @override
  Future<LeaveRequest> applyLeave(LeaveDraft draft) => throw UnimplementedError();

  @override
  Future<PayslipPage> payslips({int page = 1}) async => const PayslipPage(employee: true, rows: []);

  @override
  Future<Payslip> payslip(String id) => throw UnimplementedError();
}

Future<void> _pullable(WidgetTester tester, Widget screen) async {
  await tester.pumpWidget(MaterialApp(home: screen));
  await tester.pumpAndSettle();

  expect(find.byType(EmptyState), findsWidgets, reason: 'the empty state never drew — the check below would pass on nothing');
  expect(find.ancestor(of: find.byType(EmptyState).first, matching: find.byType(RefreshIndicator)), findsOneWidget,
      reason: 'an EmptyState outside a RefreshIndicator makes the pull do nothing');
}

void main() {
  final api = _Empty();

  testWidgets('জমা', (t) => _pullable(t, DepositListScreen(api: api)));
  testWidgets('ব্যাংক ঋণ', (t) => _pullable(t, LoanListScreen(api: api)));
  testWidgets('ভাড়া', (t) => _pullable(t, RentListScreen(api: api)));
  testWidgets('হাতধার', (t) => _pullable(t, HandLoanListScreen(api: api)));
  testWidgets('ডকুমেন্টস', (t) => _pullable(t, DocumentListScreen(api: api)));
  testWidgets('চলতি অফার', (t) => _pullable(t, OffersScreen(api: api)));
  testWidgets('আমার ছুটি', (t) => _pullable(t, LeaveScreen(api: api)));
  testWidgets('বেতনের স্লিপ', (t) => _pullable(t, PayslipListScreen(api: api)));

  testWidgets('a pull on the empty list really asks the server again', (tester) async {
    final empty = _Empty();
    await tester.pumpWidget(MaterialApp(home: DepositListScreen(api: empty)));
    await tester.pumpAndSettle();
    expect(empty.calls, 1);

    await tester.fling(find.byType(ListView), const Offset(0, 400), 1000);
    await tester.pumpAndSettle();
    expect(empty.calls, 2);
  });
}
