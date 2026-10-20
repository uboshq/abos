import 'package:flutter/widgets.dart';
import 'package:image_picker/image_picker.dart';

import '../permissions/permission_rationale.dart';

/// ⭐ মুখের ছবি বাছা — ক্যামেরা বা গ্যালারি, ⛔ অনুমতি কেবল বোতাম চাপলে (অ্যাপ শুরুতে বা পর্দা খুললে নয়; a6, ২০ অক্টোবর ২০২৬)।
///
/// <p>ক্যামেরা: আগে বাংলা ব্যাখ্যা ([PermissionRationale], "এখন না" বললে ক্যামেরা খোলেই না), তারপর Android-এর নিজের প্রশ্ন।
/// গ্যালারি: Android-এর নিজের ছবি-বাছাই (photo picker) — কোনো অনুমতি লাগে না, ফোন কেবল বাছা ছবিটা দেয়; iOS-এ
/// `requestFullMetadata: false` তাই ছবির-ভাণ্ডারের অনুমতিও চাওয়া হয় না।
///
/// <p>ⓘ ২০৪৮ বিন্দুর মধ্যে আনা — ৫১২-র বর্গ বানাতে এর বেশি লাগে না, আর বড় ছবি ভাঙতে কম-RAM ফোন আটকায়।
class FacePicker {
  const FacePicker({this.raw, this.isGranted});

  /// পরীক্ষার পথ — আসলটা image_picker
  final Future<XFile?> Function(ImageSource source)? raw;

  /// পরীক্ষার পথ — অনুমতি আগেই আছে কি না; আসলটা [PermissionCheck.granted]
  final Future<bool?> Function(AppPermission permission)? isGranted;

  static Future<XFile?> _imagePicker(ImageSource source) => ImagePicker().pickImage(
        source: source,
        maxWidth: 2048,
        maxHeight: 2048,
        imageQuality: 95,
        preferredCameraDevice: CameraDevice.front,
        requestFullMetadata: false,
      );

  Future<XFile?> pick(BuildContext context, ImageSource source) async {
    if (source == ImageSource.camera &&
        !await PermissionRationale.explainBeforeAsking(context, AppPermission.camera, isGranted: isGranted)) {
      return null;
    }
    return (raw ?? _imagePicker)(source);
  }
}
