import 'dart:convert';

import 'package:dio/dio.dart';

import '../locale/app_locale.dart';

/// ⛔ The server's host firewall (Imunify360) turned this request away before
/// ABOS ever saw it — some home and office WiFi IPs get a 403 on every call,
/// or its "One moment, please…" splash page (20 Nov 2026). Before this, the
/// login screen said "Could not sign in" and the owner thought the app was
/// broken.
///
/// <p>[ApiClient] swaps such a failure for a [DioException] of type
/// `connectionError` carrying this as its `error`, with **no response**: the
/// network is the fault, so every caller already treats it as one (the sync
/// queue keeps its rows, a refresh does not sign the person out, a screen
/// does not read the firewall's 403 as "you may not"), the raw body never
/// reaches a screen, and [errorMessageFor] names the fault.
class FirewallBlocked implements Exception {
  const FirewallBlocked(this.statusCode);

  /// What the firewall answered with — for a log line, never for a screen.
  final int? statusCode;

  @override
  String toString() => 'FirewallBlocked($statusCode)';
}

/// Whether [response] is the host firewall's answer rather than ours.
///
/// <ul>
/// <li>a 403 whose body names Imunify360 (its JSON `message`, or any text);</li>
/// <li>a body carrying the splash page's marks — "One moment, please" or
/// `wssplashchk` — whatever the status;</li>
/// <li>any HTML answer to an `/api` call. Our server renders JSON for every
/// `api/*` request, errors included (bootstrap/app.php
/// `shouldRenderJsonWhen`), so HTML there is somebody else talking. The one
/// exception is a 502/503/504: a proxy's own "bad gateway" page is the server
/// being down, not this network being turned away, and must not send
/// somebody off to find mobile data.</li>
/// </ul>
///
/// <p>⛔ A 403 of our own — JSON, no "Imunify" in it — is not this, and keeps
/// its meaning ("you may not").
bool looksBlockedByFirewall(Response<dynamic>? response) {
  if (response == null) return false;
  final status = response.statusCode ?? 0;
  final text = _bodyText(response.data);
  final lower = text.toLowerCase();
  if (status == 403 && lower.contains('imunify')) return true;
  if (lower.contains('one moment, please') || lower.contains('wssplashchk')) {
    return true;
  }
  if (status == 502 || status == 503 || status == 504) return false;
  return _isHtml(response, text) && _isApiCall(response.requestOptions);
}

/// Whether [error] is a firewall refusal — already marked by [ApiClient], or
/// a raw one (a client built elsewhere, a test).
bool isFirewallBlock(Object error) =>
    error is DioException &&
    (error.error is FirewallBlocked || looksBlockedByFirewall(error.response));

/// The replacement [ApiClient] passes on: no response (so no raw body and no
/// status for a caller to misread), the network's own type.
DioException firewallBlockedError(RequestOptions options, int? statusCode) =>
    DioException(
      requestOptions: options,
      type: DioExceptionType.connectionError,
      error: FirewallBlocked(statusCode),
      message: 'Blocked by the server host firewall (HTTP $statusCode)',
    );

/// The body as text, as much as needed to recognise it. Bytes (a PDF or a
/// photo asked for) are read as UTF-8 from the first 4 KB only.
String _bodyText(Object? data) {
  if (data == null) return '';
  if (data is String) return data;
  if (data is List<int>) {
    final head = data.length > 4096 ? data.sublist(0, 4096) : data;
    return utf8.decode(head, allowMalformed: true);
  }
  if (data is Map || data is List) {
    try {
      return jsonEncode(data);
    } catch (_) {
      return data.toString();
    }
  }
  return '';
}

bool _isHtml(Response<dynamic> response, String text) {
  final type = response.headers.value(Headers.contentTypeHeader) ?? '';
  if (type.toLowerCase().contains('text/html')) return true;
  final start = text.trimLeft().toLowerCase();
  return start.startsWith('<!doctype html') || start.startsWith('<html');
}

bool _isApiCall(RequestOptions options) =>
    options.uri.path.contains('/api/') || options.uri.path.endsWith('/api');

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

/// The server answered 404 — the door this build knocked on is not there.
///
/// <p>⚠️ <b>Only meaningful for a feature's own endpoint</b>, never for one
/// naming a particular row. `GET /dashboard/today` returning 404 means the
/// server has no such route; `GET /documents/SalesInvoice/{id}/pdf` returning
/// 404 far more likely means that invoice is gone. Same status code, opposite
/// sentences — which is why this is a separate question a caller answers for
/// itself rather than something [errorMessageFor] decides on its own.
bool isRouteAbsent(Object error) =>
    error is DioException && error.response?.statusCode == 404;

/// A message for an error branch that tells a connection problem apart from
/// anything else the server might have said.
///
/// <p>[whenAbsent] is shown for a 404, and callers pass it only where a 404
/// means the **feature** is missing rather than a row. Without it, a phone
/// newer than its server says "তালিকা আনা গেল না" — which reads as *this is
/// broken* or *the server is down*, sending somebody to check a connection
/// that is fine and to report a fault that does not exist.
///
/// <p>⭐ This is not a temporary state to be tidied away once the routes
/// land. Handsets are updated one at a time, by hand, from a link — so an app
/// ahead of its server is the normal condition of a fleet, not an accident,
/// and it stays true for every release after this one.
String errorMessageFor(
  Object error, {
  required String fallback,
  String? whenAbsent,
}) {
  // ⛔ Ahead of "no connection": the phone has a connection, this network is
  // the one the firewall turns away — mobile data is the fix, not a window.
  if (isFirewallBlock(error)) {
    return AppLocale.l10n.networkErrorFirewallBlocked;
  }
  if (isNetworkError(error)) {
    return AppLocale.l10n.networkErrorNoConnection;
  }
  if (whenAbsent != null && isRouteAbsent(error)) {
    // Ahead of any server sentence: Laravel's own 404 body ("Route [x] not
    // defined.") is English, so serverSentence already declines it — but a
    // server that someday answers 404 with a Bangla sentence would otherwise
    // overwrite the one thing the caller knows and this file does not.
    return whenAbsent;
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
