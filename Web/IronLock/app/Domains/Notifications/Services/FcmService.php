<?php

namespace App\Domains\Notifications\Services;

use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Exception\MessagingException;
use Kreait\Firebase\Messaging\AndroidConfig;
use Kreait\Firebase\Messaging\ApnsConfig;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;
use Illuminate\Support\Facades\Log;

/**
 * FcmService — Firebase Cloud Messaging delivery (Phase 5).
 *
 * Backed by the kreait/laravel-firebase package, which binds the
 * Kreait\Firebase\Contract\Messaging contract from config/firebase.php
 * (FIREBASE_CREDENTIALS). Messaging is resolved lazily and every send is
 * wrapped: a missing/invalid credentials file or an unreachable FCM endpoint
 * must never throw into the caller. Delivery is best-effort — the wakefulness
 * check / photo request already exists server-side and the timeout sweep is the
 * backstop — so a push failure returns false rather than blowing up the request.
 *
 * All payload values are coerced to strings: FCM data messages only carry
 * string key/value pairs.
 */
class FcmService
{
    private ?Messaging $messaging = null;
    private bool $resolved = false;

    /**
     * Lazily resolve the Messaging client. Returns null (and logs once) if the
     * SDK cannot be configured — e.g. the credentials file is absent in a dev
     * environment. Never throws.
     */
    private function messaging(): ?Messaging
    {
        if ($this->resolved) {
            return $this->messaging;
        }

        $this->resolved = true;

        try {
            $this->messaging = app(Messaging::class);
        } catch (\Throwable $e) {
            Log::warning('FcmService: messaging unavailable', ['reason' => $e->getMessage()]);
            $this->messaging = null;
        }

        return $this->messaging;
    }

    /**
     * Send a push notification to a specific device token. Returns true only on
     * a confirmed send.
     */
    public function sendToDevice(string $deviceToken, string $title, string $body, array $data = []): bool
    {
        if ($deviceToken === '') {
            return false;
        }

        $messaging = $this->messaging();
        if ($messaging === null) {
            return false;
        }

        try {
            // kreait/firebase-php v8: target a device with withToken() — the old
            // v7 CloudMessage::withTarget('token', ...) was removed (it threw and
            // was swallowed as a false "send failure", so pushes never left).
            $message = CloudMessage::new()
                ->withToken($deviceToken)
                ->withNotification(Notification::create($title, $body))
                ->withData($this->stringifyData($data))
                ->withAndroidConfig($this->androidConfig())
                ->withApnsConfig($this->apnsConfig());

            $messaging->send($message);

            return true;
        } catch (MessagingException $e) {
            Log::info('FcmService: send failed', ['reason' => $e->getMessage()]);
            return false;
        } catch (\Throwable $e) {
            Log::warning('FcmService: unexpected send error', ['reason' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Send a push notification to multiple device tokens.
     *
     * @return array{success_count: int, failure_count: int, results: array}
     */
    public function sendToMultipleDevices(array $deviceTokens, string $title, string $body, array $data = []): array
    {
        $success = 0;
        $failure = 0;
        $results = [];

        foreach ($deviceTokens as $token) {
            $ok = $this->sendToDevice((string) $token, $title, $body, $data);
            $ok ? $success++ : $failure++;
            $results[$token] = $ok;
        }

        return [
            'success_count' => $success,
            'failure_count' => $failure,
            'results' => $results,
        ];
    }

    /**
     * Android delivery config — wakefulness/photo challenges are time-critical
     * (a 60 s answer window), so push at HIGH priority and play the default
     * sound rather than letting the OS batch/defer a normal-priority message.
     */
    private function androidConfig(): AndroidConfig
    {
        return AndroidConfig::fromArray([
            'priority' => 'high',
            'notification' => ['sound' => 'default'],
        ]);
    }

    /**
     * APNs (iOS) delivery config. iOS routes through APNs once the Firebase
     * project has the APNs auth key uploaded (Firebase console — an ops step, no
     * code). `apns-priority: 10` delivers the alert immediately; `sound` + an
     * unread `badge` surface it. The data payload still rides along for the app's
     * handler (e.g. to fire the §6.2.1 delivery receipt).
     */
    private function apnsConfig(): ApnsConfig
    {
        return ApnsConfig::fromArray([
            'headers' => ['apns-priority' => '10'],
            'payload' => ['aps' => ['sound' => 'default', 'badge' => 1]],
        ]);
    }

    /**
     * FCM data payloads must be string→string maps. Booleans/numbers/arrays are
     * coerced so a caller can pass them without each call-site stringifying.
     */
    private function stringifyData(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if (is_bool($value)) {
                $out[$key] = $value ? 'true' : 'false';
            } elseif (is_array($value)) {
                $out[$key] = json_encode($value);
            } else {
                $out[$key] = (string) $value;
            }
        }
        return $out;
    }
}
