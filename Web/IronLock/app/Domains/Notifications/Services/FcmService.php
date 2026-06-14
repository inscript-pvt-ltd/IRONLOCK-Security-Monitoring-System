<?php

namespace App\Domains\Notifications\Services;

use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging;

class FcmService
{
    private Messaging $messaging;

    public function __construct()
    {
        $firebase = (new Factory)
            ->withServiceAccount(storage_path('app/firebase/ironlock-firebase-adminsdk.json'));

        $this->messaging = $firebase->createMessaging();
    }

    /**
     * Send a push notification to a specific device token.
     *
     * @param string $deviceToken
     * @param string $title
     * @param string $body
     * @param array $data
     * @return bool
     */
    public function sendToDevice(string $deviceToken, string $title, string $body, array $data = []): bool
    {
        // Implementation will be added later
        return false;
    }

    /**
     * Send a push notification to multiple device tokens.
     *
     * @param array $deviceTokens
     * @param string $title
     * @param string $body
     * @param array $data
     * @return array
     */
    public function sendToMultipleDevices(array $deviceTokens, string $title, string $body, array $data = []): array
    {
        // Implementation will be added later
        return [
            'success_count' => 0,
            'failure_count' => 0,
            'results' => []
        ];
    }

    /**
     * Send a notification to guards of a specific site.
     *
     * @param string $siteId
     * @param string $title
     * @param string $body
     * @param array $data
     * @return array
     */
    public function sendToSite(string $siteId, string $title, string $body, array $data = []): array
    {
        // Implementation will be added later
        return [
            'success_count' => 0,
            'failure_count' => 0,
            'results' => []
        ];
    }
}