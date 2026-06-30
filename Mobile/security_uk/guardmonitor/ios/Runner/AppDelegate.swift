import Flutter
import UIKit

@main
@objc class AppDelegate: FlutterAppDelegate, FlutterImplicitEngineDelegate {
  /// Opaque cover laid over the window to keep sensitive content (precise
  /// location, the guard's photo, PII) out of screen captures — the iOS
  /// counterpart to Android's `FLAG_SECURE`. iOS has no API to fully block
  /// screenshots, so this covers the two cases it can: the app-switcher
  /// snapshot, and active screen recording / mirroring / screen-share
  /// (`UIScreen.isCaptured`, which includes ReplayKit broadcasts such as a
  /// Google Meet phone screen-share).
  private var secureCover: UIView?

  override func application(
    _ application: UIApplication,
    didFinishLaunchingWithOptions launchOptions: [UIApplication.LaunchOptionsKey: Any]?
  ) -> Bool {
    setupScreenCaptureProtection()
    return super.application(application, didFinishLaunchingWithOptions: launchOptions)
  }

  func didInitializeImplicitFlutterEngine(_ engineBridge: FlutterImplicitEngineBridge) {
    GeneratedPluginRegistrant.register(with: engineBridge.pluginRegistry)
  }

  // MARK: - Screen-capture protection (parity with Android FLAG_SECURE)

  private func setupScreenCaptureProtection() {
    let nc = NotificationCenter.default
    nc.addObserver(
      self, selector: #selector(handleWillResignActive),
      name: UIApplication.willResignActiveNotification, object: nil)
    nc.addObserver(
      self, selector: #selector(handleDidBecomeActive),
      name: UIApplication.didBecomeActiveNotification, object: nil)
    nc.addObserver(
      self, selector: #selector(handleCaptureChanged),
      name: UIScreen.capturedDidChangeNotification, object: nil)
    // Apply immediately in case recording/mirroring is already on at launch.
    handleCaptureChanged()
  }

  /// The app's currently visible window (scene-aware, with a fallback).
  private func activeWindow() -> UIWindow? {
    let sceneWindow = UIApplication.shared.connectedScenes
      .compactMap { $0 as? UIWindowScene }
      .flatMap { $0.windows }
      .first { $0.isKeyWindow }
    return sceneWindow ?? self.window
  }

  @objc private func handleWillResignActive() {
    // Cover the snapshot iOS takes for the app switcher.
    showSecureCover()
  }

  @objc private func handleDidBecomeActive() {
    // Back in the foreground — only reveal if the screen isn't being captured.
    if !UIScreen.main.isCaptured {
      hideSecureCover()
    }
  }

  @objc private func handleCaptureChanged() {
    if UIScreen.main.isCaptured {
      // Recording / mirroring / screen-share started — hide the content.
      showSecureCover()
    } else if UIApplication.shared.applicationState == .active {
      hideSecureCover()
    }
  }

  private func showSecureCover() {
    guard secureCover == nil, let window = activeWindow() else { return }

    let cover = UIView(frame: window.bounds)
    cover.autoresizingMask = [.flexibleWidth, .flexibleHeight]
    // Match the app's dark background (AppColors.bg #07111F).
    cover.backgroundColor = UIColor(
      red: 0x07 / 255.0, green: 0x11 / 255.0, blue: 0x1F / 255.0, alpha: 1.0)

    let label = UILabel()
    label.text = "Protected content"
    label.textColor = UIColor(white: 1.0, alpha: 0.6)
    label.font = UIFont.systemFont(ofSize: 15, weight: .medium)
    label.translatesAutoresizingMaskIntoConstraints = false
    cover.addSubview(label)
    NSLayoutConstraint.activate([
      label.centerXAnchor.constraint(equalTo: cover.centerXAnchor),
      label.centerYAnchor.constraint(equalTo: cover.centerYAnchor),
    ])

    window.addSubview(cover)
    window.bringSubviewToFront(cover)
    secureCover = cover
  }

  private func hideSecureCover() {
    secureCover?.removeFromSuperview()
    secureCover = nil
  }
}
