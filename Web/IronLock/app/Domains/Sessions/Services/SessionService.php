<?php

namespace App\Domains\Sessions\Services;

class SessionService
{
    /**
     * Create a new session for a guard.
     *
     * @param string $guardId
     * @param array $deviceInfo
     * @return array
     */
    public function createSession(string $guardId, array $deviceInfo): array
    {
        // Implementation will be added later
        return [
            'session_id' => '',
            'token' => '',
            'expires_at' => null
        ];
    }

    /**
     * Validate a session token.
     *
     * @param string $token
     * @return bool
     */
    public function validateToken(string $token): bool
    {
        // Implementation will be added later
        return false;
    }

    /**
     * Terminate a session.
     *
     * @param string $sessionId
     * @return bool
     */
    public function terminateSession(string $sessionId): bool
    {
        // Implementation will be added later
        return false;
    }
}