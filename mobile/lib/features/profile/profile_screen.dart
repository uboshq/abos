import 'dart:io';
import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:image_picker/image_picker.dart';
import 'package:material_symbols_icons/symbols.dart';

import '../../core/api_client/network_errors.dart';
import '../../core/auth/auth_state.dart';
import '../../core/navigation/top_bar/abos_screen_bar.dart';
import '../../core/navigation/top_bar/top_bar_context.dart';
import '../../core/profile/face_image.dart';
import '../../core/profile/face_picker.dart';
import '../../core/profile/profile_api.dart';
import '../../core/profile/square_face.dart';
import '../../core/router/profile_routes.dart';
import '../../core/theme/app_palette.dart';
import '../../core/theme/app_spacing.dart';

/// ⭐ "প্রোফাইল ও ছবি" — নিজের তথ্য আর নিজের মুখ বদল (ছবির মেনুর প্রথম সারি; মালিকের অনুমোদিত নমুনা, পাতা ২; অ্যাপ ০.৪.৪১,
/// a6, ২০ অক্টোবর ২০২৬)।
///
/// <p>তিন বোতাম: "ক্যামেরায় তুলুন", "গ্যালারি থেকে", "ছবি সরান" (আগে জিজ্ঞেস)। বাছা ছবি ফোনেই মাঝখান থেকে বর্গ, ৫১২ বিন্দুর
/// JPEG, EXIF ছাড়া ([squareFace]); পাঠানোর সময় কত গেল তা দেখায়; সার্ভারের "না" বাংলায়। হলে নতুন মুখ সাথে সাথে সবখানে
/// ([myFaceProvider] → হোম → ওপরের বার, ছবির মেনু, "আমি")।
///
/// <p>⛔ অনুমতি কেবল বোতাম চাপলে ([FacePicker]) — পর্দা খুললে নয়। ⛔ বাছা ফাইলটা ফোনে থাকে না: পড়ার পরেই মুছে দেওয়া হয়,
/// পাঠানো সফল হোক বা না হোক।
///
/// <p>⭐ পুরনো সার্ভার (দরজা নেই): পর্দা খোলার সময় একবার দেখা ([ProfileApi.doorsOpen]); নেই হলে উপরে "সার্ভার আপডেট হয়নি" আর
/// তিন বোতাম বন্ধ — নিজের তথ্য তবু দেখায়।
class ProfileScreen extends ConsumerStatefulWidget {
  const ProfileScreen({
    super.key,
    this.api = const ServerProfileApi(),
    this.picker = const FacePicker(),
    this.shrink = squareFace,
    this.faceOf,
    this.openPhones,
  });

  final ProfileApi api;
  final FacePicker picker;

  /// বাছা ছবি → পাঠানোর JPEG; পরীক্ষার পথ
  final Future<Uint8List> Function(Uint8List source) shrink;

  /// ঠিকানা → ছবি; পরীক্ষার পথ, না দিলে টোকেনসহ [FaceImage]
  final ImageProvider Function(String url)? faceOf;

  /// "আমার ফোনগুলো" খোলা; না দিলে রাউটার
  final VoidCallback? openPhones;

  static const String title = 'প্রোফাইল ও ছবি';

  @override
  ConsumerState<ProfileScreen> createState() => _ProfileScreenState();
}

class _ProfileScreenState extends ConsumerState<ProfileScreen> {
  /// দরজা আছে কি না — null জানা নেই (তখন বোতাম খোলা; পাঠাতে গেলে নিজের ভুল বলবে)
  bool? _doors;
  bool _busy = false;
  double? _progress;
  String? _error;

  @override
  void initState() {
    super.initState();
    _checkDoors();
  }

  Future<void> _checkDoors() async {
    final open = await widget.api.doorsOpen();
    if (mounted) setState(() => _doors = open);
  }

  bool get _closed => _doors == false;

