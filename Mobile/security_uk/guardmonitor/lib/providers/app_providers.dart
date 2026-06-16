import 'dart:math';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../models/current_shift_model.dart';
import '../models/guard_profile_model.dart';
import '../services/api_client.dart';
import '../services/auth_service.dart';
import '../services/gps_service.dart';
import '../services/secure_storage_service.dart';
import '../services/shift_service.dart';

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

  Future<void> signIn(String identifier, String password) async {
    final authService = ref.read(authServiceProvider);
    final token = await authService.login(identifier, password);
    await SecureStorageService.saveToken(token.accessToken);
    if (token.refreshToken != null) {
      await SecureStorageService.saveRefreshToken(token.refreshToken!);
    }
    await SecureStorageService.saveExpiresAt(token.expiresAt);
    await SecureStorageService.saveEmail(identifier);

    if (token.guard != null) {
      ref.read(guardProfileProvider.notifier).setFromApi(token.guard!);
    } else {
      ref.read(guardProfileProvider.notifier).setFromEmail(identifier);
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
  @override
  GuardProfile build() => GuardProfile.fromEmail('j.smith@ironlock.co.uk');

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

// ── Current shift (server source of truth — id, window, can_start/can_end) ─

class CurrentShiftNotifier extends Notifier<CurrentShiftModel?> {
  @override
  CurrentShiftModel? build() => null;

  Future<void> fetch() async {
    try {
      state = await ref.read(shiftServiceProvider).fetchCurrent();
    } catch (_) {
      // Not critical — guard can still see the cached state; next poll/resume retries.
    }
  }

  /// Calls `POST /shifts/{id}/start`. Throws [DioException] on
  /// `409 SHIFT_NOT_STARTABLE` etc. so the caller can surface the error.
  Future<CurrentShiftModel> start() async {
    final current = state;
    if (current == null) {
      throw StateError('No current shift to start.');
    }
    final updated = await ref.read(shiftServiceProvider).startShift(current.id);
    state = updated;
    return updated;
  }

  /// Calls `POST /shifts/{id}/end`. Throws [DioException] on
  /// `409 SHIFT_NOT_ENDABLE` etc. so the caller can surface the error.
  Future<CurrentShiftModel> end() async {
    final current = state;
    if (current == null) {
      throw StateError('No current shift to end.');
    }
    final updated = await ref.read(shiftServiceProvider).endShift(current.id);
    state = updated;
    return updated;
  }

  void clear() => state = null;
}

final currentShiftProvider =
    NotifierProvider<CurrentShiftNotifier, CurrentShiftModel?>(CurrentShiftNotifier.new);

// ── Shift (in-progress bookkeeping: elapsed time, welfare/photo counters) ──

class ShiftState {
  const ShiftState({
    this.active = false,
    this.startTime,
    this.id,
    this.shiftRef,
    this.welfareChecksTotal = 0,
    this.welfareChecksPassed = 0,
    this.photosTotal = 0,
    this.photosPassed = 0,
  });
  final bool active;
  final DateTime? startTime;
  final String? id;       // server-assigned shift ID
  final String? shiftRef; // display ref e.g. '#SH-2847'
  final int welfareChecksTotal;
  final int welfareChecksPassed;
  final int photosTotal;
  final int photosPassed;

  ShiftState copyWith({
    bool? active,
    DateTime? startTime,
    String? id,
    String? shiftRef,
    int? welfareChecksTotal,
    int? welfareChecksPassed,
    int? photosTotal,
    int? photosPassed,
  }) {
    return ShiftState(
      active: active ?? this.active,
      startTime: startTime ?? this.startTime,
      id: id ?? this.id,
      shiftRef: shiftRef ?? this.shiftRef,
      welfareChecksTotal: welfareChecksTotal ?? this.welfareChecksTotal,
      welfareChecksPassed: welfareChecksPassed ?? this.welfareChecksPassed,
      photosTotal: photosTotal ?? this.photosTotal,
      photosPassed: photosPassed ?? this.photosPassed,
    );
  }
}

class ShiftNotifier extends Notifier<ShiftState> {
  @override
  ShiftState build() => const ShiftState();

  /// The server decides whether a shift can start (`can_start`) — this calls
  /// straight through to the API and only updates local state on success,
  /// letting `SHIFT_NOT_STARTABLE` etc. propagate to the caller.
  Future<void> start() async {
    final updated = await ref.read(currentShiftProvider.notifier).start();
    state = ShiftState(
      active: true,
      startTime: updated.actualStart ?? DateTime.now(),
      id: updated.id,
      shiftRef: updated.displayRef,
    );
    ref.read(gpsServiceProvider).startCapture(updated.id);
    _generateNoncePool();
  }

  Future<void> end() async {
    final shiftId = state.id;
    ref.read(gpsServiceProvider).stopCapture();
    state = const ShiftState();

    if (shiftId != null) {
      try {
        await ref.read(currentShiftProvider.notifier).end();
      } catch (_) {}
    }
  }

  void recordWelfareCheck({required bool passed}) {
    state = state.copyWith(
      welfareChecksTotal: state.welfareChecksTotal + 1,
      welfareChecksPassed: passed
          ? state.welfareChecksPassed + 1
          : state.welfareChecksPassed,
    );
  }

  void recordPhoto({required bool passed}) {
    state = state.copyWith(
      photosTotal: state.photosTotal + 1,
      photosPassed: passed ? state.photosPassed + 1 : state.photosPassed,
    );
  }

  /// No nonce-issuing endpoint exists in the contract (gap flagged to the
  /// backend dev) — nonces are generated client-side instead of fetched.
  void _generateNoncePool() {
    final random = Random.secure();
    final nonces = List.generate(15, (_) {
      final bytes = List<int>.generate(16, (_) => random.nextInt(256));
      return bytes.map((b) => b.toRadixString(16).padLeft(2, '0')).join();
    });
    ref.read(noncePoolProvider.notifier).load(nonces);
  }
}

final shiftProvider =
    NotifierProvider<ShiftNotifier, ShiftState>(ShiftNotifier.new);

// ── Zone ─────────────────────────────────────────────────────────────────
// 0 = inside, 1 = outside, 2 = no signal

class ZoneNotifier extends Notifier<int> {
  @override
  int build() => 0;

  void set(int state) => this.state = state.clamp(0, 2);
  void cycle() => state = (state + 1) % 3;
}

final zoneProvider = NotifierProvider<ZoneNotifier, int>(ZoneNotifier.new);

// ── Battery ───────────────────────────────────────────────────────────────

class BatteryNotifier extends Notifier<double> {
  @override
  double build() => 72.0;

  void set(double pct) => state = pct.clamp(0, 100);
  void tick() => state = (state - 0.02).clamp(0, 100);
}

final batteryProvider =
    NotifierProvider<BatteryNotifier, double>(BatteryNotifier.new);

// ── Privacy accepted ──────────────────────────────────────────────────────

class PrivacyNotifier extends Notifier<bool> {
  @override
  bool build() => false;

  void accept() => state = true;
}

final privacyAcceptedProvider =
    NotifierProvider<PrivacyNotifier, bool>(PrivacyNotifier.new);

// ── Photo verification ────────────────────────────────────────────────────

enum PhotoStatus { idle, capturing, uploading, validated, flagged, failed, expired }

class PhotoState {
  const PhotoState({
    this.status = PhotoStatus.idle,
    this.secondsRemaining = 78,
    this.expireCountdown = 30,
  });
  final PhotoStatus status;
  final int secondsRemaining;
  final int expireCountdown;

  PhotoState copyWith({
    PhotoStatus? status,
    int? secondsRemaining,
    int? expireCountdown,
  }) =>
      PhotoState(
        status: status ?? this.status,
        secondsRemaining: secondsRemaining ?? this.secondsRemaining,
        expireCountdown: expireCountdown ?? this.expireCountdown,
      );
}

class PhotoNotifier extends Notifier<PhotoState> {
  @override
  PhotoState build() => const PhotoState();

  void tick() {
    final s = state;
    if (s.status == PhotoStatus.idle || s.status == PhotoStatus.capturing) {
      final remaining = s.secondsRemaining - 1;
      if (remaining <= 0) {
        state = s.copyWith(status: PhotoStatus.expired, secondsRemaining: 0);
      } else {
        state = s.copyWith(secondsRemaining: remaining);
      }
    } else if (s.status == PhotoStatus.expired) {
      final cd = s.expireCountdown - 1;
      if (cd <= 0) {
        state = const PhotoState();
      } else {
        state = s.copyWith(expireCountdown: cd);
      }
    }
  }

  void capture() => state = state.copyWith(status: PhotoStatus.uploading);

  void setResult(PhotoStatus result) => state = state.copyWith(status: result);

  void tryAgain() => state = const PhotoState();
}

final photoProvider =
    NotifierProvider<PhotoNotifier, PhotoState>(PhotoNotifier.new);

// ── Active tab index ──────────────────────────────────────────────────────

class ActiveTabNotifier extends Notifier<int> {
  @override
  int build() => 0;

  void setTab(int index) => state = index;
}

final activeTabProvider =
    NotifierProvider<ActiveTabNotifier, int>(ActiveTabNotifier.new);

// ── Pending backend-triggered photo request ───────────────────────────────

class PendingPhotoState {
  const PendingPhotoState({this.pending = false, this.requestId});
  final bool pending;
  final String? requestId;
}

class PendingPhotoNotifier extends Notifier<PendingPhotoState> {
  @override
  PendingPhotoState build() => const PendingPhotoState();

  void setPending(bool val, {String? requestId}) =>
      state = PendingPhotoState(pending: val, requestId: requestId);
}

final pendingPhotoProvider =
    NotifierProvider<PendingPhotoNotifier, PendingPhotoState>(PendingPhotoNotifier.new);

// ── Nonce pool (generated locally at shift start, consumed per photo) ─────

class NoncePoolNotifier extends Notifier<List<String>> {
  @override
  List<String> build() => const [];

  void load(List<String> nonces) => state = List.unmodifiable(nonces);

  String? consume() {
    if (state.isEmpty) return null;
    final nonce = state.first;
    state = List.unmodifiable(state.sublist(1));
    return nonce;
  }

  bool get isEmpty => state.isEmpty;
}

final noncePoolProvider =
    NotifierProvider<NoncePoolNotifier, List<String>>(NoncePoolNotifier.new);
