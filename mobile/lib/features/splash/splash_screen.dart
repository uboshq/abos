import 'package:flutter/material.dart';

import '../../core/widgets/live_logo.dart';

/// Shown only while [AuthStatus.unknown] — the moment between the app
/// starting and [AuthController.restoreSession] answering whether a saved
/// session exists. Deliberately not a login form and not a home screen:
/// see app_router.dart's own redirect comment for why guessing either is
/// worse than a short, honest wait.
class SplashScreen extends StatelessWidget {
  const SplashScreen({super.key});

  @override
  Widget build(BuildContext context) {
    // ⭐ লোড হওয়ার সময় পুরো জীবন্ত লোগো, সাদা জমিনে (মালিক, ১ নভেম্বর ২০২৬: "loding eo Abos er full logo bosbe")
    return const Scaffold(
      backgroundColor: Color(0xFFEEF5F7),
      body: Center(child: LiveLogo(key: ValueKey('splash-live-logo'), width: 220)),
    );
  }
}
