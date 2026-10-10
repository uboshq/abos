import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:hive_flutter/hive_flutter.dart';
import 'package:home_widget/home_widget.dart';

import 'core/auth/auth_controller.dart';
import 'core/auth/auth_state.dart';
import 'core/crash/crash_reporter.dart';
import 'core/privacy/app_lock.dart';
import 'core/privacy/phone_privacy.dart';
import 'core/launcher_widgets/launcher_widget_refresh.dart';
import 'core/launcher_widgets/widget_sync_observer.dart';
import 'core/push/push_service.dart';
import 'core/router/app_router.dart';
import 'core/sync_engine/auto_sync.dart';
import 'core/sync_engine/background_sync.dart';
import 'core/sync_engine/reference_cache.dart';
import 'core/sync_engine/sync_engine.dart';
import 'core/theme/app_theme.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();

  // ⭐ ধরা-না-পড়া ভুল অফিসের ভুলের খাতায় — সবার আগে, যাতে শুরুর ভাঙাও ধরা পড়ে (সমন্বয়কের অ্যাপ-অডিট, ৭ অক্টোবর ২০২৬)
  CrashReporter.instance.install();
  // ⛔ পর্দা আড়াল দিয়ে শুরু — `/me` এলে তবে মালিকের সুইচ ([[PhonePrivacy]])
  unawaited(PhonePrivacy.secure(true));

  // Order matters, and each step here is a hard dependency of the next:
  //  1. Hive's own path setup, before any box anywhere is opened.
  //  2. SyncEngine.init() / ReferenceCache.init() — see SyncEngine.init's own
  //     doc comment: nothing this does may throw, so a corrupt box degrades
  //     to "sync is off this run" rather than a blank screen on every launch
  //     after.
  //  3. BackgroundSync — registered unconditionally, signed in or not, same
  //     reasoning as its own doc comment.
  //  4. AuthController.restoreSession() — needs TokenStorage (no init step
  //     of its own) and SessionRepository (same), so it can run last.
  await Hive.initFlutter();
  await SyncEngine.instance.init();
  await ReferenceCache.instance.init();
  await BackgroundSync.initialize();

  final authController = AuthController();
  await authController.restoreSession();

  // ⭐ অ্যাপ বন্ধ থাকলেও বার্তা (0.4.7) — Firebase না উঠলেও অ্যাপ চলে ([[PushService.init]])।
  await PushService.instance.init();
  if (authController.isSignedIn) {
    unawaited(PushService.instance.register());
  }

  // A session that was restored rather than signed into never passed
  // through login(), so the home-screen widgets are filled here. Not
  // awaited: the app must not wait on a launcher tile to draw itself.
  if (authController.isSignedIn) {
    unawaited(LauncherWidgetRefresh.refresh());
  }

  runApp(
    ProviderScope(
      overrides: [
        authStateProvider.overrideWith((ref) => authController),
      ],
      child: const AbosApp(),
    ),
  );
}

class AbosApp extends ConsumerStatefulWidget {
  const AbosApp({super.key});

  @override
  ConsumerState<AbosApp> createState() => _AbosAppState();
}

class _AbosAppState extends ConsumerState<AbosApp> {
  late final WidgetSyncObserver _widgetSync;

  /// ⭐ নিজে থেকে সিঙ্ক — অ্যাপ খোলা থাকলে, সামনে ফিরলে আর প্রতি দশ মিনিটে (মালিক, ১০ অক্টোবর ২০২৬; [[AutoSync]])
  late final AutoSync _autoSync;

  /// ⭐ অ্যাপ-তালা — সংরক্ষিত সেশনে খুললে আর অনেকক্ষণ পরে ফিরলে ([[AppLock]]); এইমাত্র পাসওয়ার্ডে ঢুকলে নয়
  late final AppLock _lock;
  StreamSubscription<Uri?>? _widgetTaps;

