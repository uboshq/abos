import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:hive_flutter/hive_flutter.dart';
import 'package:home_widget/home_widget.dart';

import 'core/sync_engine/live_sync.dart';
import 'core/sync_engine/sync_status.dart';
import 'core/auth/auth_controller.dart';
import 'core/locale/app_locale.dart';
import 'core/locale/locale_choice.dart';
import 'core/auth/auth_state.dart';
import 'core/crash/crash_reporter.dart';
import 'core/auth/session_watch.dart';
import 'core/privacy/app_lock.dart';
import 'core/privacy/lock_delay.dart';
import 'core/privacy/phone_privacy.dart';
import 'core/launcher_widgets/hub/widget_hub.dart';
import 'core/launcher_widgets/hub/widget_hub_task.dart';
import 'core/launcher_widgets/launcher_widget_refresh.dart';
import 'core/launcher_widgets/widget_sync_observer.dart';
import 'core/push/push_service.dart';
import 'core/router/app_router.dart';
import 'core/router/deep_links.dart';
import 'core/startup/startup.dart';
import 'core/sync_engine/auto_sync.dart';
import 'core/sync_engine/background_sync.dart';
import 'core/sync_engine/reference_cache.dart';
import 'core/sync_engine/sync_engine.dart';
import 'core/theme/app_look.dart';
import 'core/theme/app_theme.dart';
import 'core/theme/glass.dart';
import 'core/theme/theme_choice.dart';
import 'core/permissions/permission_rationale.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();

  // ⭐ ধরা-না-পড়া ভুল অফিসের ভুলের খাতায় — সবার আগে, যাতে শুরুর ভাঙাও ধরা পড়ে (সমন্বয়কের অ্যাপ-অডিট, ৭ অক্টোবর ২০২৬)
  CrashReporter.instance.install();
  // ⛔ পর্দা আড়াল দিয়ে শুরু — `/me` এলে তবে মালিকের সুইচ ([[PhonePrivacy]])
  unawaited(PhonePrivacy.secure(true));

  // ⭐ শুরু দুই ভাগে ([[Startup]], ec, ১৯ অক্টোবর ২০২৬) — প্রথম ছবির আগে কেবল যা প্রথম পর্দার লাগে, ক্রমে:
  //  1. Hive's own path setup, before any box anywhere is opened.
  //  2. SyncEngine.init() / ReferenceCache.init() — see SyncEngine.init's own
  //     doc comment: nothing this does may throw, so a corrupt box degrades
  //     to "sync is off this run" rather than a blank screen on every launch
  //     after. ⓘ The first screens read the reference cache synchronously, so
  //     it cannot wait; one after the other, never together (one encryption key).
  //  3. LockDelay — the app lock on the first screen reads it.
  //  4. AuthController.restoreSession() — needs TokenStorage (no init step
  //     of its own) and SessionRepository (same), so it can run last. No network.
  // ⓘ প্রথম ফ্রেমের পরে: পটভূমির সিঙ্ক (WorkManager — আগের মতোই সবার জন্য, ঢুকে থাকুন বা না), Firebase আর বার্তার টোকেন,
  // হোম-স্ক্রিনের উইজেট, আটকে থাকা ফ্রেমের খোঁজ আর জমা ক্র্যাশ পাঠানো।
  final authController = AuthController();
  await Startup(StartupSteps(
    storage: Hive.initFlutter,
    queue: SyncEngine.instance.init,
    referenceCache: ReferenceCache.instance.init,
    // ⭐ অ্যাপ-তালার সময় — মানুষের বাছাই ([[LockDelay]]); পড়া না গেলে ৫ মিনিট, কখনো ছোড়ে না
    // ⓘ তালার সময় আর আলো/অন্ধকারের বাছাই — দুটোই কেবল ফোনের ভাণ্ডার, প্রথম ফ্রেমের আগে
    lockDelay: () async {
      await LockDelay.instance.load();
      await ThemeChoices.instance.load();
      // ⭐ অ্যাপের রূপ আর কাচের ঝাপসা (0.4.58; মালিক, ২০ নভেম্বর ২০২৬ "ok") — কেবল ফোনের ভাণ্ডার
      await AppLooks.instance.load();
      ThemeChoices.onChanged = (_) => WidgetHub.refresh();
      // ⭐ ভাষার বাছাই (0.4.43) — উইজেট নতুন ভাষায়, আর সার্ভারে রাখা ([[saveLocale]]; পুরনো সার্ভারে চুপ)
      await LocaleChoices.instance.load();
      LocaleChoices.onChanged = (_) async {
        unawaited(WidgetHub.refresh());
        await saveLocale(AppLocale.code);
        // ⓘ লেখা শেষ (পুরনো সার্ভারে চুপ) — হোম এখন মেনু নতুন ভাষায় আনে
        LocaleChoices.savedOnServer.value++;
      };
    },
    restoreSession: () async {
      await authController.restoreSession();
      return authController.isSignedIn;
    },
    backgroundSync: BackgroundSync.initialize,
    // ⭐ অ্যাপ বন্ধ থাকলেও বার্তা (0.4.7) — Firebase না উঠলেও অ্যাপ চলে ([[PushService.init]])।
    push: (signedIn) async {
      await PushService.instance.init();
      if (signedIn) unawaited(PushService.instance.register());
    },
    // A session that was restored rather than signed into never passed
    // through login(), so the home-screen widgets are filled here.
    // ⭐ নতুন উইজেটগুলো আর তাদের ৩০ মিনিটের কাজ — ফিরিয়ে আনা সেশনেও ([WidgetHub], [WidgetHubTask]); অপেক্ষা নয়
    signedInExtras: () async {
      await LauncherWidgetRefresh.refresh();
      unawaited(WidgetHub.refresh());
      unawaited(WidgetHubTask.start());
    },
    // ⭐ আটকে থাকা ফ্রেমের খোঁজ, আর আগের চালুর জমা ক্র্যাশ পাঠানো ([[CrashReporter.flush]])
    afterFrame: () {
      CrashReporter.instance.watchStalls();
      // ⓘ আগের চালুর ANR আর নেটিভ ক্র্যাশ Android-এর খাতা থেকে, তারপর পাঠানো ([[CrashReporter.reportPastExits]])
      unawaited(CrashReporter.instance.reportPastExits().then((_) => CrashReporter.instance.flush()));
    },
  )).boot((_) => ProviderScope(
        overrides: [
          authStateProvider.overrideWith((ref) => authController),
        ],
        child: const AbosApp(),
      ));
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
  late final LivePoll _livePoll;

  /// ⭐ দূর থেকে বের করা — সামনে এলে আর প্রতি ২ মিনিটে সেশন যাচাই ([[SessionWatch]])
  late final SessionWatch _sessionWatch;

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

    _sessionWatch = SessionWatch(
      signedIn: () =>
          ref.read(authStateProvider).status == AuthStatus.signedIn,
    )..start();

    // ⓘ নেট আছে কি না — অফলাইনের পট্টি আর "শেষ সিঙ্ক" পড়ে ([SyncStatus])
    SyncStatus.instance.watchNetwork();

    // ⭐ সামনে খোলা অ্যাপের "নতুন কিছু আছে?" — ১৫–২০ সেকেন্ডে, নতুন কিছু থাকলে তবেই পুরো গোল ([LivePoll])
    _livePoll = LivePoll(
      signedIn: () => ref.read(authStateProvider).status == AuthStatus.signedIn,
      onChanged: () => _autoSync.runNow(),
    )..start();

    _listenForWidgetTaps();

    final signedIn = ref.read(authStateProvider).status == AuthStatus.signedIn;
    if (signedIn) _askNotifications();
    _lock = AppLock(startLocked: signedIn)..signedIn = signedIn;
    WidgetsBinding.instance.addObserver(_lock);

    // ⭐ বার্তায় চাপলে ট্র্যাকিং; নতুন করে ঢুকলে এই ফোনের টোকেন আবার জমা (অন্য কেউ এই ফোনে ঢুকে থাকলে
    // সার্ভার আগের জনের সারি থেকে টোকেন সরায় — [[PushTokenController]])।
    PushService.instance.listenForTaps(_openFromPush);
    // ⭐ অফিসে কিছু লেখা হলে নীরব ডাক — খোলা অ্যাপ তখনই সিঙ্ক করে (রিয়েল-টাইম, মালিক, ১০ অক্টোবর ২০২৬)
    // ⭐ নীরব ডাকে উইজেটও — গোল ব্যর্থ হলেও ([WidgetHub.refresh] চলতি গোলে জুড়ে যায়, দুবার নয়)
    PushService.instance.listenForSync(() =>
        ref.read(authStateProvider).status == AuthStatus.signedIn
            ? _autoSync.runNow().whenComplete(WidgetHub.refresh)
            : Future<void>.value());
    ref.listenManual<AuthState>(authStateProvider, (previous, next) {
      if (previous?.status != AuthStatus.signedIn && next.status == AuthStatus.signedIn) {
        unawaited(PushService.instance.register());
        _askNotifications();
      }
      // ⓘ বেরোলে তালার কিছু নেই; নতুন করে ঢুকলে তালা খোলা (এইমাত্র পাসওয়ার্ড দিলেন)
      _lock.signedIn = next.status == AuthStatus.signedIn;
    });
  }

  /// ⭐ বার্তার অনুমতি — পর্দা ওঠার পরে, বাংলা ব্যাখ্যা দিয়ে, একবারই ([[PushService.askPermissionOnce]])।
  void _askNotifications() {
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (!mounted) return;
      unawaited(PushService.instance.askPermissionOnce(() async {
        final context = ref.read(goRouterProvider).routerDelegate.navigatorKey.currentContext;
        if (context == null || !context.mounted) return false;
        return PermissionRationale.explainBeforeAsking(context, AppPermission.notifications,
            isGranted: (_) async => false);
      }));
    });
  }

  void _openFromPush(String path) => _openOrHold(path);

  /// ⭐ বার্তা বা উইজেটের চাপ — সাইন-ইন না থাকলে (বা সেশন এখনো খোঁজা হচ্ছে, ঠান্ডা শুরু) পথটা [DeepLinks]-এ মনে রেখে
  /// লগইন, তারপর ঠিক সেই পাতা; আগে লগইনের পরে কেবল হোম আসত। ওয়েবের লিংকের একই নিয়ম।
  void _openOrHold(String path) {
    DeepLinks.holdUnlessSignedIn(path, ref.read(authStateProvider).status);
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
    // and the page opens right after signing in.
    _openOrHold(path);
  }

  @override
  void dispose() {
    _widgetSync.stop();
    _autoSync.stop();
    _sessionWatch.stop();
    _livePoll.stop();
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
    // ⭐ মানুষের ভাষা — চাপ দিলেই পুরো অ্যাপ নতুন ভাষায় ([appLocaleProvider]; সুইচ বন্ধ থাকলে সবসময় বাংলা)
    final locale = ref.watch(appLocaleProvider);
    // ⭐ মানুষের আলো/অন্ধকার বাছাই — চাপ দিলেই বদলায়, অ্যাপ আবার খুলতে হয় না ([ThemeChoices])
    // ⭐ রূপ (ABOS / Liquid Glass / Material 3) আর কাচের ঝাপসা — চাপ বা দাগ টানলেই বদলায় ([AppLooks]; 0.4.58)
    return ListenableBuilder(
      listenable: Listenable.merge([ThemeChoices.instance.current, AppLooks.instance.look, AppLooks.instance.blur]),
      builder: (context, _) {
        final choice = ThemeChoices.instance.current.value;
        final look = AppLooks.instance.look.value;
        return MaterialApp.router(
        title: 'ABOS',
        debugShowCheckedModeBanner: false,
        // ⓘ Liquid Glass সবসময় কাচের অন্ধকার পটে, Material 3 সবসময় আলোয় — নমুনার মতো; ABOS আগের মতো মানুষের আলো/অন্ধকার
        theme: switch (look) {
          AppLook.glass => AppTheme.glass(GlassSpec.current),
          AppLook.expressive => AppTheme.expressive(),
          AppLook.abos => AppTheme.light(),
        },
        darkTheme: switch (look) {
          AppLook.glass => AppTheme.glass(GlassSpec.current),
          AppLook.expressive => AppTheme.expressive(),
          AppLook.abos => AppTheme.dark(),
        },
        themeMode: look == AppLook.abos ? choice.mode : ThemeMode.light,
        /*
         * ⭐ বাংলা — তারিখ বাছাই, OK/CANCEL, কপি-পেস্টের মেনু, "ফিরে যান" টিপ আর মাসের নাম (পূর্ণ নিরীক্ষা §৯ ⚠️৫, ১৮ অক্টোবর ২০২৬;
         * মালিক কেবল বাংলা পড়েন)। ⓘ টাকা আর তারিখের নিজের লেখা ধরন [[Money]]-তে, এখানে নয়।
         */
        locale: locale,
        supportedLocales: AppLocale.supported,
        localizationsDelegates: AppLocale.delegates,
        routerConfig: router,
        builder: (context, child) {
          final gate = AppLockGate(
            lock: _lock,
            onSignOut: () => ref.read(authStateProvider.notifier).logout(),
            child: child ?? const SizedBox.shrink(),
          );
          // ⭐ Liquid Glass — সব পাতার পেছনে একই রঙের ছোপ, পাতাগুলো স্বচ্ছ পটে তার উপর ([GlassBackdrop])
          return look == AppLook.glass ? GlassBackdrop(child: gate) : gate;
        },
      );
      },
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
///
/// <p>⭐ নতুন উইজেটের ঠিকানা ([WidgetHub]): `abos://widget/open?path=<পথ>` — অ্যাপের পথ (`/home…`) যেমন আছে, ওয়েবের পথ
/// [DeepLinks.targetFor] দিয়ে; `abos://widget/open?key=<চাবি>` — ঘর। ⛔ অচেনা বা বাইরের পথ সবসময় `/home`, অন্য কোথাও নয়।
String? widgetDestination(Uri? uri) {
  if (uri == null || uri.scheme != 'abos' || uri.host != 'widget') return null;
  if (uri.path == '/open') {
    final path = uri.queryParameters['path'];
    if (path == null || path.isEmpty) return '/home';
    if (path == '/home' || path.startsWith('/home/')) return path;
    final link = Uri.tryParse(path);
    return (link == null ? null : DeepLinks.targetFor(link)) ?? '/home';
  }
  return switch (uri.path) {
    '/approvals' => '/home/approvals',
    '/today' => '/home',
    _ => null,
  };
}
