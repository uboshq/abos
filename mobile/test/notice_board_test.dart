import 'package:abos_mobile/core/auth/auth_user.dart';
import 'package:abos_mobile/core/menu/menu_repository.dart';
import 'package:abos_mobile/core/menu/module_gate.dart';
import 'package:abos_mobile/core/navigation/nav_catalog.dart';
import 'package:abos_mobile/core/widgets/empty_state.dart';
import 'package:abos_mobile/features/notices/notice_board_screen.dart';
import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// ⭐ নোটিশ বোর্ড, ফোনে — মালিক, ১৩ অক্টোবর ২০২৬: "app e baki module & menu"।
/// তালিকা সার্ভারের; খুললে পড়া হয়েছে বলে দাগ; সই চাওয়া নোটিশে সই, একবারই; সার্ভারের "না" বাংলায়।
class _FakeNotices implements NoticeBoardApi {
  _FakeNotices(this.items, {this.unread = 1});

  List<NoticeItem> items;
  int unread;
  final List<String> read = [];
  final List<String> signed = [];
  Object? signFails;

  @override
  Future<NoticePage> notices() async => NoticePage(items: items, unread: unread);

  @override
  Future<void> markRead(String id) async => read.add(id);

  @override
  Future<void> acknowledge(String id) async {
    if (signFails != null) throw signFails!;
    signed.add(id);
  }
}

const _rule = NoticeItem(
  id: 'n-rule',
  no: 'NTC-0004',
  title: 'নতুন হাজিরার নিয়ম',
  summary: 'সকাল ৯টার মধ্যে',
  body: 'সবাইকে সকাল ৯টার মধ্যে হাজিরা দিতে হবে।',
  priority: 'important',
  publishedAt: '2026-10-12T09:00:00+06:00',
  ackRequired: true,
  ackDeadline: '2026-10-20T17:00:00+06:00',
  standing: 'unread',
  author: 'মালিক',
);

const _news = NoticeItem(id: 'n-news', title: 'ঈদের ছুটি', body: 'ছুটি তিন দিন।', priority: 'normal', standing: 'read');

void main() {
  testWidgets('the list says what is unread and what still wants a signature', (tester) async {
    await tester.pumpWidget(MaterialApp(home: NoticeBoardScreen(api: _FakeNotices([_rule, _news]))));
    await tester.pumpAndSettle();

    expect(find.text('1টা নোটিশ এখনো পড়া হয়নি'), findsOneWidget);
    expect(find.text('নতুন হাজিরার নিয়ম'), findsOneWidget);
    expect(find.textContaining('গুরুত্বপূর্ণ · পড়া হয়নি'), findsOneWidget);
    expect(find.textContaining('সাধারণ · পড়া হয়েছে'), findsOneWidget);
  });

  testWidgets('opening an unread notice marks it read and shows it whole; a read one is not marked again',
      (tester) async {
    final api = _FakeNotices([_rule, _news]);
    await tester.pumpWidget(MaterialApp(home: NoticeBoardScreen(api: api)));
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const ValueKey('notice-n-rule')));
    await tester.pumpAndSettle();
    expect(api.read, ['n-rule']);
    expect(find.text('সবাইকে সকাল ৯টার মধ্যে হাজিরা দিতে হবে।'), findsOneWidget);
    expect(find.textContaining('মালিক'), findsOneWidget);
    expect(find.textContaining('সইয়ের শেষ সময়'), findsOneWidget);

    await tester.pageBack();
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('notice-n-news')));
    await tester.pumpAndSettle();
    expect(api.read, ['n-rule']);
    expect(find.byKey(const ValueKey('notice-sign')), findsNothing, reason: 'no signature wanted');
  });

  testWidgets('signing goes once and the page says it is signed', (tester) async {
    final api = _FakeNotices([_rule]);
    await tester.pumpWidget(MaterialApp(home: NoticeDetailScreen(notice: _rule, api: api)));
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const ValueKey('notice-sign')));
    await tester.pumpAndSettle();

    expect(api.signed, ['n-rule']);
    expect(find.byKey(const ValueKey('notice-signed')), findsOneWidget);
    expect(find.byKey(const ValueKey('notice-sign')), findsNothing);
  });

  testWidgets('a server refusal to sign is shown in its own Bengali words', (tester) async {
    final api = _FakeNotices([_rule])
      ..signFails = DioException(
        requestOptions: RequestOptions(path: '/notices/n-rule/acknowledge'),
        response: Response(
          requestOptions: RequestOptions(path: '/notices/n-rule/acknowledge'),
          statusCode: 422,
          data: {'message': 'এই নোটিশে সই চাওয়া হয়নি।'},
        ),
        type: DioExceptionType.badResponse,
      );
    await tester.pumpWidget(MaterialApp(home: NoticeDetailScreen(notice: _rule, api: api)));
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const ValueKey('notice-sign')));
    await tester.pumpAndSettle();
    expect(find.text('এই নোটিশে সই চাওয়া হয়নি।'), findsOneWidget);
    expect(find.byKey(const ValueKey('notice-signed')), findsNothing);
  });

  testWidgets('nothing posted is an empty state that a pull refreshes', (tester) async {
    await tester.pumpWidget(MaterialApp(home: NoticeBoardScreen(api: _FakeNotices([], unread: 0))));
    await tester.pumpAndSettle();

    expect(find.text('এখন কোনো নোটিশ নেই'), findsOneWidget);
    expect(find.ancestor(of: find.byType(EmptyState), matching: find.byType(RefreshIndicator)), findsOneWidget);
  });

  test('the board is everybody\'s and no phone switch closes it', () {
    const nobody = AuthUser(id: 'u', name: 'x', email: 'x@x', roles: [], permissions: []);
    final items = const MenuRepository().ordered(const [], nobody);
    expect(NavTree.from(items).module('notification')!.screens.map((r) => r.path), ['notices']);
    expect(ModuleGate.allows(const {}, 'notices'), isTrue);
  });
}
