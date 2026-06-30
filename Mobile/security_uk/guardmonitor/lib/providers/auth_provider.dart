import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../models/auth_token_model.dart';
import '../models/guard_profile_model.dart';
import '../services/api_client.dart';
import '../services/auth_service.dart';
import '../services/shift_access_link.dart';
import '../services/gps_service.dart';
import '../services/notification_service.dart';
import '../services/push_messaging_service.dart';
import '../services/push_service.dart';
import '../services/secure_storage_service.dart';
import 'shift_provider.dart';
import 'wakefulness_provider.dart';

// ── Auth ──────────────────────────────────────────────────────────────────

enum AuthState { signedOut, signedIn }

class AuthNotifier extends AsyncNotifier<AuthState> {
  @override
  Future<AuthState> build() async {
    // Wire the JWT interceptor's terminal-refresh-failure callback to our
    // own signOut, so a dead refresh token routes the app back to Login
    // instead of leaving every subsequent request silently failing.
    ref.read(forcedSignOutCallbackProvider.notifier).set(signOut);

    // Read the stored token defensively. If secure storage itself errors on
    // startup, treat it as "not signed in" and show Login — never let build()
    // throw into AsyncError, which would route to Login anyway but via an
    // error state (audit L9). This keeps the startup path total.
    String? token;
    try {
      token = await SecureStorageService.getToken();
    } catch (_) {
      return AuthState.signedOut;
    }
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
    final token = await ref.read(authServiceProvider).login(identifier, password);
    await _persistSession(token, identifier: identifier, rememberMe: rememberMe);
  }

  /// Signs in by redeeming a Shift Access Link (SSO) token. The redeem response
  /// is identical to login, so it shares [_persistSession] entirely — the guard
  /// is already `checked_in` server-side, exactly like a password login. Throws
  /// a [ShiftAccessException] (mapped error code) on failure; the deep-link
  /// handler surfaces it on the login screen. Always "remembers" — a passwordless
  /// session would otherwise be unrecoverable after a token expiry.
  Future<void> signInWithShiftAccess(String token) async {
    final AuthTokenModel auth;
    try {
      auth = await ref.read(authServiceProvider).redeemShiftAccess(token);
    } on DioException catch (e) {
      throw ShiftAccessException.fromDio(e);
    }
    await _persistSession(auth, identifier: auth.guard?.email, rememberMe: true);
  }

  /// Shared post-auth wiring for both password login and SSO redeem: persist the
  /// session, hydrate the guard profile, flip to signed-in, kick off the shift
  /// fetch. [identifier] is the email/employee-code to remember + the display
  /// fallback (the typed identifier for login, the guard's email for SSO).
  Future<void> _persistSession(
    AuthTokenModel token, {
    required String? identifier,
    required bool rememberMe,
  }) async {
    await SecureStorageService.saveToken(token.accessToken);
    await SecureStorageService.saveExpiresAt(token.expiresAt);
    // The photo-signing key is session-scoped, not "remember me" data — it must
    // be available to sign uploads this session regardless of the checkbox, and
    // /me (the relaunch restore path) never returns it. Persist it whenever the
    // backend issues one; clearSession() wipes it on sign-out.
    if (token.hmacSecret != null) {
      await SecureStorageService.saveHmacSecret(token.hmacSecret!);
    }
    if (rememberMe && token.refreshToken != null) {
      await SecureStorageService.saveRefreshToken(token.refreshToken!);
    }
    if (rememberMe && identifier != null) {
      await SecureStorageService.saveEmail(identifier);
    }

    if (token.guard != null) {
      ref.read(guardProfileProvider.notifier).setFromApi(token.guard!);
    } else {
      // The response didn't embed the guard profile — fetch the real one from
      // /me rather than guessing a display name from the email. The email-derived
      // name is only a last resort if /me is also unavailable.
      try {
        final profile = await ref.read(authServiceProvider).getProfile();
        ref.read(guardProfileProvider.notifier).setFromApi(profile);
      } catch (_) {
        if (identifier != null) {
          ref.read(guardProfileProvider.notifier).setFromEmail(identifier);
        }
      }
    }

    state = const AsyncData(AuthState.signedIn);
    ref.read(currentShiftProvider.notifier).fetch();
  }

  Future<void> signOut() async {
    // Tear down shift-bound background work first. This matters most on a
    // FORCED sign-out (dead refresh token) while a shift is active, where the
    // UI's end()-then-signOut() path didn't run — without this the GPS timer
    // keeps firing and the "shift ended" reminder stays armed. Both calls are
    // idempotent, so they're harmless when nothing is running.
    ref.read(gpsServiceProvider).stopCapture();
    await NotificationService.cancelShiftEnd();
    // Stop pushes reaching this device for a now-signed-out guard: unmap the
    // token server-side (while it's still valid) then drop the local FCM token
    // so the next guard gets a fresh one (H5).
    try {
      await ref.read(pushServiceProvider).unregisterToken();
    } catch (_) {}
    await PushMessaging.clearToken();
    try {
      await ref.read(authServiceProvider).logout();
    } catch (_) {}
    await SecureStorageService.clearSession();
    ref.read(currentShiftProvider.notifier).clear();
    ref.invalidate(shiftProvider);
    ref.invalidate(wakefulnessScheduleProvider);
    // Reset the wakefulness FSM + its handled-check history so a different guard
    // signing in on this device starts clean.
    ref.invalidate(wakefulnessProvider);
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
    // Drop empty segments so a leading/trailing/doubled separator (e.g.
    // "_john.doe", "john..doe") can't make us index an empty string below.
    final parts =
        local.split(RegExp(r'[._\-]')).where((p) => p.isNotEmpty).toList();
    String name;
    String initials;
    if (parts.length >= 2) {
      name = '${_cap(parts[0])} ${_cap(parts[1])}';
      initials = '${parts[0][0]}${parts[1][0]}'.toUpperCase();
    } else if (parts.length == 1) {
      name = _cap(parts.first);
      initials =
          parts.first.substring(0, parts.first.length.clamp(0, 2)).toUpperCase();
    } else {
      // Nothing usable in the local part — fall back to a neutral placeholder.
      name = 'Guard';
      initials = 'G';
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
    // Guard against empty name fields from /me — indexing [0] on "" throws.
    final f = model.firstName.isNotEmpty ? model.firstName[0] : '';
    final l = model.lastName.isNotEmpty ? model.lastName[0] : '';
    final initials = (f + l).toUpperCase();
    state = GuardProfile(
      email: model.email,
      name: model.fullName,
      initials: initials.isEmpty ? 'G' : initials,
      employeeCode: model.employeeCode,
      siaLicenceNumber: model.siaLicenceNumber,
      siaLicenceExpiry: model.siaLicenceExpiry,
    );
  }
}

final guardProfileProvider =
    NotifierProvider<GuardProfileNotifier, GuardProfile>(GuardProfileNotifier.new);
