import 'package:flutter/material.dart';

import '../../core/theme/app_colors.dart';

/// Shown only while [AuthStatus.unknown] — the moment between the app
/// starting and [AuthController.restoreSession] answering whether a saved
/// session exists. Deliberately not a login form and not a home screen:
/// see app_router.dart's own redirect comment for why guessing either is
/// worse than a short, honest wait.
class SplashScreen extends StatelessWidget {
  const SplashScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return const Scaffold(
      backgroundColor: AppColors.primary,
      body: Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(
              'ABOS',
              style: TextStyle(
                color: AppColors.onPrimary,
                fontSize: 32,
                fontWeight: FontWeight.w700,
                letterSpacing: 1.2,
              ),
            ),
            SizedBox(height: 24),
            CircularProgressIndicator(color: AppColors.onPrimary),
          ],
        ),
      ),
    );
  }
}
