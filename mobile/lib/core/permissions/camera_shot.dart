import 'package:flutter/widgets.dart';
import 'package:image_picker/image_picker.dart';

import 'permission_rationale.dart';

/// ক্যামেরায় একটা ছবি — আগে বাংলা ব্যাখ্যা ([PermissionRationale]), তারপর image_picker (যেটা নিজে Android-এর
/// ক্যামেরা-প্রশ্ন খোলে)। ⓘ "এখন না" বললে null — যেন ছবিই তোলা হয়নি।
Future<XFile?> takeCameraShot(
  BuildContext context, {
  int? imageQuality,
  double? maxWidth,
}) async {
  if (!await PermissionRationale.explainBeforeAsking(context, AppPermission.camera)) {
    return null;
  }
  return ImagePicker().pickImage(
    source: ImageSource.camera,
    imageQuality: imageQuality,
    maxWidth: maxWidth,
  );
}
