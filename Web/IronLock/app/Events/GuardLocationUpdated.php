<?php

namespace App\Events;

use App\Domains\GPS\Models\GuardLocation;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * GuardLocationUpdated — fired after each accepted GPS ping.
 *
 * Currently a plain in-process event. The dashboard reads live positions by
 * polling /admin/live-guards every 15s, so broadcasting is not yet required.
 * When Pusher is configured (php artisan install:broadcasting), promote this to
 * `implements ShouldBroadcast`, add `use InteractsWithSockets;` and uncomment
 * the methods below to push on the `ironlock.dashboard` channel.
 */
class GuardLocationUpdated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly string $guardId,
        public readonly string $shiftId,
        public readonly GuardLocation $location,
    ) {}

    // When Pusher is configured:
    //
    // public function broadcastOn(): array  { return [new Channel('ironlock.dashboard')]; }
    // public function broadcastAs(): string { return 'guard.location.updated'; }
    // public function broadcastWith(): array {
    //     return [
    //         'guard_id'      => $this->guardId,
    //         'shift_id'      => $this->shiftId,
    //         'latitude'      => (float) $this->location->latitude,
    //         'longitude'     => (float) $this->location->longitude,
    //         'zone_status'   => $this->location->zone_status,
    //         'battery_level' => $this->location->battery_level,
    //         'updated_at'    => $this->location->updated_at?->toISOString(),
    //     ];
    // }
}
