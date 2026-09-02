import 'package:dio/dio.dart';

/// Whether a caught error is the connection's fault -- unreachable host, timed
/// out, no signal -- rather than the server having an opinion about what was
/// sent.
///
/// <p>The two need telling apart everywhere, and the bug that comes from not
/// doing it is always the same shape: a screen shows one fixed message for
/// every failure, so "you are out of coverage" and "this shop is over its
/// credit limit" read identically -- and only one of those is fixed by walking
/// to a window.
bool isNetworkError(Object error) =>
    error is DioException &&
    (error.type == DioExceptionType.connectionError ||
        error.type == DioExceptionType.connectionTimeout ||
        error.type == DioExceptionType.sendTimeout ||
        error.type == DioExceptionType.receiveTimeout);

/// A message for an error branch that tells a connection problem apart from
/// anything else the server might have said.
String errorMessageFor(Object error, {required String fallback}) {
  if (isNetworkError(error)) {
    return 'সংযোগ নেই। ইন্টারনেট চেক করে আবার চেষ্টা করুন।';
  }
  return serverSentence(error) ?? fallback;
}

/// What the server said, when it said something meant to be read.
///
/// <p>Null unless all three hold, and each rules out a way this could show
/// somebody a string that helps nobody:
///
/// <p><b>4xx only.</b> A 4xx is the server having an opinion about the
/// request. A 5xx is the server having failed, and its message is as likely to
/// be an exception's own text as anything a person can act on.
///
/// <p><b>Bangla only.</b> ABOS writes its person-facing refusals in Bangla
/// (`lang/bn`, and every module's own `Resources/lang/bn`) and its framework
/// noise in English -- Laravel's own "The trx date field is required." and
/// "Route [x] not defined." are both real 4xx bodies, and neither belongs on a
/// shop's phone. That is a convention rather than a guarantee, which is why
/// the fallback stays: a deliberate English message is simply not shown,
/// exactly as before. This can add information, never remove it.
///
/// <p><b>Non-empty.</b> A blank message would replace a useful fallback with
/// nothing at all.
String? serverSentence(Object error) {
  if (error is! DioException) return null;
  final status = error.response?.statusCode ?? 0;
  if (status < 400 || status >= 500) return null;
  final body = error.response?.data;
  // Laravel puts the human sentence in `message` for both an aborted request
  // and a ValidationException (where `errors` carries the per-field detail).
  final message = body is Map ? body['message'] : null;
  if (message is! String) return null;
  final text = message.trim();
  if (text.isEmpty || !_hasBangla(text)) return null;
  return text;
}

/// Any character in the Bengali block. One is enough: a mixed sentence is
/// still written for a person.
bool _hasBangla(String text) =>
    text.runes.any((r) => r >= 0x0980 && r <= 0x09FF);
