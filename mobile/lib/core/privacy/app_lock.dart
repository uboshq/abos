import 'dart:async';

import 'package:flutter/material.dart';
import 'package:local_auth/local_auth.dart';
import 'package:local_auth_android/local_auth_android.dart';

import '../locale/app_locale.dart';
import '../locale/l10n.dart';
import '../navigation/top_bar/abos_top_bar.dart' show bnDigits;
import '../theme/app_colors.dart';
import '../theme/app_palette.dart';
import '../theme/app_spacing.dart';
import 'app_pin.dart';
import 'lock_delay.dart';

/// ⭐ অ্যাপ-তালা — ফোনের নিজের আঙুলের ছাপ বা PIN (সমন্বয়কের অ্যাপ-অডিট, ৭ অক্টোবর ২০২৬: খোলা ফোন হারালে বা অন্যের হাতে
/// গেলে যে কেউ মালিকের অনুমোদন-বাক্সে সই দিতে আর নগদ-ব্যাংকের অঙ্ক দেখতে পারতেন; OWASP MASVS-AUTH)।
///
/// <p>কখন চায়: সংরক্ষিত সেশনে অ্যাপ খুললে, আর [idleLimit]-এর বেশি বাইরে থেকে ফিরলে। এইমাত্র পাসওয়ার্ড দিয়ে ঢুকলে নয়।
/// ⭐ ফোনে নিজের কোনো তালা (PIN, নকশা, আঙুল) না থাকলে অ্যাপের নিজের PIN ([AppPin]; পূর্ণ নিরীক্ষা §৯ ⚠️১২, ১৮ অক্টোবর ২০২৬)।
/// ⛔ আগে তখন অ্যাপ সোজা খুলত, কেবল সতর্কবার্তা। ⓘ PIN বসানো যায় কেবল পাসওয়ার্ড দিয়ে ঢোকার পরপরই ([needsNewPin]) — তালা-
/// পর্দায় নয়, নইলে খোলা ফোন হাতে পাওয়া কেউ নিজের PIN বসিয়ে ঢুকতেন। PIN ছাড়া পুরনো সেশন ফোন আবার খুললে পাসওয়ার্ড চায়
/// ([mustSignInAgain])। পাঁচবার ভুলে বের করে দেয়।
class AppLock extends ChangeNotifier with WidgetsBindingObserver {
  AppLock({
    required bool startLocked,
    Future<bool> Function()? authenticate,
    Future<bool> Function()? deviceSupported,
    DateTime Function()? clock,
    AppPin? pin,
    Duration Function()? idleLimitOf,
  })  : _locked = startLocked,
        _idleLimitOf = idleLimitOf ?? (() => LockDelay.instance.current.value),
        _pin = pin ?? AppPin(),
        _authenticate = authenticate ?? _deviceAuthenticate,
        _deviceSupported = deviceSupported ?? _deviceHasALock,
        _clock = clock ?? DateTime.now;

  /// ডিফল্ট সময় — মানুষ না বাছলে ([[LockDelay]])
  static const Duration idleLimit = Duration(minutes: LockDelay.defaultMinutes);

  /// ⭐ এখনকার সময় — "আমি" ট্যাবের বাছাই ([[LockDelay]]); ফেরার মুহূর্তে পড়া, তাই বদলালে সাথে সাথে খাটে
  final Duration Function() _idleLimitOf;

  final Future<bool> Function() _authenticate;
  final Future<bool> Function() _deviceSupported;
  final DateTime Function() _clock;
  final AppPin _pin;

  bool _checked = false;
  bool _pinSet = false;
  bool _askPin = false;
  bool _mustSignInAgain = false;
  bool _tooMany = false;

  bool _locked;
  bool _busy = false;
  bool _noDeviceLock = false;
  DateTime? _leftAt;

  /// কেউ ঢুকে আছেন কি না — বেরোনো অবস্থায় তালার কিছু নেই
  ///
  /// <p>⛔ বেরোলে তালাও খোলে — নইলে তালা-পর্দা থেকে বেরিয়ে (বা তালা থাকা অবস্থায় সেশন শেষ হলে) পরের জন পাসওয়ার্ড দিয়ে
  /// ঢুকেই আগের জনের ফোনের PIN চাওয়া পর্দায় পড়তেন, অথচ নিয়ম "এইমাত্র পাসওয়ার্ড দিলে তালা নয়" (ফোন লাইন-ধরে নিরীক্ষা,
  /// ১৫ অক্টোবর ২০২৬)।
  bool get signedIn => _signedIn;
  set signedIn(bool value) {
    if (!value) {
      _locked = false;
      _forgetPinState();
    }
    final cameIn = value && !_signedIn;
    _signedIn = value;
    // ⭐ নতুন করে ঢুকলে আবার দেখা — ফোনে তালা নেই আর PIN নেই হলে PIN বসাতে বলা ([needsNewPin])
    if (cameIn) unawaited(prepare());
  }

