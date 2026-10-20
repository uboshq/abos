import 'package:abos_mobile/core/orders/paper_scan_api.dart';
import 'package:abos_mobile/core/permissions/permission_rationale.dart';
import 'package:abos_mobile/core/push/push_service.dart';
import 'package:abos_mobile/features/scan/paper_scan_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:mobile_scanner/mobile_scanner.dart';

/// ⭐ প্রতিটা চলার-সময়ের অনুমতির আগে বাংলা ব্যাখ্যা — Android-এর প্রশ্ন তার পরে (অ্যাপ রিলিজ নিরীক্ষা, ২০ অক্টোবর ২০২৬)।
final _bangla = RegExp(r'[ঀ-৿]');

/// একটা বোতাম: ব্যাখ্যা, তারপর (হ্যাঁ বললে) "সিস্টেমের প্রশ্ন" — [log]-এ ক্রমটা লেখা থাকে।
Widget _host(List<String> log, AppPermission permission, {bool? granted = false}) {
  return MaterialApp(
    home: Scaffold(
      body: Builder(
        builder: (context) => TextButton(
          onPressed: () async {
            final go = await PermissionRationale.explainBeforeAsking(context, permission,
                isGranted: (_) async {
              log.add('checked');
              return granted;
            });
            if (go) log.add('system-request');
          },
          child: const Text('go'),
        ),
      ),
    ),
  );
}

void main() {
  for (final permission in AppPermission.values) {
    testWidgets('${permission.name}: the Bengali rationale shows before the system request', (tester) async {
      final log = <String>[];
      await tester.pumpWidget(_host(log, permission));
      await tester.tap(find.text('go'));
      await tester.pumpAndSettle();

      final dialog = find.byKey(Key('permission-rationale-${permission.name}'));
      expect(dialog, findsOneWidget, reason: 'সিস্টেমের প্রশ্নের আগে ব্যাখ্যা আসেনি');
      expect(log, ['checked'], reason: '⛔ ব্যাখ্যা পড়ার আগেই সিস্টেমের প্রশ্ন খুলল');
      final body = PermissionRationale.bodyOf(permission);
      expect(find.text(body), findsOneWidget);
      expect(find.text(PermissionRationale.titleOf(permission)), findsOneWidget);
      expect(_bangla.allMatches(body).length, greaterThan(body.length ~/ 2),
          reason: 'ব্যাখ্যাটা বাংলায় নয়');

      await tester.tap(find.byKey(const Key('permission-rationale-ok')));
      await tester.pumpAndSettle();
      expect(log, ['checked', 'system-request']);
    });

    testWidgets('${permission.name}: "এখন না" never opens the system request', (tester) async {
      final log = <String>[];
      await tester.pumpWidget(_host(log, permission));
      await tester.tap(find.text('go'));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const Key('permission-rationale-no')));
      await tester.pumpAndSettle();
      expect(log, ['checked']);
    });
  }

  testWidgets('an already-granted permission asks nothing and goes on', (tester) async {
    final log = <String>[];
    await tester.pumpWidget(_host(log, AppPermission.camera, granted: true));
    await tester.tap(find.text('go'));
    await tester.pumpAndSettle();
    expect(find.byType(AlertDialog), findsNothing);
    expect(log, ['checked', 'system-request']);
  });

  testWidgets('the QR scan screen explains before the camera starts', (tester) async {
    await tester.pumpWidget(MaterialApp(
        home: PaperScanScreen(api: const ServerPaperScanApi(), isGranted: (_) async => false)));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('permission-rationale-camera')), findsOneWidget);
    expect(find.byType(MobileScanner), findsNothing,
        reason: '⛔ ব্যাখ্যার আগেই ক্যামেরা চালু হল — Android-এর প্রশ্ন ব্যাখ্যা ছাড়াই আসত');

    await tester.tap(find.byKey(const Key('permission-rationale-no')));
    await tester.pumpAndSettle();
    expect(find.byType(MobileScanner), findsNothing);
    expect(find.byKey(const Key('scan-ask-camera')), findsOneWidget);

    await tester.tap(find.byKey(const Key('scan-ask-camera')));
    await tester.pumpAndSettle();
    expect(find.byKey(const Key('permission-rationale-camera')), findsOneWidget);
  });

  group('notifications are asked once, after the explanation', () {
    Future<List<String>> run({bool? granted = false, bool asked = false, bool yes = true}) async {
      final log = <String>[];
      await askNotificationsOnce(
        granted: () async => granted,
        alreadyAsked: () async => asked,
        markAsked: () async => log.add('marked'),
        explain: () async {
          log.add('explained');
          return yes;
        },
        request: () async => log.add('system-request'),
      );
      return log;
    }

    test('explanation, then the request', () async {
      expect(await run(), ['marked', 'explained', 'system-request']);
    });
    test('"এখন না" sends no request', () async {
      expect(await run(yes: false), ['marked', 'explained']);
    });
    test('asked before: nothing again', () async {
      expect(await run(asked: true), isEmpty);
    });
    test('granted already: nothing', () async {
      expect(await run(granted: true), isEmpty);
    });
  });
}
