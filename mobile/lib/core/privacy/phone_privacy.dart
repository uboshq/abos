import 'package:flutter/foundation.dart';
import 'package:flutter/services.dart';
import 'package:home_widget/home_widget.dart';

/// ⭐ ফোনের পর্দা আড়াল আর উইজেটের অঙ্ক — মালিকের দুই সুইচ (`/me`-র `secureScreens`, `widgetAmounts`; সমন্বয়কের অ্যাপ-অডিট,
/// ৭ অক্টোবর ২০২৬: টাকার পর্দা "সাম্প্রতিক অ্যাপ"-এ ছবি হয়ে থাকত, আর উইজেটে হাতের নগদ অ্যাপ না খুলেই চোখে পড়ত)।
///
/// <p>⛔ ভুল হলে আড়ালের দিকে: অ্যাপ শুরুতেই পর্দা আড়াল করে, `/me` এলে তবে মালিকের সুইচ মানে; উইজেটের অঙ্ক পড়া না গেলে
/// লুকানো। উইজেটের পছন্দ উইজেটের নিজের ভাণ্ডারে — পেছনের রিফ্রেশও পড়তে পারে, নতুন কোনো প্যাকেজ ছাড়া।
/// কিছুই ছোড়ে না — পর্দা বা উইজেটের জন্য সেশন ভাঙবে না।
class PhonePrivacy {
  const PhonePrivacy._();

  static const _channel = MethodChannel('com.abos.abos_mobile/secure');
  static const _amountsKey = 'widget_show_amounts';

  /// পরীক্ষার পথ — আসলটা Android-এর FLAG_SECURE
  @visibleForTesting
  static Future<void> Function(bool on) secureSetter = _setSecure;

  @visibleForTesting
  static Future<bool> Function() amountsReader = _readAmounts;

  @visibleForTesting
  static Future<void> Function(bool on) amountsWriter = _writeAmounts;

  /// পর্দা আড়াল — স্ক্রিনশট আর সাম্প্রতিক-অ্যাপের ছবি বন্ধ
  static Future<void> secure(bool on) async {
    try {
      await secureSetter(on);
    } catch (error) {
      debugPrint('ABOS secure screens not set: $error');
    }
  }

  /// উইজেটে টাকার অঙ্ক দেখানো যায় কি না — পড়া না গেলে না
  static Future<bool> widgetAmounts() async {
    try {
      return await amountsReader();
    } catch (_) {
      return false;
    }
  }

  static Future<void> setWidgetAmounts(bool on) async {
    try {
      await amountsWriter(on);
    } catch (error) {
      debugPrint('ABOS widget amounts not saved: $error');
    }
  }

  /// ⭐ `/me`-র দুই সুইচ মানা — পর্দা আড়াল, আর উইজেটের পছন্দ বদলালে উইজেট নতুন করে আঁকা ([[HomeShell]])
  static Future<void> follow({
    required bool secureScreens,
    required bool widgetAmounts,
    required Future<void> Function() redrawWidgets,
  }) async {
    await secure(secureScreens);
    if (await PhonePrivacy.widgetAmounts() != widgetAmounts) {
      await setWidgetAmounts(widgetAmounts);
      await redrawWidgets();
    }
  }

  /// লুকানো অঙ্কের জায়গায়
  static const hidden = '•••';

  static Future<void> _setSecure(bool on) => _channel.invokeMethod<void>('set', {'on': on});

  static Future<bool> _readAmounts() async => (await HomeWidget.getWidgetData<String>(_amountsKey)) == '1';

  static Future<void> _writeAmounts(bool on) async {
    await HomeWidget.saveWidgetData<String>(_amountsKey, on ? '1' : '0');
  }
}