  bool _signedIn = true;

  bool get locked => _locked && signedIn;
  bool get busy => _busy;

  /// ফোনে নিজের তালা নেই
  bool get noDeviceLock => _noDeviceLock;

  /// তালা-পর্দায় অ্যাপের PIN চাইতে হবে (ফোনে তালা নেই, PIN বসানো আছে)
  bool get asksPin => locked && _askPin;

  /// ⛔ ফোনে তালা নেই, PIN-ও নেই, আর তালা পড়েছে — কে ধরে আছেন জানা নেই: পাসওয়ার্ড দিয়ে আবার ঢুকতে হবে
  bool get mustSignInAgain => locked && _mustSignInAgain;

  /// ⭐ এইমাত্র পাসওয়ার্ড দিয়ে ঢুকেছেন, ফোনে তালা নেই, PIN নেই — এখনই বসাতে হবে (পর্দা আটকে রাখে)
  bool get needsNewPin => signedIn && !_locked && _checked && _noDeviceLock && !_pinSet;

  /// পাঁচবার ভুল — বের করে দিতে হবে
  bool get tooManyTries => _tooMany;

  /// ⭐ ফোনে তালা আছে কি না, আর PIN — ঢোকার পরে একবার (গেট ডাকে)
  Future<void> prepare() async {
    if (!signedIn) return;
    try {
      _noDeviceLock = !await _deviceSupported();
      _pinSet = _noDeviceLock && await _pin.isSet();
    } catch (_) {
      _noDeviceLock = false;
    }
    _checked = true;
    notifyListeners();
  }

  /// নতুন PIN — দুইবার একই; ভুল হলে কারণ, ঠিক হলে null
  Future<String?> setPin(String first, String again) async {
    final a = AppPin.normalise(first);
    if (a == null) return AppLocale.l10n.lockScreenPinLength(bnDigits(4), bnDigits(6));
    if (a != AppPin.normalise(again)) return AppLocale.l10n.lockScreenPinTwice;
    await _pin.set(a);
    _pinSet = true;
    notifyListeners();
    return null;
  }

  /// তালা-পর্দার PIN — মিললে খোলে; না মিললে কারণ (কয়বার বাকি), পাঁচবারের পরে অপেক্ষা ([AppPin.waitAfter])
  ///
  /// <p>⛔ আগে পাঁচবার ভুলে বের করে দিত — মালিক, ২০ নভেম্বর ২০২৬: তালা মানে তালা, লগআউট নয়; লগআউট কেবল নিজে বের হলে, "আমার
  /// ফোনগুলো" থেকে বাদ দিলে, পাসওয়ার্ড বদলালে, প্রশাসক বন্ধ করলে। এখন ফোনের নিজের তালার মতো অপেক্ষা, দ্বিগুণ হয়ে।
  /// ⓘ অপেক্ষার মধ্যে PIN যাচাই হয় না — ভুলও গোনা হয় না, তাই অপেক্ষা নিজে নিজে বাড়ে না।
  Future<String?> submitPin(String typed) async {
    final wait = await pinWaitLeft();
    if (wait > Duration.zero) return _waitMessage(wait);
    if (await _pin.check(typed)) {
      _locked = false;
      _askPin = false;
      _tooMany = false;
      notifyListeners();
      return null;
    }
    final tries = await _pin.tries();
    final left = AppPin.maxTries - tries;
    if (left <= 0) {
      _tooMany = true;
      notifyListeners();
      return _waitMessage(AppPin.waitAfter(tries));
    }
    return AppLocale.l10n.lockScreenPinWrong(left, bnDigits(left));
  }

  /// এখনো কতক্ষণ PIN দেওয়া যাবে না — শূন্য হলে দেওয়া যায়
  Future<Duration> pinWaitLeft() async {
    final wait = AppPin.waitAfter(await _pin.tries());
    final last = await _pin.lastWrongAt();
    if (wait == Duration.zero || last == null) return Duration.zero;
    final left = last.add(wait).difference(_clock());
    return left.isNegative ? Duration.zero : left;
  }

  String _waitMessage(Duration wait) {
    final seconds = wait.inSeconds < 1 ? 1 : wait.inSeconds;
    return AppLocale.l10n.lockScreenPinWait(seconds, bnDigits(seconds));
  }

