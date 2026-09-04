import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:hive_flutter/hive_flutter.dart';

import 'core/auth/auth_controller.dart';
import 'core/auth/auth_state.dart';
import 'core/router/app_router.dart';
import 'core/sync_engine/background_sync.dart';
import 'core/sync_engine/reference_cache.dart';
import 'core/sync_engine/sync_engine.dart';
import 'core/theme/app_theme.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();

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

  runApp(
    ProviderScope(
      overrides: [
        authStateProvider.overrideWith((ref) => authController),
      ],
      child: const AbosApp(),
    ),
  );
}

class AbosApp extends ConsumerWidget {
  const AbosApp({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final router = ref.watch(goRouterProvider);
    return MaterialApp.router(
      title: 'ABOS',
      debugShowCheckedModeBanner: false,
      theme: AppTheme.light(),
      routerConfig: router,
    );
  }
}
