import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../locale/app_locale.dart';

/// ফোনের যে অনুমতিগুলো অ্যাপ চলার সময় চায়, আর প্রতিটার আগে বাংলায় কেন — Android-এর নিজের ইংরেজি/অস্পষ্ট
/// প্রশ্নটা আসার **আগে** (অ্যাপ রিলিজ নিরীক্ষা, ec, ২০ অক্টোবর ২০২৬)।
///
/// <p>ⓘ ব্লুটুথ প্রিন্টারের ব্যাখ্যা আগে থেকেই আছে (`ThermalFlow.explainOnce`); এখানে বাকি দুইটা — ক্যামেরা
/// (QR স্ক্যান, স্লিপ/রসিদের ছবি) আর বার্তা (Android 13+)। আঙুলের ছাপ (local_auth) আর "অজানা অ্যাপ বসানো"
/// কোনো সিস্টেম-প্রশ্ন খোলে না, তাই ওদের ব্যাখ্যা নিজের পর্দাতেই।
enum AppPermission { camera, notifications }

/// Android-কে জিজ্ঞেস — অনুমতি আগেই দেওয়া আছে কিনা। দেওয়া থাকলে সিস্টেম-প্রশ্ন আসবে না, তাই ব্যাখ্যাও নয়।
class PermissionCheck {
  const PermissionCheck._();

  static const _channel = MethodChannel('com.abos.abos_mobile/permissions');

  /// null = ফোন উত্তর দিতে পারল না (ⓘ তখন ব্যাখ্যা দেখানো হয় — বাড়তি একটা বাক্য ক্ষতি নয়, না-বলা প্রশ্ন ক্ষতি)।
  static Future<bool?> granted(AppPermission permission) async {
    try {
      return await _channel.invokeMethod<bool>('granted', {'name': permission.name});
    } catch (_) {
      return null;
    }
  }
}

class PermissionRationale {
  const PermissionRationale._();

  static String get cameraTitle => AppLocale.l10n.permissionCameraTitle;
  static String get cameraBody => AppLocale.l10n.permissionCameraBody;

  static String get notificationsTitle => AppLocale.l10n.permissionNotificationsTitle;
  static String get notificationsBody => AppLocale.l10n.permissionNotificationsBody;

  static String get notNow => AppLocale.l10n.permissionNotNow;
  static String get ok => AppLocale.l10n.permissionOk;

  static String titleOf(AppPermission p) => switch (p) {
        AppPermission.camera => cameraTitle,
        AppPermission.notifications => notificationsTitle,
      };

  static String bodyOf(AppPermission p) => switch (p) {
        AppPermission.camera => cameraBody,
        AppPermission.notifications => notificationsBody,
      };

  /// সিস্টেম-প্রশ্নের আগে বাংলা ব্যাখ্যা। true = এগোন (অনুমতি আগেই আছে, অথবা মানুষটা "ঠিক আছে" বললেন);
  /// false = "এখন না" বা পর্দা বন্ধ — তখন সিস্টেম-প্রশ্নও খোলা হয় না।
  ///
  /// <p>[isGranted] পরীক্ষার পথ; আসলটা [PermissionCheck.granted]।
  static Future<bool> explainBeforeAsking(
    BuildContext context,
    AppPermission permission, {
    Future<bool?> Function(AppPermission)? isGranted,
  }) async {
    final already = await (isGranted ?? PermissionCheck.granted)(permission);
    if (already == true) return true;
    if (!context.mounted) return false;
    final yes = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        key: Key('permission-rationale-${permission.name}'),
        title: Text(titleOf(permission)),
        content: Text(bodyOf(permission)),
        actions: [
          TextButton(
              key: const Key('permission-rationale-no'),
              onPressed: () => Navigator.of(context).pop(false),
              child: Text(notNow)),
          FilledButton(
              key: const Key('permission-rationale-ok'),
              onPressed: () => Navigator.of(context).pop(true),
              child: Text(ok)),
        ],
      ),
    );
    return yes == true;
  }
}
