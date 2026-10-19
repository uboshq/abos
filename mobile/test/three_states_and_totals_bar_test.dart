import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:abos_mobile/core/theme/app_colors.dart';
import 'package:abos_mobile/core/widgets/empty_state.dart';
import 'package:abos_mobile/core/widgets/paged_list.dart';
import 'package:abos_mobile/core/widgets/row_icon.dart';
import 'package:abos_mobile/core/widgets/state_views.dart';
import 'package:abos_mobile/core/widgets/totals_bar.dart';

/// ⭐ তিন অবস্থা, সর্বমোটের পট্টি আর সারির আইকন — দলের সাধারণ widget (মোবাইলের নকশা ৩; মালিকের "হ্যাঁ", ১৯ অক্টোবর ২০২৬)।
void main() {
  Widget host(Widget child, {Brightness brightness = Brightness.light}) => MaterialApp(
        theme: ThemeData(colorScheme: ColorScheme.fromSeed(seedColor: AppColors.primary, brightness: brightness)),
        home: Scaffold(body: child),
      );

  testWidgets('a first load shows skeleton rows, not a bare spinner, until the rows come', (tester) async {
    final gate = Completer<(List<String>, int?)>();
    await tester.pumpWidget(host(PagedList<String>(
      load: (_) => gate.future,
      item: (_, r) => ListTile(title: Text(r)),
      emptyTitle: 'কিছু নেই',
      failed: 'আনা গেল না',
    )));
    await tester.pump();
    expect(find.byKey(const ValueKey('loading-skeleton')), findsOneWidget);
    expect(find.bySemanticsLabel('লোড হচ্ছে'), findsOneWidget);
    expect(find.byType(LinearProgressIndicator), findsNothing);

    gate.complete((['RV-2610-0142'], null));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('loading-skeleton')), findsNothing);
    expect(find.text('RV-2610-0142'), findsOneWidget);
  });

  testWidgets('a failed load says so in Bengali and its 48dp retry button loads again', (tester) async {
    var calls = 0;
    await tester.pumpWidget(host(PagedList<String>(
      load: (_) async {
        calls++;
        if (calls == 1) throw StateError('boom');
        return (['PV-2610-0057'], null);
      },
      item: (_, r) => ListTile(title: Text(r)),
      emptyTitle: 'কিছু নেই',
      failed: 'আনা গেল না',
    )));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('books-error')), findsOneWidget, reason: 'পুরনো টেস্টের চাবি থাকে');
    expect(find.text('আনা গেল না'), findsOneWidget);
    final retry = find.byKey(const ValueKey('error-retry'));
    expect(tester.getSize(retry).height, greaterThanOrEqualTo(48));

    await tester.tap(retry);
    await tester.pumpAndSettle();
    expect(calls, 2);
    expect(find.byType(ErrorState), findsNothing);
    expect(find.text('PV-2610-0057'), findsOneWidget);
  });

  testWidgets('an empty list can offer a way forward', (tester) async {
    var tapped = false;
    await tester.pumpWidget(host(EmptyState(
      icon: Icons.inbox_outlined,
      title: 'কোনো অর্ডার নেই',
      action: FilledButton(onPressed: () => tapped = true, child: const Text('নতুন অর্ডার')),
    )));
    await tester.tap(find.text('নতুন অর্ডার'));
    expect(tapped, isTrue);
  });

  testWidgets('the states take their colours from the theme, so dark mode reads light text', (tester) async {
    await tester.pumpWidget(host(const Column(children: [
      EmptyState(icon: Icons.inbox_outlined, title: 'কিছু নেই'),
      ErrorState(message: 'আনা গেল না'),
    ]), brightness: Brightness.dark));
    final dark = ColorScheme.fromSeed(seedColor: AppColors.primary, brightness: Brightness.dark);
    expect(tester.widget<Text>(find.text('কিছু নেই')).style!.color, dark.onSurface);
    expect(tester.widget<Text>(find.text('আনা গেল না')).style!.color, dark.onSurface);
  });

  testWidgets('the totals bar keeps this page and the grand total apart, money in English digits', (tester) async {
    await tester.pumpWidget(host(const TotalsBar(pageCount: 5, pageSum: 402450, allCount: 37, allSum: 1842650)));
    expect(find.text('এই পাতা · 5টি'), findsOneWidget);
    expect(find.text('৳4,02,450.00'), findsOneWidget);
    expect(find.text('সর্বমোট · 37টি'), findsOneWidget);
    expect(find.text('৳18,42,650.00'), findsOneWidget);
    final grand = tester.widget<Text>(find.text('৳18,42,650.00')).style!;
    final page = tester.widget<Text>(find.text('৳4,02,450.00')).style!;
    expect(grand.fontSize! > page.fontSize!, isTrue, reason: 'সর্বমোট বড় করে');
  });

  test('a row icon reads at 3:1 or more on its own tint, by module or by tone', () {
    for (final code in [...AppColors.module.keys, 'no-such-module']) {
      final (bg, ink) = RowIcon(Icons.receipt_long, module: code).colours;
      expect(AppColors.contrast(ink, bg), greaterThanOrEqualTo(3.0), reason: code);
    }
    for (final tone in [AppColors.success, AppColors.warning, const Color(0xFFFFEB3B), const Color(0xFF10B981)]) {
      final (bg, ink) = RowIcon(Icons.call_received, tone: tone).colours;
      expect(AppColors.contrast(ink, bg), greaterThanOrEqualTo(3.0), reason: '$tone');
    }
  });
}
