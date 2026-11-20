import 'dart:io';

import 'package:abos_mobile/core/auth/auth_user.dart';
import 'package:abos_mobile/core/config/app_channel.dart';
import 'package:abos_mobile/core/router/profile_routes.dart';
import 'package:abos_mobile/core/sms_gateway/sms_gateway_channel.dart';
import 'package:abos_mobile/core/update/app_version_check.dart';
import 'package:abos_mobile/core/update/update_install_flow.dart';
import 'package:abos_mobile/features/profile/profile_screen.dart';
import 'package:abos_mobile/features/update/app_update_tile.dart';
import 'package:abos_mobile/features/update/store_update_button.dart';
import 'package:abos_mobile/features/update/update_download_button.dart';
import 'package:abos_mobile/features/update/update_gate.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';

import 'profile/profile_fakes.dart';
import 'profile/profile_host.dart';

/// ⭐ দুই বিলি-পথ (মালিকের সিদ্ধান্ত, ২০ নভেম্বর ২০২৬) — lib/core/config/app_channel.dart।
///
/// <p>দাবি: Google Play-র বিল্ডে (`ABOS_CHANNEL=play`) নিজে নামানো-বসানোর কোনো বোতাম বা পথ নেই — নতুন সংস্করণ মানে Play Store-এর
/// পাতা, ন্যূনতম-সংস্করণের দেয়ালেও; অফিসের SMS ফোনের সারি, পথ আর সেবা-চালু নেই। side বিল্ডে (ডিফল্ট) সব আগের মতো।
const _sha = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

const _release = AppRelease(
  versionCode: 29,
  versionName: '0.4.29',
  url: 'https://erp.example/app.apk',
  apkSha256: _sha,
  sizeBytes: 1000,
  minimumCode: 25,
);

Widget _gate(UpdateStatus status) => MaterialApp(
      // ⓘ key-তে রায় — একই পরীক্ষায় নোটিশের পরে দেয়াল আঁকলে নতুন State, নতুন যাচাই
      home: Scaffold(
          body: UpdateGate(
              key: ValueKey(status.verdict), check: () async => status, child: const Text('অ্যাপের ভেতর'))),
    );

Future<void> _askTile(WidgetTester tester) async {
  await tester.pumpWidget(MaterialApp(
      home: Scaffold(
          body: ListView(children: [AppUpdateTile(current: 28, currentName: '0.4.28', fetch: () async => _release)]))));
  await tester.pumpAndSettle();
  await tester.tap(find.byKey(const ValueKey('app-update-check')));
  await tester.pumpAndSettle();
}

const _smsKeeper = AuthUser(
    id: 'u1', name: 'আল-আমিন শুভ', email: 'alamin@adi.test', roles: ['owner'], permissions: ['notification.sms_gateway']);

