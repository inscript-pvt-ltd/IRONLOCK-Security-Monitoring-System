<?php

namespace App\Http\Controllers\Admin;

use App\Domains\Sites\Models\Site;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * SiteController - Manages physical security sites with GPS coordinates
 *
 * Provides CRUD operations for sites where guards are deployed:
 * - Site creation with GPS coordinate validation
 * - Interactive map integration for site positioning
 * - Grace period configuration for zone exit tolerance
 * - Contact information and site-specific instructions
 */
class SiteController extends Controller
{
    /**
     * Display a listing of all sites.
     */
    public function index(Request $request)
    {
        $sites = Site::notArchived()
        ->with(['creator', 'geofences'])
        // Count shifts currently in progress per site — used to lock geofence
        // editing while a guard is on duty (the live geofence must not change
        // mid-shift). Mirrors the server-side guard in GeofenceController.
        ->withCount(['shifts as active_shifts_count' => function ($query) {
            $query->where('status', \App\Domains\Shifts\Models\Shift::STATUS_ACTIVE);
        }])
        ->when($request->get('status'), function($query, $status) {
            $query->where('status', $status);
        })
        ->when($request->get('search'), function($query, $search) {
            $query->where(function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('address', 'like', "%{$search}%");
            });
        })
        ->orderBy('created_at', 'desc')
        ->paginate(15);

        // Load coordinates for each geofence. The geometry is always a polygon, but
        // expose the original shape so circles reload as circles (and keep their radius).
        foreach ($sites as $site) {
            // Boolean flag the Sites page reads to disable the radius/geofence
            // tools while a shift is active at this site.
            $site->has_active_shift = $site->active_shifts_count > 0;

            foreach ($site->geofences as $geofence) {
                $geofence->coordinates = $geofence->getPolygonCoordinates();
                $geofence->type = $geofence->shape_type ?? 'polygon';
            }
        }