  Future<void> _take(ImageSource source) async {
    setState(() => _error = null);
    final shot = await widget.picker.pick(context, source);
    if (shot == null || !mounted) {
      if (shot != null) await _forget(shot);
      return;
    }
    Uint8List face;
    try {
      face = await widget.shrink(await shot.readAsBytes());
    } catch (_) {
      if (mounted) setState(() => _error = 'ছবিটা পড়া গেল না — আরেকটা ছবি বাছুন।');
      return;
    } finally {
      // ⛔ বাছা ছবি ফোনের ক্যাশে পড়ে থাকে না — বর্গ বানানো হলেই মোছা, পাঠানো সফল হোক বা না হোক
      await _forget(shot);
    }
    await _upload(face);
  }

  static Future<void> _forget(XFile shot) async {
    try {
      final file = File(shot.path);
      if (await file.exists()) await file.delete();
    } catch (_) {
      // ⓘ মোছা না গেলে (অন্য অ্যাপের ফাইল) — ফোনের নিজের ক্যাশ-পরিষ্কার দেখবে; পাঠানো থামে না
    }
  }

  Future<void> _upload(Uint8List face) async {
    if (!mounted) return;
    setState(() {
      _busy = true;
      _progress = 0;
      _error = null;
    });
    try {
      final change = await widget.api.uploadPhoto(face, onProgress: (sent) {
        if (mounted) setState(() => _progress = sent);
      });
      ref.read(myFaceProvider.notifier).state = change.photoUrl;
      _say('ছবি বদলানো হলো — ওয়েবেও এই মুখ দেখাবে।');
    } on PhotoRefused catch (e) {
      if (mounted) setState(() => _error = e.message);
    } on ProfileDoorsMissing {
      if (mounted) {
        setState(() {
          _doors = false;
          _error = ProfileDoorsMissing.message;
        });
      }
    } catch (e) {
      if (mounted) setState(() => _error = errorMessageFor(e, fallback: 'ছবি পাঠানো গেল না। একটু পরে আবার চেষ্টা করুন।'));
    } finally {
      if (mounted) {
        setState(() {
          _busy = false;
          _progress = null;
        });
      }
    }
  }

