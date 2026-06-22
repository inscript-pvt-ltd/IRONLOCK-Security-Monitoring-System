import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../models/guard_profile_model.dart';
import '../services/api_client.dart';
import '../services/auth_service.dart';
import '../services/secure_storage_service.dart';
import 'shift_provider.dart';

// ── Auth ──────────────────────────────────────────────────────────────────

enum AuthState { signedOut, signedIn }

class AuthNotifier extends AsyncNotifier<AuthState> {
  @override
  Future<AuthState> build() async {
    // Wire the JWT interceptor's terminal-refresh-failure callback to our
    // own signOut, so a dead refresh token routes the app back to Login
    // instead of leaving every subsequent request silently failing.
    ref.read(forcedSignOutCallbackProvider.notifier).set(signOut);

    final token = await SecureStorageService.getToken();
    if (token != null) {
      // Restore profile from API on startup; fall back to saved email.
      try {
        final profile = await ref.read(authServiceProvider).getProfile();
        ref.read(guardProfileProvider.notifier).setFromApi(profile);
      } catch (_) {
        final email = await SecureStorageService.getSavedEmail();
        if (email != null) {
          ref.read(guardProfileProvider.notifier).setFromEmail(email);
        }
      }
      ref.read(currentShiftProvider.notifier).fetch();
      return AuthState.signedIn;
    }
    return AuthState.signedOut;
  }

  Future<void> signIn(
    String identifier,
    String password, {
    bool rememberMe = true,
  }) async {
    final authService = ref.read(authServiceProvider);
    final token = await authService.login(identifier, password);
    await SecureStorageService.saveToken(token.accessToken);
    await SecureStorageService.saveExpiresAt(token.expiresAt);
    if (rememberMe && token.refreshToken != null) {
      await SecureStorageService.saveRefreshToken(token.refreshToken!);
    }
    if (rememberMe) {
      await SecureStorageService.saveEmail(identifier);
    }

    if (token.guard != null) {
      ref.read(guardProfileProvider.notifier).setFromApi(token.guard!);
    } else {
      // The login response didn't embed the guard profile — fetch the real one
      // from /me rather than guessing a display name from the email. The
      // email-derived name is only a last resort if /me is also unavailable.
      try {
        final profile = await authService.getProfile();
        ref.read(guardProfileProvider.notifier).setFromApi(profile);
      } catch (_) {
        ref.read(guardProfileProvider.notifier).setFromEmail(identifier);
      }
    }

    state = const AsyncData(AuthState.signedIn);
    ref.read(currentShiftProvider.notifier).fetch();
  }

  Future<void> signOut() async {
    try {
      await ref.read(authServiceProvider).logout();
    } catch (_) {}
    await SecureStorageService.clearSession();
    ref.read(currentShiftProvider.notifier).clear();
    ref.invalidate(shiftProvider);
    state = const AsyncData(AuthState.signedOut);
  }
}

final authProvider =
    AsyncNotifierProvider<AuthNotifier, AuthState>(AuthNotifier.new);

// ── Guard profile ─────────────────────────────────────────────────────────

class GuardProfile {
  const GuardProfile({
    required this.email,
    required this.name,
    required this.initials,
    this.employeeCode,
    this.siaLicenceNumber,
    this.siaLicenceExpiry,
  });

  final String email;
  final String name;
  final String initials;
  final String? employeeCode;
  final String? siaLicenceNumber;
  final String? siaLicenceExpiry;

  static GuardProfile fromEmail(String email) {
    final local = email.split('@').first;
    final parts = local.split(RegExp(r'[._\-]'));
    String name;
    String initials;
    if (parts.length >= 2) {
      name = '${_cap(parts[0])} ${_cap(parts[1])}';
      initials = '${parts[0][0]}${parts[1][0]}'.toUpperCase();
    } else {
      name = _cap(local);
      initials = local.substring(0, local.length.clamp(0, 2)).toUpperCase();
    }
    return GuardProfile(email: email, name: name, initials: initials);
  }

  static String _cap(String s) =>
      s.isEmpty ? s : '${s[0].toUpperCase()}${s.substring(1)}';
}

class GuardProfileNotifier extends Notifier<GuardProfile> {
  // Neutral placeholder shown only in the brief window before the real profile
  // is restored from /me (or derived from the signed-in email). Deliberately
  // not a specific demo identity so a stale name can never be shown as real.
  @override
  GuardProfile build() => const GuardProfile(email: '', name: 'Guard', initials: 'G');

  void setFromEmail(String email) =>
      state = GuardProfile.fromEmail(email);

  void setFromApi(GuardProfileModel model) {
    final initials = '${model.firstName[0]}${model.lastName[0]}'.toUpperCase();
    state = GuardProfile(
      email: model.email,
      name: model.fullName,
      initials: initials,
      employeeCode: model.employeeCode,
      siaLicenceNumber: model.siaLicenceNumber,
      siaLicenceExpiry: model.siaLicenceExpiry,
    );
  }
}

final guardProfileProvider =
    NotifierProvider<GuardProfileNotifier, GuardProfile>(GuardProfileNotifier.new);