  /// বেরোলে PIN-এর হিসাব ভোলা — ভাণ্ডারের PIN মোছে [[AuthController]] (বেরোনোর পথ একটাই)
  void _forgetPinState() {
    _pinSet = false;
    _askPin = false;
    _mustSignInAgain = false;
    _tooMany = false;
    _checked = false;
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    switch (state) {
      case AppLifecycleState.paused || AppLifecycleState.hidden:
        // ⓘ যাচাই চলার সময় ফোনের PIN-পর্দা অ্যাপকে পেছনে পাঠায় — ওটা "বাইরে যাওয়া" নয়; নইলে "সাথে সাথে" বাছলে যাচাই শেষে
        //   ফিরেই আবার তালা, চিরকাল ঘুরত
        if (_busy) break;
        _leftAt ??= _clock();
      case AppLifecycleState.resumed:
        final left = _leftAt;
        _leftAt = null;
        if (signedIn && left != null && _clock().difference(left) >= _idleLimitOf()) {
          _locked = true;
          notifyListeners();
        }
      default:
        break;
    }
  }

  /// খোলা — ফোনের নিজের যাচাই; ফোনে তালা না থাকলে অ্যাপের PIN চায়, PIN-ও না থাকলে পাসওয়ার্ড
  Future<void> unlock() async {
    if (_busy) return;
    _busy = true;
    notifyListeners();
    try {
      if (!await _deviceSupported()) {
        // ⛔ ফোনে তালা নেই — আর খোলা নয়: অ্যাপের PIN, PIN না থাকলে পাসওয়ার্ড (পূর্ণ নিরীক্ষা §৯ ⚠️১২)
        _noDeviceLock = true;
        _pinSet = await _pin.isSet();
        _askPin = _pinSet;
        _mustSignInAgain = !_pinSet;
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
        localizedReason: AppLocale.l10n.lockScreenBiometricReason,
        authMessages: [
          AndroidAuthMessages(
              signInTitle: AppLocale.l10n.lockScreenBiometricTitle, cancelButton: AppLocale.l10n.lockScreenBiometricCancel),
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

  final _pinField = TextEditingController();
  final _pinAgain = TextEditingController();
  String? _pinError;

  @override
  void initState() {
    super.initState();
    widget.lock.addListener(_changed);
    widget.lock.prepare();
  }

  @override
  void dispose() {
    widget.lock.removeListener(_changed);
    _pinField.dispose();
    _pinAgain.dispose();
    super.dispose();
  }

  void _changed() {
    if (!widget.lock.locked) _asked = false;
    // ⓘ পাঁচবার ভুলে আর বের করে দেওয়া নয় — অপেক্ষা ([AppLock.submitPin], মালিক, ২০ নভেম্বর ২০২৬)
    if (mounted) setState(() {});
  }

  Future<void> _enterPin() async {
    final why = await widget.lock.submitPin(_pinField.text);
    _pinField.clear();
    if (mounted) setState(() => _pinError = why);
  }

  Future<void> _savePin() async {
    final why = await widget.lock.setPin(_pinField.text, _pinAgain.text);
    _pinField.clear();
    _pinAgain.clear();
    if (mounted) setState(() => _pinError = why);
  }

  Widget _pinBox(Key key, TextEditingController c, String label) => TextField(
        key: key,
        controller: c,
        obscureText: true,
        keyboardType: TextInputType.number,
        maxLength: 6,
        textAlign: TextAlign.center,
        decoration: InputDecoration(labelText: label, counterText: ''),
      );

  Widget _cover({required Key key, required List<Widget> children}) => Positioned.fill(
        child: Material(
          key: key,
          color: context.palette.surface,
          child: SafeArea(
            child: Center(
              child: SingleChildScrollView(
                padding: const EdgeInsets.all(AppSpacing.lg),
                child: Column(mainAxisSize: MainAxisSize.min, children: children),
              ),
            ),
          ),
        ),
      );

  @override
  Widget build(BuildContext context) {
    final lock = widget.lock;
    if (lock.locked && !_asked && !lock.asksPin && !lock.mustSignInAgain) {
      // ⓘ নিজে থেকে একবার চাওয়া — বোতাম চাপতে না হয়
      _asked = true;
      WidgetsBinding.instance.addPostFrameCallback((_) => lock.unlock());
    }
    return Stack(
      children: [
        // ⛔ তালার নিচের পর্দা স্ক্রিন-রিডারের কাছেও বন্ধ — আগে TalkBack চালু করে তালার নিচের "অনুমোদন" বোতামে পৌঁছে
        // দুইবার ছুঁয়ে সই দেওয়া যেত (ফোন লাইন-ধরে নিরীক্ষা, ১৫ অক্টোবর ২০২৬)
        ExcludeSemantics(excluding: lock.locked, child: widget.child),
        // ⭐ অ্যাপের PIN — ফোনে তালা নেই
        if (lock.asksPin)
          _cover(key: const ValueKey('app-pin-enter'), children: [
            const Icon(Icons.lock_outline, size: 56, color: AppColors.primary),
            const SizedBox(height: AppSpacing.md),
            Text(context.l10n.lockScreenEnterPin, style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w700)),
            const SizedBox(height: AppSpacing.md),
            _pinBox(const ValueKey('app-pin-field'), _pinField, 'PIN'),
            if (_pinError != null)
              Text(_pinError!, key: const ValueKey('app-pin-error'), style: TextStyle(color: context.palette.danger)),
            const SizedBox(height: AppSpacing.md),
            FilledButton(key: const ValueKey('app-pin-open'), onPressed: _enterPin, child: Text(context.l10n.commonOpen)),
            if (widget.onSignOut != null) TextButton(onPressed: widget.onSignOut, child: Text(context.l10n.lockScreenSignOut)),
          ]),
        // ⛔ ফোনে তালা নেই, PIN-ও নেই — আবার পাসওয়ার্ড
        if (lock.mustSignInAgain)
          _cover(key: const ValueKey('app-pin-sign-in-again'), children: [
            const Icon(Icons.lock_outline, size: 56, color: AppColors.primary),
            const SizedBox(height: AppSpacing.md),
            Text(context.l10n.lockScreenSignInAgainTitle, style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w700)),
            const SizedBox(height: AppSpacing.sm),
            Text(context.l10n.lockScreenSignInAgainBody, textAlign: TextAlign.center),
            const SizedBox(height: AppSpacing.md),
            if (widget.onSignOut != null)
              FilledButton(key: const ValueKey('app-pin-sign-out'), onPressed: widget.onSignOut, child: Text(context.l10n.lockScreenSignOutAndIn)),
          ]),
        // ⭐ এইমাত্র ঢুকেছেন — PIN বসানো, তবেই কাজ
        if (lock.needsNewPin)
          _cover(key: const ValueKey('app-pin-new'), children: [
            const Icon(Icons.password, size: 56, color: AppColors.primary),
            const SizedBox(height: AppSpacing.md),
            Text(context.l10n.lockScreenSetPinTitle, style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w700)),
            const SizedBox(height: AppSpacing.sm),
            Text(context.l10n.lockScreenSetPinBody(bnDigits(4), bnDigits(6)), textAlign: TextAlign.center),
            const SizedBox(height: AppSpacing.md),
            _pinBox(const ValueKey('app-pin-new-field'), _pinField, context.l10n.lockScreenNewPin),
            _pinBox(const ValueKey('app-pin-new-again'), _pinAgain, context.l10n.lockScreenPinAgain),
            if (_pinError != null)
              Text(_pinError!, key: const ValueKey('app-pin-error'), style: TextStyle(color: context.palette.danger)),
            const SizedBox(height: AppSpacing.md),
            FilledButton(key: const ValueKey('app-pin-save'), onPressed: _savePin, child: Text(context.l10n.lockScreenSave)),
            if (widget.onSignOut != null) TextButton(onPressed: widget.onSignOut, child: Text(context.l10n.lockScreenSignOut)),
          ]),
        if (lock.locked && !lock.asksPin && !lock.mustSignInAgain)
          Positioned.fill(
            child: Material(
              key: const ValueKey('app-lock'),
              color: context.palette.surface,
              child: SafeArea(
                child: Center(
                  child: Padding(
                    padding: const EdgeInsets.all(AppSpacing.lg),
                    child: Column(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        const Icon(Icons.lock_outline, size: 56, color: AppColors.primary),
                        const SizedBox(height: AppSpacing.md),
                        Text(context.l10n.lockScreenLockedTitle, style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w700)),
                        const SizedBox(height: AppSpacing.sm),
                        Text(context.l10n.lockScreenLockedBody, textAlign: TextAlign.center),
                        const SizedBox(height: AppSpacing.lg),
                        FilledButton.icon(
                          key: const ValueKey('app-lock-open'),
                          onPressed: lock.busy ? null : lock.unlock,
                          icon: const Icon(Icons.fingerprint),
                          label: Text(context.l10n.commonOpen),
                        ),
                        if (widget.onSignOut != null)
                          TextButton(onPressed: widget.onSignOut, child: Text(context.l10n.lockScreenSignOut)),
                      ],
                    ),
                  ),
                ),
              ),
            ),
          ),
      ],
    );
  }
}
