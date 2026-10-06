import 'package:abos_mobile/core/update/app_version_check.dart';
import 'package:abos_mobile/features/update/app_update_tile.dart';
import 'package:abos_mobile/features/update/update_download_button.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// ⭐ "আরও" পাতার "অ্যাপ হালনাগাদ" — মালিক, ৭ অক্টোবর ২০২৬: "অ্যাপে আপডেট বোতাম দেওয়ার কথা ছিল"।
const _sha = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

AppRelease _release(int code) => AppRelease(
      versionCode: code,
      versionName: '0.4.$code',
      url: 'https://erp.example/app.apk',
      apkSha256: _sha,
      sizeBytes: 1000,
      minimumCode: 25,
    );

Future<void> _pump(WidgetTester tester, Widget tile) async {
  await tester.pumpWidget(MaterialApp(home: Scaffold(body: ListView(children: [tile]))));
  await tester.pumpAndSettle();
}

void main() {
  testWidgets('it shows the running version and asks only when tapped', (tester) async {
    var asked = 0;
    await _pump(
        tester,
        AppUpdateTile(
            current: 28,
            currentName: '0.4.23',
            fetch: () async {
              asked++;
              return _release(28);
            }));

    expect(find.text('চলছে 0.4.23'), findsOneWidget);
    expect(asked, 0, reason: 'পাতা খুললেই সার্ভার নয় — চাপলে');

    await tester.tap(find.byKey(const ValueKey('app-update-check')));
    await tester.pumpAndSettle();
    expect(asked, 1);
    expect(find.byKey(const ValueKey('app-update-latest')), findsOneWidget);
    expect(find.byType(UpdateDownloadButton), findsNothing);
  });

  testWidgets('a newer build offers the same download as the home notice', (tester) async {
    await _pump(tester, AppUpdateTile(current: 28, currentName: '0.4.23', fetch: () async => _release(29)));

    await tester.tap(find.byKey(const ValueKey('app-update-check')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('app-update-newer')), findsOneWidget);
    expect(find.textContaining('0.4.29'), findsOneWidget);
    expect(find.byType(UpdateDownloadButton), findsOneWidget);
    expect(find.byKey(const ValueKey('app-update-latest')), findsNothing);
  });

  testWidgets('no network says so plainly, and a second tap asks again', (tester) async {
    var fail = true;
    await _pump(
        tester,
        AppUpdateTile(
            current: 28,
            currentName: '0.4.23',
            fetch: () async {
              if (fail) throw Exception('offline');
              return _release(28);
            }));

    await tester.tap(find.byKey(const ValueKey('app-update-check')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('app-update-offline')), findsOneWidget);
    expect(find.byKey(const ValueKey('app-update-latest')), findsNothing,
        reason: '⛔ নেট না থাকলেও "সর্বশেষ" বলল');

    fail = false;
    await tester.tap(find.byKey(const ValueKey('app-update-check')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('app-update-offline')), findsNothing);
    expect(find.byKey(const ValueKey('app-update-latest')), findsOneWidget);
  });
}