  @override
  void initState() {
    super.initState();

    // Fills the widgets at the moment somebody leaves for the home screen,
    // which is the moment before they read them.
    _widgetSync = WidgetSyncObserver(
      signedIn: () =>
          ref.read(authStateProvider).status == AuthStatus.signedIn,
    )..start();

    _autoSync = AutoSync(
      signedIn: () =>
          ref.read(authStateProvider).status == AuthStatus.signedIn,
    )..start();

    _listenForWidgetTaps();

    final signedIn = ref.read(authStateProvider).status == AuthStatus.signedIn;
    _lock = AppLock(startLocked: signedIn)..signedIn = signedIn;
    WidgetsBinding.instance.addObserver(_lock);

    // ⭐ বার্তায় চাপলে ট্র্যাকিং; নতুন করে ঢুকলে এই ফোনের টোকেন আবার জমা (অন্য কেউ এই ফোনে ঢুকে থাকলে
    // সার্ভার আগের জনের সারি থেকে টোকেন সরায় — [[PushTokenController]])।
    PushService.instance.listenForTaps(_openFromPush);
    ref.listenManual<AuthState>(authStateProvider, (previous, next) {
      if (previous?.status != AuthStatus.signedIn && next.status == AuthStatus.signedIn) {
        unawaited(PushService.instance.register());
      }
      // ⓘ বেরোলে তালার কিছু নেই; নতুন করে ঢুকলে তালা খোলা (এইমাত্র পাসওয়ার্ড দিলেন)
      _lock.signedIn = next.status == AuthStatus.signedIn;
    });
  }

  void _openFromPush(String path) {
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!mounted) return;
      ref.read(goRouterProvider).go(path);
    });
  }

  /// A tap on a home-screen widget opens the page behind it: the approvals
  /// widget opens the inbox, the figures widget the day's page.
  ///
  /// <p>Everything here is wrapped. A launcher that does not speak this
  /// plugin's channel must cost the app nothing but the shortcut.
  void _listenForWidgetTaps() {
    try {
      HomeWidget.initiallyLaunchedFromHomeWidget()
          .then(_openFromWidget)
          .catchError((Object _) {});
      _widgetTaps = HomeWidget.widgetClicked.listen(
        _openFromWidget,
        onError: (Object _) {},
      );
    } catch (_) {
      // No widgets on this platform.
    }
  }

  void _openFromWidget(Uri? uri) {
    final path = widgetDestination(uri);
    if (path == null) return;
    // Signed out, the router's own redirect sends this to the login screen,
    // which is where somebody tapping a wiped widget ought to land.
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!mounted) return;
      ref.read(goRouterProvider).go(path);
    });
  }

  @override
  void dispose() {
    _widgetSync.stop();
    _autoSync.stop();
    _widgetTaps?.cancel();
    WidgetsBinding.instance.removeObserver(_lock);
    _lock.dispose();
    _watched?.routerDelegate.removeListener(_noteScreen);
    super.dispose();
  }

  GoRouter? _watched;

  /// ক্র্যাশের খবরে কোন পর্দা ছিল — রাউটার বদলালেই ([[CrashReporter.screen]])
  void _noteScreen() {
    final router = _watched;
    if (router != null) {
      CrashReporter.instance.screen = router.routerDelegate.currentConfiguration.uri.path;
    }
  }

  @override
  Widget build(BuildContext context) {
    final GoRouter router = ref.watch(goRouterProvider);
    if (!identical(router, _watched)) {
      _watched?.routerDelegate.removeListener(_noteScreen);
      _watched = router..routerDelegate.addListener(_noteScreen);
    }
    return MaterialApp.router(
      title: 'ABOS',
      debugShowCheckedModeBanner: false,
      theme: AppTheme.light(),
      routerConfig: router,
      builder: (context, child) => AppLockGate(
        lock: _lock,
        onSignOut: () => ref.read(authStateProvider.notifier).logout(),
        child: child ?? const SizedBox.shrink(),
      ),
    );
  }
}

/// Where a widget's address leads inside the app, or null for an address
/// this build does not know.
///
/// <p>The addresses are the ones the two Kotlin providers put on their
/// click intents: `abos://widget/approvals` and `abos://widget/today`.
/// Anything else opens the app where it already was, which is what a tap on
/// an unknown thing should do.
String? widgetDestination(Uri? uri) {
  if (uri == null || uri.scheme != 'abos' || uri.host != 'widget') return null;
  return switch (uri.path) {
    '/approvals' => '/home/approvals',
    '/today' => '/home',
    _ => null,
  };
}
