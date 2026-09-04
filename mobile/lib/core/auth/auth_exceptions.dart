/// Password was accepted; a six-digit authenticator code is the only thing
/// left. The login screen catches this and swaps to the code field rather
/// than showing it as a failure — see docs/Contract, §১: `POST /auth/login`,
/// the 409 branch.
class MfaRequiredException implements Exception {
  const MfaRequiredException({required this.codeWasWrong});

  /// True once somebody has already typed a code and it was refused — the
  /// screen must not treat this the same as "no code entered yet", or a
  /// wrong six digits would look identical to never having been asked.
  final bool codeWasWrong;
}

/// Everything else `/auth/login` can refuse with — wrong identifier/password,
/// a locked account, or the throttle. [message] is the server's own Bangla
/// sentence (`network_errors.dart`'s `serverSentence`), shown verbatim rather
/// than replaced with a generic line: see the contract's closing rule on
/// showing 4xx text as UI, not swallowing it.
class AuthFailure implements Exception {
  const AuthFailure(this.message);

  final String message;
}
