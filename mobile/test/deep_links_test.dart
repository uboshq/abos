import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';

import 'package:abos_mobile/core/auth/auth_state.dart';
import 'package:abos_mobile/core/privacy/app_lock.dart';
import 'package:abos_mobile/core/router/deep_links.dart';
import 'package:abos_mobile/core/web_inside/web_inside.dart';

/// ⭐ নোটিশ বা SMS-এর erp.adi.com.bd লিংক অ্যাপের ঠিক সেই পাতায় (Android App Links; পরিকল্পনা ৪, অংশ ৬; fe, ২০ অক্টোবর ২০২৬)।
void main() {
  String webPathOf(String target) => WebPaths.webPathOf(target.split('/').last)!;

  setUp(DeepLinks.take);

  test('a business link opens the same page through the menu and notice door, query and all', () {
    final target = DeepLinks.targetFor(Uri.parse('https://erp.adi.com.bd/sales/invoices/12?tab=lines'))!;
    expect(target, startsWith('/home/web/'));
    expect(webPathOf(target), '/sales/invoices/12?tab=lines');
    expect(webPathOf(DeepLinks.targetFor(Uri.parse('/accounts/vouchers/7'))!), '/accounts/vouchers/7', reason: 'পথ একা এলেও');
  });

  test('links that are not ours, or that belong in the browser, are left alone', () {
    for (final link in [
      'https://evil.example/sales/invoices/12',
      'http://erp.adi.com.bd/sales/invoices/12',
      'https://erp.adi.com.bd/verify/INV-2610-0388',
      'https://erp.adi.com.bd/portal/orders',
      'https://erp.adi.com.bd/login',
      'https://erp.adi.com.bd/reset-password/abc',
      'https://erp.adi.com.bd/salesman',
      '/home/approvals',
      '/',
    ]) {
      expect(DeepLinks.targetFor(Uri.parse(link)), isNull, reason: link);
    }
  });

  test('a link never skips sign-in: it waits behind the login and opens once, right after it', () {
    final uri = Uri.parse('https://erp.adi.com.bd/approvals/5');
    final target = DeepLinks.targetFor(uri)!;

    expect(DeepLinks.route(uri, AuthStatus.unknown), '/', reason: 'সেশন খোঁজা চলছে — স্প্ল্যাশে অপেক্ষা');
    expect(DeepLinks.route(uri, AuthStatus.signedOut), '/login');
    expect(DeepLinks.take(), target, reason: 'লগইনের পরে সেই পাতা');
    expect(DeepLinks.take(), isNull, reason: 'একবারই');

    expect(DeepLinks.route(uri, AuthStatus.signedIn), target);
    expect(DeepLinks.take(), isNull, reason: 'সাইন-ইন থাকলে কিছু জমে না');
  });

  test('the manifest claims exactly these prefixes on the live host, verified', () {
    final manifest = File('android/app/src/main/AndroidManifest.xml').readAsStringSync();
    expect(manifest, contains('<intent-filter android:autoVerify="true">'));
    expect(manifest, contains('<data android:host="erp.adi.com.bd"/>'));
    expect(manifest, contains('<data android:scheme="https"/>'));
    final claimed = RegExp(r'android:pathPrefix="([^"]+)"').allMatches(manifest).map((m) => m.group(1)).toSet();
    expect(claimed, DeepLinks.prefixes.toSet());
  });

  test('the router asks DeepLinks first and hands the held link over after sign-in', () {
    final router = File('lib/core/router/app_router.dart').readAsStringSync();
    expect(router, contains('DeepLinks.route(state.uri, auth.status)'));
    expect(router, contains("return DeepLinks.take() ?? '/home';"));
  });

  // ⓘ a6-এর দাবি (তাঁর থামানো ডিপ-লিংকের কাজ থেকে, ২০ অক্টোবর ২০২৬): লিংকে খোলা পর্দাও তালার নিচে
  testWidgets('a screen opened by a link sits under the app lock like any other', (tester) async {
    final lock = AppLock(startLocked: true, authenticate: () async => false, deviceSupported: () async => true);
    final router = GoRouter(
      initialLocation: '/home',
      redirect: (context, state) => DeepLinks.route(state.uri, AuthStatus.signedIn),
      routes: [
        GoRoute(path: '/home', builder: (_, __) => const Text('HOME'), routes: [
          GoRoute(path: 'web/:code', builder: (_, __) => const Text('PAPER')),
        ]),
      ],
    );
    await tester.pumpWidget(MaterialApp.router(
      routerConfig: router,
      builder: (context, child) => AppLockGate(lock: lock, child: child ?? const SizedBox()),
    ));
    await tester.pumpAndSettle();

    router.go('/accounts/vouchers/12');
    await tester.pumpAndSettle();

    expect(find.text('PAPER'), findsOneWidget, reason: 'লিংকের কাগজের পর্দা');
    expect(find.byKey(const ValueKey('app-lock')), findsOneWidget, reason: '⛔ লিংকে খোলা পর্দায় তালা নেই');
    // ⓘ অ্যাপের আসল গঠনেও তালা প্রতিটা পর্দার উপরে — main.dart-এর builder
    expect(File('lib/main.dart').readAsStringSync(), contains('builder: (context, child) => AppLockGate('));
  });
}
