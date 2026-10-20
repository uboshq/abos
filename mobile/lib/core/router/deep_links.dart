import '../auth/auth_state.dart';
import '../web_inside/web_inside.dart';

/// ⭐ নোটিশ বা SMS-এর লিংক থেকে অ্যাপের ঠিক সেই পাতা (Android App Links; মোবাইলের পরিকল্পনা ৪, অংশ ৬; fe, ২০ অক্টোবর ২০২৬)।
///
/// <p>`https://erp.adi.com.bd/sales/invoices/12` চাপলে ফোন ব্রাউজার নয়, এই অ্যাপ খোলে (সার্ভারের
/// `/.well-known/assetlinks.json` আর ম্যানিফেস্টের `autoVerify`)। Flutter ঠিকানাটা রাউটারে দেয়; এখানে সেটা অ্যাপের নিজের পথে
/// বসে — নোটিশের "খুলুন" আর মেনুর একই দরজায় (`/home/web/<চিহ্ন>`): ফোনের নিজের পর্দা থাকলে সেটা ([NativeForWeb]), নইলে
/// ভিতরের ওয়েব-জানালা।
///
/// <p>⛔ লিংক তালা বা লগইন এড়ায় না: সাইন-ইন না থাকলে পথটা মনে রেখে আগে লগইন, তারপর সেই পাতা; অ্যাপ-তালা আগের মতোই
/// সব পাতার উপরে। ⓘ কেবল ব্যবসার পাতার উপসর্গ — যাচাইয়ের QR, ডিলার-পোর্টাল, লগইন আর পাসওয়ার্ড ফেরানোর লিংক ব্রাউজারেই
/// থাকে (ম্যানিফেস্টের তালিকাও এই একই)।
class DeepLinks {
  const DeepLinks._();

  /// ওয়েবের যে উপসর্গগুলো অ্যাপ নেয় — `AndroidManifest.xml`-এর `pathPrefix` হুবহু এই তালিকা (টেস্ট মেলায়)
  static const List<String> prefixes = [
    '/accounts',
    '/approvals',
    '/credit-control',
    '/customers',
    '/dashboard',
    '/documents',
    '/finance',
    '/governance',
    '/hr',
    '/inventory',
    '/master-data',
    '/notices',
    '/notification',
    '/notifications',
    '/owner',
    '/papers',
    '/promotions',
    '/purchase',
    '/reports',
    '/restaurant',
    '/sales',
    '/suppliers',
  ];

  /// লিংকের অ্যাপ-পথ, অথবা null যদি এটা লিংক না হয় (অ্যাপের নিজের পথ, বা আমাদের নয় এমন কিছু)
  static String? targetFor(Uri uri) {
    if (uri.hasScheme && uri.scheme != 'https') return null;
    if (uri.host.isNotEmpty && uri.host != WebPaths.allowedHost) return null;
    final path = uri.path;
    final ours = prefixes.any((p) => path == p || path.startsWith('$p/'));
    if (!ours) return null;
    final webPath = uri.hasQuery ? '$path?${uri.query}' : path;
    return '/home/${WebPaths.navPathFor(webPath)}';
  }

  /// রাউটারের সিদ্ধান্ত — লিংক না হলে null; সাইন-ইন থাকলে সেই পাতা; নইলে মনে রেখে স্প্ল্যাশ বা লগইন
  static String? route(Uri uri, AuthStatus status) {
    final link = targetFor(uri);
    if (link == null) return null;
    if (status == AuthStatus.signedIn) return link;
    hold(link);
    return status == AuthStatus.unknown ? '/' : '/login';
  }

  static String? _pending;

  /// ⭐ বার্তা বা উইজেটের চাপে অ্যাপের পথ — সাইন-ইন না থাকলে (বা সেশন এখনো খোঁজা হচ্ছে) মনে রাখা, যাতে লগইনের পরে
  /// হোম নয়, ঠিক সেই পাতা খোলে। ⓘ কেবল অ্যাপের নিজের পথ (`/home…`)।
  static void holdUnlessSignedIn(String path, AuthStatus status) {
    if (status != AuthStatus.signedIn && (path == '/home' || path.startsWith('/home/'))) hold(path);
  }

  /// সাইন-ইন হওয়ার আগে আসা লিংক — লগইনের পরে একবারই খোলে
  static void hold(String target) => _pending = target;

  static String? take() {
    final t = _pending;
    _pending = null;
    return t;
  }
}
