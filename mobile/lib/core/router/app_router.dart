import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../features/auth/login_screen.dart';
import '../../features/customers/customer_list_screen.dart';
import '../../features/home/home_shell.dart';
import '../../features/orders/new_order_screen.dart';
import '../../features/orders/order_prefill.dart';
import '../../features/orders/order_list_screen.dart';
import '../../features/products/product_list_screen.dart';
import '../../features/splash/splash_screen.dart';
import '../../features/stock/stock_list_screen.dart';
import '../../features/sync/sync_status_screen.dart';
import '../auth/auth_state.dart';

/// Turns `ref.listen(authStateProvider, ...)` into the [Listenable] go_router
/// wants for [GoRouter.refreshListenable] — go_router has no native awareness
/// of Riverpod, so a redirect that reads [authStateProvider] still needs
/// something to tell the router *when* to re-evaluate it.
class _AuthRefreshNotifier extends ChangeNotifier {
  _AuthRefreshNotifier(Ref ref) {
    ref.listen<AuthState>(authStateProvider, (previous, next) {
      if (previous?.status != next.status) notifyListeners();
    });
  }
}

final goRouterProvider = Provider<GoRouter>((ref) {
  final refresh = _AuthRefreshNotifier(ref);
  ref.onDispose(refresh.dispose);

  return GoRouter(
    initialLocation: '/',
    refreshListenable: refresh,
    redirect: (context, state) {
      final auth = ref.read(authStateProvider);
      final goingToLogin = state.matchedLocation == '/login';

      // Nobody has looked yet — restoreSession() is still running. Stay on
      // the splash rather than guessing signed-in or signed-out; guessing
      // wrong either flashes a login form for a session that turns out to
      // exist, or drops someone with no session onto a home screen that
      // then has nothing to show.
      if (auth.status == AuthStatus.unknown) {
        return state.matchedLocation == '/' ? null : '/';
      }
      if (auth.status == AuthStatus.signedOut) {
        return goingToLogin ? null : '/login';
      }
      // Signed in — never leave someone stranded on the splash or the login
      // form once a session exists.
      if (goingToLogin || state.matchedLocation == '/') return '/home';
      return null;
    },
    routes: [
      GoRoute(path: '/', builder: (context, state) => const SplashScreen()),
      GoRoute(
          path: '/login', builder: (context, state) => const LoginScreen()),
      GoRoute(
        path: '/home',
        builder: (context, state) => const HomeShell(),
        routes: [
          GoRoute(
            path: 'customers',
            builder: (context, state) => const CustomerListScreen(),
          ),
          GoRoute(
            path: 'products',
            builder: (context, state) => const ProductListScreen(),
          ),
          GoRoute(
            path: 'stock',
            builder: (context, state) => const StockListScreen(),
          ),
          GoRoute(
            path: 'new-order',
            // `extra` carries an OrderPrefill only when opened from "নতুন
            // করে লিখুন" on a rejected order (order_list_screen.dart /
            // sync_status_screen.dart) — absent on the plain "নতুন অর্ডার"
            // tile, which is the ordinary case.
            builder: (context, state) => NewOrderScreen(
              prefill: state.extra as OrderPrefill?,
            ),
          ),
          GoRoute(
            path: 'orders',
            builder: (context, state) => const OrderListScreen(),
          ),
          GoRoute(
            path: 'sync-status',
            builder: (context, state) => const SyncStatusScreen(),
          ),
        ],
      ),
    ],
  );
});
