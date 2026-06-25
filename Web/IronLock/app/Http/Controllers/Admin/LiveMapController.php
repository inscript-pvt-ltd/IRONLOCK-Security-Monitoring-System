<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Domains\Sites\Models\Site;

/**
 * Live Map (D-08 · ADM-008 / GPS-006 / GPS-009).
 *
 * A full-screen operations map of every on-duty guard, status-coded by zone /
 * comms / unresponsive state, with a click-through guard side panel. The page
 * shell renders server-side and the markers refresh from the shared
 * `/admin/live-guards` endpoint every 15s (same polling source as the dashboard
 * mini-map — decision: polling, not WebSocket, for this phase).
 *
 * No breadcrumb trail / location history (roadmap §6.2): `guard_locations` keeps
 * only the latest fix per guard, so the map shows live positions only.
 */
class LiveMapController extends Controller
{
    /**
     * The Live Map page shell + the site filter options.
     */
    public function index()
    {
        return view('admin.live-map.index', [
            'sites' => Site::orderBy('name')->get(['id', 'name']),
        ]);
    }
}
