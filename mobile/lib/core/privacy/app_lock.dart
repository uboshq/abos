import 'package:flutter/material.dart';
import 'package:local_auth/local_auth.dart';
import 'package:local_auth_android/local_auth_android.dart';

import '../theme/app_colors.dart';
import '../theme/app_spacing.dart';

/// ⭐ অ্যাপ-তালা — ফোনের নিজের আঙুলের ছাপ বা PIN (সমন্বয়কের অ্যাপ-অডিট, ৭ অক্টোবর ২০২৬: খোলা ফোন হারালে বা অন্যের হাতে
/// গেলে যে কেউ মালিকের অনুমোদন-বাক্সে সই দিতে আর নগদ-ব্যাংকের অঙ্ক দেখতে পারতেন; OWASP MASVS-AUTH)।
///
/// <p>কখন চায়: সংরক্ষিত সেশনে অ্যাপ খুললে, আর [idleLimit]-এর বেশি বাইরে থেকে ফিরলে। এইমাত্র পাসওয়ার্ড দিয়ে ঢুকলে নয়।
/// ⓘ ফোনে নিজের কোনো তালা (PIN, নকশা, আঙুল) না থাকলে তালা দেওয়া যায় না — তখন আটকানো হয় না (নইলে মানুষটা নিজেই
/// আটকে যেতেন), পর্দা বলে দেয় ফোনে তালা দিতে।
class AppLock extends ChangeNotifier with WidgetsBindingObserver {
  AppLock({
    required bool startLocked,
    Future<bool> Function()? authenticate,
    Future<bool> Function()? deviceSupported,
    DateTime Function()? clock,
  })  : _locked = startLocked,
        _authenticate = authenticate ?? _deviceAuthenticate,
        _deviceSupported = deviceSupported ?? _deviceHasALock,
        _clock = clock ?? DateTime.now;

  static const Duration idleLimit = Duration(minutes: 5);

  final Future<bool> Function() _authenticate;
  final Future<bool> Function() _deviceSupported;
  final DateTime Function() _clock;

  bool _locked;
  bool _busy = false;
  bool _noDeviceLock = false;
  DateTime? _leftAt;

  /// কেউ ঢুকে আছেন কি না — বেরোনো অবস্থায় তালার কিছু নেই
  bool signedIn = true;

  bool get locked => _locked && signedIn;
  bool get busy => _busy;

  /// ফোনে নিজের তালা নেই — একবার জানানো হয়
  bool get noDeviceLock => _noDeviceLock;

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    switch (state) {
      case AppLifecycleState.paused || AppLifecycleState.hidden:
        _leftAt ??= _clock();
      case AppLifecycleState.resumed:
        final left = _leftAt;
        _leftAt = null;
        if (signedIn && left != null && _clock().difference(left) >= idleLimit) {
          _locked = true;
          notifyListeners();
        }
      default:
        break;
    }
  }

  /// খোলা — ফোনের নিজের যাচাই; ফোনে তালা না থাকলে খোলা থাকে (আটকানোর উপায় নেই)
  Future<void> unlock() async {
    if (_busy) return;
    _busy = true;
    notifyListeners();
    try {
      if (!await _deviceSupported()) {
        _noDeviceLock = true;
        _locked = false;
        return;
      }
      if (await _authenticate()) _locked = false;
    } catch (_) {
      // ⓘ যাচাইয়ের পর্দা খুলল না — তালা থাকে, আবার চাপা যায়
    } finally {
      _busy = false;
      notifyListeners();
    }
  }

  static Future<bool> _deviceHasALock() => LocalAuthentication().isDeviceSupported();

  static Future<bool> _deviceAuthenticate() => LocalAuthentication().authenticate(
        localizedReason: 'ABOS খুলতে আঙুলের ছাপ বা ফোনের PIN দিন',
        authMessages: const [
          AndroidAuthMessages(signInTitle: 'ABOS খুলুন', cancelButton: 'থাক'),
        ],
        persistAcrossBackgrounding: true,
      );
}

/// তালা থাকলে অ্যাপের উপরে তালার পর্দা — নিচের অ্যাপ অক্ষত থাকে, খুললেই যেখানে ছিলেন সেখানে
class AppLockGate extends StatefulWidget {
  const AppLockGate({super.key, required this.lock, required this.child, this.onSignOut});

  final AppLock lock;
  final Widget child;
  final VoidCallback? onSignOut;

  @override
  State<AppLockGate> createState() => _AppLockGateState();
}

class _AppLockGateState extends State<AppLockGate> {
  bool _asked = false;
  bool _noteDismissed = false;

  @override
  void initState() {
    super.initState();
    widget.lock.addListener(_changed);
  }

  @override
  void dispose() {
    widget.lock.removeListener(_changed);
    super.dispose();
  }

  void _changed() {
    if (!widget.lock.locked) _asked = false;
    if (mounted) setState(() {});
  }

  @override
  Widget build(BuildContext context) {
    final lock = widget.lock;
    if (lock.locked && !_asked) {
      // ⓘ নিজে থেকে একবার চাওয়া — বোতাম চাপতে না হয়
      _asked = true;
      WidgetsBinding.instance.addPostFrameCallback((_) => lock.unlock());
    }
    return Stack(
      children: [
        widget.child,
        if (lock.locked)
          Positioned.fill(
            child: Material(
              key: const ValueKey('app-lock'),
              color: AppColors.surface,
              child: SafeArea(
                child: Center(
                  child: Padding(
                    padding: const EdgeInsets.all(AppSpacing.lg),
                    child: Column(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        const Icon(Icons.lock_outline, size: 56, color: AppColors.primary),
                        const SizedBox(height: AppSpacing.md),
                        const Text('অ্যাপ তালাবদ্ধ', style: TextStyle(fontSize: 18, fontWeight: FontWeight.w700)),
                        const SizedBox(height: AppSpacing.sm),
                        const Text('আঙুলের ছাপ বা ফোনের PIN দিয়ে খুলুন।', textAlign: TextAlign.center),
                        const SizedBox(height: AppSpacing.lg),
                        FilledButton.icon(
                          key: const ValueKey('app-lock-open'),
                          onPressed: lock.busy ? null : lock.unlock,
                          icon: const Icon(Icons.fingerprint),
                          label: const Text('খুলুন'),
                        ),
                        if (widget.onSignOut != null)
                          TextButton(onPressed: widget.onSignOut, child: const Text('বেরিয়ে যান')),
                      ],
                    ),
                  ),
                ),
              ),
            ),
          ),
        // ⓘ ফোনে নিজের তালা নেই — অ্যাপ আটকায় না, কেবল একবার বলে
        if (!lock.locked && lock.noDeviceLock && !_noteDismissed)
          Positioned(
            left: 0,
            right: 0,
            top: 0,
            child: SafeArea(
              child: Material(
                key: const ValueKey('app-lock-no-device-lock'),
                color: AppColors.warningSurface,
                child: ListTile(
                  dense: true,
                  leading: const Icon(Icons.lock_open, color: AppColors.warning),
                  title: const Text('এই ফোনে তালা দেওয়া নেই — ফোনের সেটিংসে PIN বা আঙুলের ছাপ চালু করুন, তবেই অ্যাপ-তালা কাজ করবে।',
                      style: TextStyle(fontSize: 12.5, color: AppColors.warning)),
                  trailing: IconButton(
                    icon: const Icon(Icons.close, size: 18),
                    onPressed: () => setState(() => _noteDismissed = true),
                  ),
                ),
              ),
            ),
          ),
      ],
    );
  }
}