        return view('admin.sites.index', compact('sites'));
    }

    /**
     * Store a newly created site.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:sites,name',
            'address' => 'required|string|max:500',
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'grace_period_minutes' => 'nullable|integer|min:1|max:30',
            'contact_person' => 'nullable|string|max:255',
            'contact_phone' => 'nullable|string|max:20',
            'status' => 'nullable|in:active,inactive',
            'instructions' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $site = Site::create([
                'name' => $request->name,
                'address' => $request->address,
                'latitude' => $request->latitude,
                'longitude' => $request->longitude,
                'grace_period_minutes' => $request->input('grace_period_minutes', Site::DEFAULT_GRACE_PERIOD_MINUTES),
                'contact_person' => $request->contact_person,
                'contact_phone' => $request->contact_phone,
                'instructions' => $request->instructions,
                'status' => $request->input('status', 'active'),
                'created_by' => $this->currentAdminId(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Site created successfully',
                'site' => $site->load('creator')
            ]);
        } catch (\Exception $e) {
            Log::error('Error creating site', ['exception' => $e]);
            return response()->json([
                'success' => false,
                'error' => 'Unable to create site. Please try again.'
            ], 500);
        }
    }

    /**
     * Display the specified site.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        try {
            $site = Site::with(['creator', 'geofences' => function($query) {
                $query->where('is_active', true);
            }, 'shifts' => function($query) {
                $query->latest()->take(5);
            }])->findOrFail($id);

            return response()->json([
                'success' => true,
                'site' => $site
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Site not found'
            ], 404);
        }
    }

    /**
     * Update the specified site.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        try {
            $site = Site::findOrFail($id);

            $validator = Validator::make($request->all(), [
                'name' => [
                    'required',
                    'string',
                    'max:255',
                    Rule::unique('sites')->ignore($site->id)
                ],
                'address' => 'required|string|max:500',
                'latitude' => 'required|numeric|between:-90,90',
                'longitude' => 'required|numeric|between:-180,180',
                'grace_period_minutes' => 'nullable|integer|min:1|max:30',
                'contact_person' => 'nullable|string|max:255',
                'contact_phone' => 'nullable|string|max:20',
                'status' => 'nullable|in:active,inactive',
                'instructions' => 'nullable|string|max:1000',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors()
                ], 422);
            }

            $site->update([
                'name' => $request->name,
                'address' => $request->address,
                'latitude' => $request->latitude,
                'longitude' => $request->longitude,
                'grace_period_minutes' => $request->input('grace_period_minutes', $site->grace_period_minutes),
                'contact_person' => $request->contact_person,
                'contact_phone' => $request->contact_phone,
                'instructions' => $request->instructions,
                'status' => $request->input('status', $site->status),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Site updated successfully',
                'site' => $site->load('creator')
            ]);
        } catch (\Exception $e) {
            Log::error('Error updating site', ['site_id' => $id, 'exception' => $e]);
            return response()->json([
                'success' => false,
                'error' => 'Unable to update site. Please try again.'
            ], 500);
        }
    }

    /**
     * Remove (archive) the specified site.
     *
     * This is a data-preserving soft-delete, NOT a hard delete: the row is kept
     * (archived_at stamped) so historical shifts and their events/alerts/reports
     * still resolve the real site. Archiving only hides the site from the admin
     * list and the new-shift picker. Reversible (clear archived_at to restore).
     */
    public function destroy(string $id): JsonResponse
    {
        try {
            $site = Site::findOrFail($id);

            if ($site->isArchived()) {
                return response()->json([
                    'success' => false,
                    'error' => 'This site has already been removed.'
                ], 422);
            }

            return DB::transaction(function () use ($site) {
                // Cannot archive a site that still has shifts needing it live for
                // monitoring — those must be completed or cancelled first.
                $activeShifts = $site->shifts()
                    ->whereIn('status', ['scheduled', 'active'])
                    ->count();

                if ($activeShifts > 0) {
                    return response()->json([
                        'success' => false,
                        'error' => "Cannot remove site with {$activeShifts} active or scheduled shifts. Please complete or cancel all shifts first."
                    ], 422);
                }

                // Archive in place — the geofence stays attached to the (now
                // hidden) site; no need to tear it down since the site can no
                // longer be scheduled and can be restored intact later.
                //
                // Set by direct assignment (not ->update([...])): archived_at is
                // deliberately kept out of $fillable so it can never be set from a
                // request payload, and mass-assignment would silently drop it.
                $site->archived_at = now();
                $site->save();

                return response()->json([
                    'success' => true,
                    'message' => 'Site removed successfully. Its records are preserved for historical shifts.'
                ]);
            });
        } catch (\Exception $e) {
            Log::error('Error removing site', ['site_id' => $id, 'exception' => $e]);
            return response()->json([
                'success' => false,
                'error' => 'Unable to remove site. Please try again.'
            ], 500);
        }
    }

    /**
     * Toggle site status (active/inactive).
     */
    public function toggleStatus(Request $request, string $id): JsonResponse
    {
        try {
            $site = Site::findOrFail($id);

            $newStatus = $site->status === Site::STATUS_ACTIVE
                ? Site::STATUS_INACTIVE
                : Site::STATUS_ACTIVE;

            $site->update(['status' => $newStatus]);

            return response()->json([
                'success' => true,
                'message' => "Site {$newStatus} successfully",
                'site' => $site
            ]);
        } catch (\Exception $e) {
            Log::error('Error updating site status', ['site_id' => $id, 'exception' => $e]);
            return response()->json([
                'success' => false,
                'error' => 'Unable to update site status. Please try again.'
            ], 500);
        }
    }

    /**
     * Get sites list for dropdowns/selects.
     */
    public function list(): JsonResponse
    {
        return $this->getActiveSites();
    }

    /**
     * Get sites list for dropdown/select components.
     */
    public function getActiveSites(): JsonResponse
    {
        try {
            $sites = Site::active()
                ->notArchived()
                ->select('id', 'name', 'address')
                ->orderBy('name')
                ->get();

            return response()->json([
                'success' => true,
                'sites' => $sites
            ]);
        } catch (\Exception $e) {
            Log::error('Error loading sites', ['exception' => $e]);
            return response()->json([
                'success' => false,
                'error' => 'Unable to load sites. Please try again.'
            ], 500);
        }
    }
}
