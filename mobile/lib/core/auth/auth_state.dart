import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'auth_controller.dart';
import 'auth_user.dart';

/// Where the app is in the sign-in lifecycle — main.dart / the router watch
/// this, not a boolean, because "not signed in" has two different reasons a
/// screen must tell apart: nobody has looked yet ([AuthStatus.unknown], the
/// splash stays up) versus somebody looked and there is no session
/// ([AuthStatus.signedOut], go to /login).
enum AuthStatus { unknown, signedOut, signedIn }

class AuthState {
  const AuthState({
    required this.status,
    this.user,
    this.needsMfaCode = false,
    this.mfaCodeWasWrong = false,
  });

  const AuthState.unknown() : this(status: AuthStatus.unknown);
  const AuthState.signedOut() : this(status: AuthStatus.signedOut);
  const AuthState.signedIn(AuthUser user)
      : this(status: AuthStatus.signedIn, user: user);

  final AuthStatus status;
  final AuthUser? user;

  /// The 409 branch of `/auth/login`: password was right, a six-digit code
  /// is the only thing standing between here and a session.
  final bool needsMfaCode;

  /// The code just tried was wrong — a wrong code must not lock the account
  /// the way a wrong password does (see the contract's own warning), so this
  /// is its own flag rather than folding into a generic error string that a
  /// retry would read as the same kind of failure.
  final bool mfaCodeWasWrong;

  AuthState copyWith({
    AuthStatus? status,
    AuthUser? user,
    bool? needsMfaCode,
    bool? mfaCodeWasWrong,
  }) =>
      AuthState(
        status: status ?? this.status,
        user: user ?? this.user,
        needsMfaCode: needsMfaCode ?? this.needsMfaCode,
        mfaCodeWasWrong: mfaCodeWasWrong ?? this.mfaCodeWasWrong,
      );
}

final authStateProvider =
    StateNotifierProvider<AuthController, AuthState>((ref) {
  throw UnimplementedError(
      'authStateProvider must be overridden in main.dart with the real '
      'AuthController once SyncEngine/ReferenceCache have finished init() — '
      'see main.dart\'s own comment for why the order matters.');
});