  Future<void> _remove() async {
    final yes = await showDialog<bool>(
      context: context,
      builder: (dialog) => AlertDialog(
        key: const ValueKey('profile-remove-confirm'),
        icon: const Icon(Symbols.no_photography),
        title: const Text('ছবিটা সরাবেন?'),
        content: const Text('সরালে আপনার মুখের জায়গায় নামের প্রথম অক্ষর দেখাবে — ফোনে আর ওয়েবে দুই জায়গাতেই।'),
        actions: [
          TextButton(
            key: const ValueKey('profile-remove-no'),
            onPressed: () => Navigator.of(dialog).pop(false),
            child: const Text('না, থাক'),
          ),
          FilledButton(
            key: const ValueKey('profile-remove-yes'),
            style: FilledButton.styleFrom(
              backgroundColor: Theme.of(dialog).colorScheme.error,
              foregroundColor: Theme.of(dialog).colorScheme.onError,
            ),
            onPressed: () => Navigator.of(dialog).pop(true),
            child: const Text('সরান'),
          ),
        ],
      ),
    );
    if (yes != true || !mounted) return;
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await widget.api.removePhoto();
      ref.read(myFaceProvider.notifier).state = null;
      _say('ছবি সরানো হলো।');
    } on ProfileDoorsMissing {
      if (mounted) {
        setState(() {
          _doors = false;
          _error = ProfileDoorsMissing.message;
        });
      }
    } catch (e) {
      if (mounted) setState(() => _error = errorMessageFor(e, fallback: 'ছবি সরানো গেল না। একটু পরে আবার চেষ্টা করুন।'));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  void _say(String text) {
    if (!mounted) return;
    ScaffoldMessenger.maybeOf(context)
      ?..hideCurrentSnackBar()
      ..showSnackBar(SnackBar(content: Text(text)));
  }

  void _openPhones() {
    final open = widget.openPhones;
    if (open != null) return open();
    GoRouter.maybeOf(context)?.push('/home/${ProfileRoutes.phones}');
  }

  @override
  Widget build(BuildContext context) {
    final user = ref.watch(authStateProvider).user;
    final top = ref.watch(topBarContextProvider);
    final url = ref.watch(myFaceProvider);
    final cs = Theme.of(context).colorScheme;
    final p = context.palette;
    final name = user?.name ?? '';
    final where = [top?.company ?? '', top?.branch ?? ''].where((s) => s.trim().isNotEmpty).join(' · ');
    final designation = top?.designation;
    final face = url == null ? null : (widget.faceOf ?? _faceOf)(url);
    final locked = _busy || _closed;
    final percent = ((_progress ?? 0) * 100).round();

    return Scaffold(
      appBar: const AbosScreenBar(title: ProfileScreen.title, module: 'আমি'),
      body: ListView(
        key: const ValueKey('profile-screen'),
        padding: const EdgeInsets.all(AppSpacing.md),
        children: [
          Center(child: _Face(name: name, image: face)),
          const SizedBox(height: AppSpacing.md),
          Text(name,
              key: const ValueKey('profile-name'),
              textAlign: TextAlign.center,
              style: Theme.of(context).textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w700)),
          if (designation != null && designation.trim().isNotEmpty)
            Text(designation.trim(),
                key: const ValueKey('profile-designation'), textAlign: TextAlign.center, style: TextStyle(color: p.onSurfaceMuted)),
          if (where.isNotEmpty)
            Text(where, key: const ValueKey('profile-where'), textAlign: TextAlign.center, style: TextStyle(color: p.onSurfaceMuted)),
          if (user != null && user.email.isNotEmpty)
            Text(user.email, key: const ValueKey('profile-email'), textAlign: TextAlign.center, style: TextStyle(color: p.onSurfaceMuted)),
          const SizedBox(height: AppSpacing.lg),
          if (_closed)
            Semantics(
              liveRegion: true,
              child: Container(
                key: const ValueKey('profile-server-old'),
                padding: const EdgeInsets.all(AppSpacing.md),
                margin: const EdgeInsets.only(bottom: AppSpacing.md),
                decoration: BoxDecoration(
                  color: p.warningSurface,
                  borderRadius: BorderRadius.circular(12),
                  border: Border.all(color: p.border),
                ),
                child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Icon(Symbols.cloud_sync, color: p.warning),
                  const SizedBox(width: AppSpacing.sm),
                  Expanded(
                    child: Text(ProfileDoorsMissing.message, style: TextStyle(color: p.onSurface)),
                  ),
                ]),
              ),
            ),
          if (_progress != null)
            Semantics(
              key: const ValueKey('profile-progress'),
              liveRegion: true,
              label: 'ছবি পাঠানো হচ্ছে, $percent শতাংশ',
              child: ExcludeSemantics(
                child: Padding(
                  padding: const EdgeInsets.only(bottom: AppSpacing.md),
                  child: Column(crossAxisAlignment: CrossAxisAlignment.stretch, children: [
                    LinearProgressIndicator(value: _progress == 0 ? null : _progress),
                    const SizedBox(height: AppSpacing.xs),
                    Text('ছবি পাঠানো হচ্ছে… $percent%', textAlign: TextAlign.center),
                  ]),
                ),
              ),
            ),
          if (_error != null)
            Semantics(
              liveRegion: true,
              child: Padding(
                padding: const EdgeInsets.only(bottom: AppSpacing.md),
                child: Text(_error!,
                    key: const ValueKey('profile-photo-error'),
                    textAlign: TextAlign.center,
                    style: TextStyle(color: cs.error, fontWeight: FontWeight.w600)),
              ),
            ),
          _Wide(
            child: FilledButton.icon(
              key: const ValueKey('profile-camera'),
              onPressed: locked ? null : () => _take(ImageSource.camera),
              icon: const Icon(Symbols.photo_camera),
              label: const Text('ক্যামেরায় তুলুন'),
            ),
          ),
          _Wide(
            child: FilledButton.tonalIcon(
              key: const ValueKey('profile-gallery'),
              onPressed: locked ? null : () => _take(ImageSource.gallery),
              icon: const Icon(Symbols.photo_library),
              label: const Text('গ্যালারি থেকে'),
            ),
          ),
          _Wide(
            child: OutlinedButton.icon(
              key: const ValueKey('profile-remove'),
              style: OutlinedButton.styleFrom(foregroundColor: cs.error),
              onPressed: locked || url == null ? null : _remove,
              icon: const Icon(Symbols.no_photography),
              label: const Text('ছবি সরান'),
            ),
          ),
          Padding(
            padding: const EdgeInsets.symmetric(vertical: AppSpacing.sm),
            child: Text(
              'ছবি মাঝখান থেকে বর্গ করে ছোট করা হয়, ছবির ভেতরের জায়গা-সময়ের তথ্য মুছে। কেবল আপনার কোম্পানির সহকর্মীরা দেখেন।',
              textAlign: TextAlign.center,
              style: TextStyle(color: p.onSurfaceMuted, fontSize: 13),
            ),
          ),
          const Divider(height: AppSpacing.lg),
          ListTile(
            key: const ValueKey('profile-phones'),
            minVerticalPadding: 12,
            leading: const Icon(Symbols.devices),
            title: const Text('আমার ফোনগুলো'),
            subtitle: const Text('কোন কোন ফোনে ঢোকা আছেন — হারানো ফোন দূর থেকে বের করে দিন'),
            trailing: const Icon(Symbols.chevron_right),
            onTap: _openPhones,
          ),
        ],
      ),
    );
  }

  static ImageProvider _faceOf(String url) => ResizeImage(FaceImage(url), width: 360, policy: ResizeImagePolicy.fit);
}

