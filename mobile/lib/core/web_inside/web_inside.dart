import 'dart:convert';

import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';
import 'package:webview_flutter/webview_flutter.dart';

import '../api_client/api_client.dart';
import '../api_client/network_errors.dart';
import '../config/app_config.dart';

/// ⭐ অ্যাপের ভিতরে ওয়েবের পর্দা (মালিক, ১৩ অক্টোবর ২০২৬: *"web e na dhuke jar jar kaj 100% app ei korte pare"*)।
///
/// <p>যে মেনু-সারির ফোন-পর্দা নেই, `/me` তার ওয়েব-পথ পাঠায় (`web`, সাইটের ভিতরের `/…`)। অ্যাপ সেটা `/home/web/<চিহ্ন>`
/// পথে নিজের ভিতরের ওয়েব-জানালায় খোলে ([WebInsideScreen])। এই ফাইল কেবল তিনটা কথা জানে: পথটা কীভাবে অ্যাপের পথে বসে,
/// সার্ভারের কাছে একবারের চাবি কীভাবে চাইতে হয়, আর জানালা কোন ঠিকানায় থাকতে পারে।
class WebPaths {
  const WebPaths._();

  /// The `/home/<segment>` every in-app web screen hangs under.
  static const String segment = 'web';

  /// `/accounts/chart` → `web/L2FjY291bnRzL2NoYXJ0` — one path segment, so go_router and the recents list (tab-separated)
  /// carry it untouched, and a favourite of a web screen is just another path.
  static String navPathFor(String webPath) =>
      '$segment/${base64Url.encode(utf8.encode(webPath)).replaceAll('=', '')}';

  static bool isWeb(String navPath) => navPath.startsWith('$segment/');

  static final Map<String, String> _titles = {};

  /// The menu's own name for a web screen — remembered when the menu is built ([MenuRepository.webTiles]), read by the
  /// window's title bar; the address itself carries no words.
  static void rememberTitle(String navPath, String label) => _titles[navPath] = label;

  static String titleOf(String navPath) => _titles[navPath] ?? 'ABOS';

  /// The web path a `/home/web/<code>` stands for, or null when the code is not one of ours.
  static String? webPathOf(String code) {
    try {
      final padded = code.padRight((code.length + 3) ~/ 4 * 4, '=');
      final path = utf8.decode(base64Url.decode(padded));
      return path.startsWith('/') && !path.startsWith('//') ? path : null;
    } catch (_) {
      return null;
    }
  }

  /// The one host the window may stay on — the server this app talks to ([AppConfig.apiBaseUrl]).
  static String get allowedHost => Uri.parse(AppConfig.apiBaseUrl).host;

  /// ⛔ ABOS-এর ঠিকানাতেই থাকে — অন্য যেকোনো ঠিকানা (বাইরের লিংক, মানচিত্র, ইমেইল) ফোনের নিজের অ্যাপে খোলে।
  static bool staysInside(Uri uri, {String? host}) {
    if (uri.scheme == 'about' || uri.scheme == 'data' || uri.scheme == 'blob') return true;
    if (uri.scheme != 'https' && uri.scheme != 'http') return false;
    return uri.host.toLowerCase() == (host ?? allowedHost).toLowerCase();
  }
}

/// What the key door answered.
sealed class WebKey {
  const WebKey();
}

/// A one-time address the window opens — dies in 60 seconds, opens once, only on this phone.
class WebKeyIssued extends WebKey {
  const WebKeyIssued(this.url);
  final String url;
}

/// The person's two-step code is wanted (wrong when [wasWrong]) — the same rule as the web sign-in.
class WebKeyNeedsCode extends WebKey {
  const WebKeyNeedsCode({required this.wasWrong, required this.message});
  final bool wasWrong;
  final String message;
}

/// Refused — the sentence is the server's own Bengali where it sent one.
class WebKeyRefused extends WebKey {
  const WebKeyRefused(this.message);
  final String message;
}

class WebHandoffApi {
  const WebHandoffApi._();

  /// `POST /web-handoff` — a key for [webPath]; [code] when the door asked for the two-step code.
  static Future<WebKey> ask(String webPath, {String? code}) async {
    try {
      final response = await ApiClient.dio.post<Map<String, dynamic>>('/web-handoff', data: {
        'path': webPath,
        if (code != null && code.trim().isNotEmpty) 'code': code.trim(),
      });
      final url = response.data?['url'];
      if (url is String && url.isNotEmpty) return WebKeyIssued(url);
      return const WebKeyRefused('পাতাটা খোলা গেল না। কিছুক্ষণ পর আবার চেষ্টা করুন।');
    } on DioException catch (error) {
      final body = error.response?.data;
      if (error.response?.statusCode == 409 && body is Map && body['needsCode'] == true) {
        return WebKeyNeedsCode(
          wasWrong: body['codeWasWrong'] == true,
          message: serverSentence(error) ??
              (body['codeWasWrong'] == true ? 'কোডটা মেলেনি — আবার দিন।' : 'দুই ধাপের কোডটা দিন।'),
        );
      }
      return WebKeyRefused(errorMessageFor(error,
          fallback: 'পাতাটা খোলা গেল না। কিছুক্ষণ পর আবার চেষ্টা করুন।',
          whenAbsent: 'সার্ভার এখনো এই সুবিধা পায়নি — অফিসকে সার্ভার হালনাগাদ করতে বলুন।'));
    } catch (error) {
      return WebKeyRefused(errorMessageFor(error, fallback: 'পাতাটা খোলা গেল না। কিছুক্ষণ পর আবার চেষ্টা করুন।'));
    }
  }
}

/// ⭐ বেরোনোর সময় জানালার কুকি মোছা — পরের জন যেন আগের জনের ওয়েব-সেশনে না ঢোকেন (একই ফোন, কয়েকজন)।
class WebInsideSession {
  const WebInsideSession._();

  @visibleForTesting
  static Future<void> Function() clearer = _clear;

  static Future<void> clear() async {
    try {
      await clearer();
    } catch (error) {
      debugPrint('ABOS web cookies not cleared: $error');
    }
  }

  static Future<void> _clear() async {
    await WebViewCookieManager().clearCookies();
  }
}