void main() {
  tearDown(() => AppChannel.debugOverride = null);

  test('the build default is side, and the switch reads in one place', () {
    expect(AppChannel.name, 'side', reason: 'ABOS_CHANNEL না দিলে আজকের APK');
    expect(AppChannel.isPlay, isFalse);
    AppChannel.debugOverride = 'play';
    expect(AppChannel.isPlay, isTrue);
    expect(AppChannel.playStoreUrl, 'https://play.google.com/store/apps/details?id=univer.abos');

    // ⛔ সুইচ আর কোথাও পড়া নয় — একটাই জায়গা
    final readers = Directory('lib')
        .listSync(recursive: true)
        .whereType<File>()
        .where((f) => f.path.endsWith('.dart') && f.readAsStringSync().contains("'ABOS_CHANNEL'"))
        .map((f) => f.path.replaceAll('\\', '/'))
        .toList();
    expect(readers, ['lib/core/config/app_channel.dart']);
  });

  group('side (today\'s sideload APK) — unchanged', () {
    testWidgets('the More tile offers the in-app download, no store link', (tester) async {
      await _askTile(tester);
      expect(find.byType(UpdateDownloadButton), findsOneWidget);
      expect(find.byType(StoreUpdateButton), findsNothing);
    });

    testWidgets('the home notice and the wall download in the app', (tester) async {
      await tester.pumpWidget(_gate(const UpdateStatus(verdict: UpdateVerdict.available, release: _release)));
      await tester.pumpAndSettle();
      expect(find.byType(UpdateDownloadButton), findsOneWidget);
      expect(find.byType(StoreUpdateButton), findsNothing);

      await tester.pumpWidget(_gate(const UpdateStatus(verdict: UpdateVerdict.blocked, release: _release)));
      await tester.pumpAndSettle();
      expect(find.text('নতুন সংস্করণ নামান'), findsOneWidget);
      expect(find.byType(StoreUpdateButton), findsNothing);
    });

    testWidgets('the SMS phone row shows for its key holder', (tester) async {
      expect(ProfileRoutes.smsGatewayInThisBuild, isTrue);
      final c = profileContainer(auth: SignedIn(_smsKeeper));
      await tester.pumpWidget(profileHost(c, ProfileScreen(api: FakeProfileApi())));
      await tester.pumpAndSettle();
      expect(find.byKey(const ValueKey('profile-sms-gateway'), skipOffstage: false), findsOneWidget);
    });
  });

  group('play (Google Play) — no self-update, no SMS phone', () {
    setUp(() => AppChannel.debugOverride = 'play');

    testWidgets('the More tile offers the Play Store, never the download', (tester) async {
      await _askTile(tester);
      expect(find.byKey(const ValueKey('app-update-newer')), findsOneWidget, reason: 'নতুন আছে, সেটা বলা হয়');
      expect(find.byType(UpdateDownloadButton), findsNothing);
      expect(find.byType(StoreUpdateButton), findsOneWidget);
      expect(find.text('Play Store-এ আপডেট করুন'), findsOneWidget);
      expect(find.text('এখনই আপডেট করুন'), findsNothing, reason: '"এখনই" বললে মনে হত অ্যাপ নিজে বসাবে');
    });

    testWidgets('the home notice offers the store', (tester) async {
      await tester.pumpWidget(_gate(const UpdateStatus(verdict: UpdateVerdict.available, release: _release)));
      await tester.pumpAndSettle();
      expect(find.text('অ্যাপের ভেতর'), findsOneWidget);
      expect(find.byType(UpdateDownloadButton), findsNothing);
      expect(find.byKey(const ValueKey('update-open-store')), findsOneWidget);
    });

    testWidgets('the minimum-version wall sends to the store instead of downloading', (tester) async {
      await tester.pumpWidget(_gate(const UpdateStatus(verdict: UpdateVerdict.blocked, release: _release)));
      await tester.pumpAndSettle();
      expect(find.text('এই সংস্করণটি আর ব্যবহার করা যাবে না'), findsOneWidget);
      expect(find.text('অ্যাপের ভেতর'), findsNothing, reason: 'দেয়াল দেয়ালই');
      expect(find.byType(UpdateDownloadButton), findsNothing);
      expect(find.text('নতুন সংস্করণ নামান'), findsNothing);
      expect(find.byKey(const ValueKey('update-open-store')), findsOneWidget);
    });

    testWidgets('a release without a checksum still gets the store link', (tester) async {
      // ⓘ Play নিজে যাচাই করে — sha না থাকলেও "বসানো যাচ্ছে না" নয়
      await tester.pumpWidget(_gate(
          const UpdateStatus(verdict: UpdateVerdict.blocked, release: AppRelease(versionCode: 29, minimumCode: 25))));
      await tester.pumpAndSettle();
      expect(find.byKey(const ValueKey('update-open-store')), findsOneWidget);
    });

    testWidgets('the store button opens univer.abos on Google Play, and says so when it cannot', (tester) async {
      final opened = <Uri>[];
      var ok = true;
      await tester.pumpWidget(MaterialApp(
          home: Scaffold(body: StoreUpdateButton(open: (u) async {
        opened.add(u);
        return ok;
      }))));
      await tester.tap(find.byKey(const ValueKey('update-open-store')));
      await tester.pumpAndSettle();
      expect(opened, [Uri.parse('https://play.google.com/store/apps/details?id=univer.abos')]);
      expect(find.byKey(const ValueKey('update-store-failed')), findsNothing);

      ok = false;
      await tester.tap(find.byKey(const ValueKey('update-open-store')));
      await tester.pumpAndSettle();
      expect(find.byKey(const ValueKey('update-store-failed')), findsOneWidget);
    });

    test('the install flow refuses before touching anything', () async {
      var touched = 0;
      Future<T> no<T>() async {
        touched++;
        throw StateError('play বিল্ডে নামানো/বসানো ডাকা হলো');
      }

      final result = await UpdateInstallFlow.run(
        _release,
        actions: UpdateActions(
          canInstall: () => no(),
          cached: () => no(),
          freeBytes: () => no(),
          download: (_, __) => no(),
          lengthOf: (_) => no(),
          sha256Of: (_) => no(),
          install: (_) => no(),
          delete: (_) => no(),
        ),
      );
      expect(result.outcome, UpdateOutcome.notInstallable);
      expect(touched, 0);
    });

    testWidgets('the SMS phone row is absent even for its key holder', (tester) async {
      expect(ProfileRoutes.smsGatewayInThisBuild, isFalse);
      final c = profileContainer(auth: SignedIn(_smsKeeper));
      await tester.pumpWidget(profileHost(c, ProfileScreen(api: FakeProfileApi())));
      await tester.pumpAndSettle();
      expect(find.byKey(const ValueKey('profile-sms-gateway'), skipOffstage: false), findsNothing);
    });

    test('the SMS service is never started or asked for SEND_SMS', () async {
      TestWidgetsFlutterBinding.ensureInitialized();
      const channel = MethodChannel('test/sms_gateway_play');
      final calls = <String>[];
      TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger.setMockMethodCallHandler(channel, (call) async {
        calls.add(call.method);
        return true;
      });
      addTearDown(() => TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger.setMockMethodCallHandler(channel, null));

      const gateway = SmsGatewayChannel(channel);
      await gateway.start(key: 'k');
      expect(await gateway.requestPermission(), isFalse);
      expect(calls, isEmpty);

      AppChannel.debugOverride = null;
      await gateway.start(key: 'k');
      expect(calls, ['start'], reason: 'side-এ আগের মতোই চালু হয়');
    });

    test('the router has no SMS phone route in play', () {
      final router = File('lib/core/router/app_router.dart').readAsStringSync();
      expect(
          RegExp(r'if \(ProfileRoutes\.smsGatewayInThisBuild\)\s*_framed\(\s*path: ProfileRoutes\.smsGateway').hasMatch(router),
          isTrue);
    });
  });
}