/// বড় গোল মুখ — ছবি না থাকলে বা না এলে নামের প্রথম অক্ষর
class _Face extends StatelessWidget {
  const _Face({required this.name, this.image});

  final String name;
  final ImageProvider? image;

  static const double size = 112;

  @override
  Widget build(BuildContext context) {
    final cs = Theme.of(context).colorScheme;
    final letter = name.trim().isEmpty ? '?' : name.trim().characters.first;
    final initial = Center(
      child: Text(letter,
          key: const ValueKey('profile-initial'),
          textScaler: TextScaler.noScaling,
          style: TextStyle(fontSize: 44, fontWeight: FontWeight.w700, color: cs.onPrimaryContainer)),
    );
    return Semantics(
      key: const ValueKey('profile-face'),
      image: true,
      label: image == null ? 'আপনার ছবি নেই — নামের প্রথম অক্ষর দেখানো হচ্ছে' : 'আপনার ছবি',
      child: ExcludeSemantics(
        child: Container(
          width: size,
          height: size,
          decoration: BoxDecoration(shape: BoxShape.circle, color: cs.primaryContainer, border: Border.all(color: cs.outlineVariant, width: 2)),
          child: ClipOval(
            child: image == null
                ? initial
                : Image(
                    key: const ValueKey('profile-photo'),
                    image: image!,
                    fit: BoxFit.cover,
                    width: size,
                    height: size,
                    errorBuilder: (_, __, ___) => initial,
                  ),
          ),
        ),
      ),
    );
  }
}

/// পুরো চওড়া, অন্তত ৪৮dp উঁচু (WCAG 2.5.8)
class _Wide extends StatelessWidget {
  const _Wide({required this.child});

  final Widget child;

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.only(bottom: AppSpacing.sm),
        child: ConstrainedBox(constraints: const BoxConstraints(minHeight: 48), child: child),
      );
}
