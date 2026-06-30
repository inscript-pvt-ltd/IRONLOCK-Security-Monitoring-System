import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

final networkStatusProvider = StreamProvider<bool>((ref) async* {
  // Emit the current state immediately so the UI is correct on launch
  // without waiting for a connectivity change event to fire.
  // On iOS simulator, onConnectivityChanged alone is unreliable for WiFi.
  final initial = await Connectivity().checkConnectivity();
  yield initial.any((r) => r != ConnectivityResult.none);

  // Then follow every future change.
  yield* Connectivity().onConnectivityChanged.map(
    (results) => results.any((r) => r != ConnectivityResult.none),
  );
});

final isOnlineProvider = Provider<bool>((ref) {
  return ref.watch(networkStatusProvider).maybeWhen(
    data: (online) => online,
    orElse: () => true, // assume online until proven otherwise
  );
});
