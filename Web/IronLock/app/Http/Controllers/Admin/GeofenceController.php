<?php

namespace App\Http\Controllers\Admin;

use App\Domains\Geofences\Models\Geofence;
use App\Domains\Sites\Models\Site;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * GeofenceController - Manages polygon boundaries with MySQL spatial validation
 *
 * Provides operations for virtual boundaries around sites:
 * - Polygon creation and editing with coordinate validation
 * - MySQL spatial ST_CONTAINS point-in-polygon testing
 * - Interactive map integration for boundary drawing
 * - Version control and active/inactive status management
 */
class GeofenceController extends Controller
{
    /**
     * Display geofences for a specific site.
     */
    public function index(Request $request, string $siteId): JsonResponse
    {
        try {
            $site = Site::findOrFail($siteId);

            $geofences = Geofence::where('site_id', $siteId)
                ->with(['creator'])
                ->when($request->get('active_only'), function($query) {
                    $query->where('is_active', true);
                })
                ->orderBy('version', 'desc')
                ->get();

            // Add coordinate arrays for frontend display, exposing the original
            // shape so circles reload as resizable circles rather than polygons.
            $geofences->each(function($geofence) {
                $geofence->coordinates = $geofence->getPolygonCoordinates();
                $geofence->type = $geofence->shape_type ?? 'polygon';
            });

            return response()->json([
                'success' => true,
                'site' => $site,
                'geofences' => $geofences
            ]);
        } catch (\Exception $e) {
            Log::error('Error loading geofences', ['site_id' => $siteId, 'exception' => $e]);
            return response()->json([
                'success' => false,
                'error' => 'Unable to load geofences. Please try again.'
            ], 500);
        }
    }

    /**
     * Store a new geofence (polygon or circle).
     */
    public function store(Request $request): JsonResponse
    {
        // Handle coordinates that come as JSON string
        $coordinates = $request->get('coordinates');
        if (is_string($coordinates)) {
            $coordinates = json_decode($coordinates, true);
        }

        $validator = Validator::make(array_merge($request->all(), ['coordinates' => $coordinates]), [
            'site_id' => 'required|exists:sites,id',
            'type' => 'required|in:polygon,circle',
            'coordinates' => 'required',
            'is_active' => 'boolean'
        ]);

        // Additional validation based on type
        if ($request->get('type') === 'polygon') {
            $validator->sometimes('coordinates', 'array|min:3', function($input) {
                return $input->type === 'polygon';
            });
            $validator->sometimes('coordinates.*', 'array|size:2', function($input) {
                return $input->type === 'polygon';
            });
            // Each polygon vertex element must be a real number — without this a
            // non-numeric value slips through to the WKT string and produces a
            // raw MySQL parse error (500) instead of a clean 422.
            $validator->sometimes('coordinates.*.*', 'numeric', function($input) {
                return $input->type === 'polygon';
            });
        } else if ($request->get('type') === 'circle') {
            $validator->sometimes('coordinates.center', 'required|array|size:2', function($input) {
                return $input->type === 'circle';
            });
            $validator->sometimes('coordinates.center.*', 'numeric', function($input) {
                return $input->type === 'circle';
            });
            $validator->sometimes('coordinates.radius', 'required|numeric|min:1', function($input) {
                return $input->type === 'circle';
            });
        }

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();

        try {
            $site = Site::findOrFail($request->site_id);

            // Lock geofence changes while a shift is in progress at this site —
            // the live geofence drives zone monitoring and must not change under
            // an on-duty guard. (The Sites UI also disables the tools, but this
            // is the authoritative guard.)
            $activeShifts = DB::table('shifts')
                ->where('site_id', $site->id)
                ->where('status', 'active')
                ->count();

            if ($activeShifts > 0) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'error' => "Can't change this site's geofence while a shift is active here. You can edit it once the shift ends."
                ], 422);
            }

            // Get next version number for this site
            $nextVersion = Geofence::where('site_id', $site->id)->max('version') + 1;

            // If this is set as active, deactivate other geofences for this site
            if ($request->boolean('is_active', true)) {
                Geofence::where('site_id', $site->id)->update(['is_active' => false]);
            }

            // Generate UUID for the geofence
            $geofenceId = \Illuminate\Support\Str::uuid()->toString();

            // Create WKT geometry string based on type. We always store a POLYGON
            // (so ST_CONTAINS works), but remember the original shape + radius so a
            // circle can be reloaded and resized rather than becoming a dumb polygon.
            $wkt = '';
            $name = $request->get('name', $site->name . ' Geofence');
            $shapeType = $request->get('type');
            $radiusValue = null;

            if ($shapeType === 'circle') {
                // Convert circle to polygon approximation
                $center = $coordinates['center'];
                $radiusValue = (int) round($coordinates['radius']);
                $polygonCoords = $this->circleToPolygon($center[0], $center[1], $radiusValue);
                $wkt = Geofence::createPolygonFromCoordinates($polygonCoords);
                $name = $name . ' (Circle ' . $radiusValue . 'm)';
            } else {
                $wkt = Geofence::createPolygonFromCoordinates($coordinates);
                $name = $name . ' (Polygon)';
            }

            // Create geofence record with polygon in one operation using raw SQL
            $adminId = $this->currentAdminId();
            $now = now();

            DB::statement(
                "INSERT INTO geofences (id, site_id, name, shape_type, radius, polygon, version, is_active, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ST_GeomFromText(?, 4326), ?, ?, ?, ?, ?)",
                [
                    $geofenceId,
                    $site->id,
                    $name,
                    $shapeType,
                    $radiusValue,
                    $wkt,
                    $nextVersion,
                    $request->boolean('is_active', true) ? 1 : 0,
                    $adminId,
                    $now,
                    $now
                ]
            );

            // Load the created geofence
            $geofence = Geofence::find($geofenceId);

            DB::commit();

            // Reload with coordinates for response
            $geofence->load('creator', 'site');
            $geofence->coordinates = $geofence->getPolygonCoordinates();

            return response()->json([
                'success' => true,
                'message' => 'Geofence created successfully',
                'geofence' => $geofence
            ]);
        } catch (\Exception $e) {
            DB::rollback();

            Log::error('Geofence creation failed', [
                'exception' => $e,
                'request' => $request->all()
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Unable to create geofence. Please try again.'
            ], 500);
        }
    }

    /**
     * Convert a circle to a polygon approximation.
     */
    private function circleToPolygon(float $lat, float $lng, float $radius, int $points = 32): array
    {
        $polygon = [];
        $earthRadius = 6371000; // Earth's radius in meters

        for ($i = 0; $i < $points; $i++) {
            $angle = (2 * M_PI * $i) / $points;

            // Calculate offset in meters
            $dx = $radius * cos($angle);
            $dy = $radius * sin($angle);

            // Convert to lat/lng offset
            $deltaLat = $dy / $earthRadius * (180 / M_PI);
            $deltaLng = $dx / ($earthRadius * cos($lat * M_PI / 180)) * (180 / M_PI);

            $polygon[] = [$lat + $deltaLat, $lng + $deltaLng];
        }

        return $polygon;
    }

    /**
     * Display the specified geofence.
     */
    public function show(string $id): JsonResponse
    {
        try {
            $geofence = Geofence::with(['site', 'creator'])->findOrFail($id);
            $geofence->coordinates = $geofence->getPolygonCoordinates();

            return response()->json([
                'success' => true,
                'geofence' => $geofence
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Geofence not found'
            ], 404);
        }
    }

    /**
     * Update the specified geofence polygon.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'coordinates' => 'required|array|min:3',
            'coordinates.*' => 'required|array|size:2',
            'coordinates.*.0' => 'required|numeric|between:-90,90',
            'coordinates.*.1' => 'required|numeric|between:-180,180',
            'is_active' => 'boolean'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();

        try {
            $geofence = Geofence::findOrFail($id);

            // If setting as active, deactivate others for this site
            if ($request->boolean('is_active') && !$geofence->is_active) {
                Geofence::where('site_id', $geofence->site_id)
                    ->where('id', '!=', $geofence->id)
                    ->update(['is_active' => false]);
            }

            // Update geofence properties
            $geofence->update([
                'name' => $request->name,
                'is_active' => $request->boolean('is_active'),
            ]);

            // Update polygon coordinates
            $geofence->setPolygonFromCoordinates($request->coordinates);

            DB::commit();

            // Reload with coordinates
            $geofence->load('creator', 'site');
            $geofence->coordinates = $geofence->getPolygonCoordinates();

            return response()->json([
                'success' => true,
                'message' => 'Geofence updated successfully',
                'geofence' => $geofence
            ]);
        } catch (\Exception $e) {
            DB::rollback();
            Log::error('Error updating geofence', ['geofence_id' => $id, 'exception' => $e]);
            return response()->json([
                'success' => false,
                'error' => 'Unable to update geofence. Please try again.'
            ], 500);
        }
    }

    /**
     * Remove the specified geofence.
     */
    public function destroy(string $id): JsonResponse
    {
        try {
            $geofence = Geofence::findOrFail($id);

            return DB::transaction(function () use ($geofence) {
                // Business rule: Check for active shifts using this geofence
                $activeShifts = DB::table('shifts')
                    ->where('geofence_id', $geofence->id)
                    ->whereIn('status', ['scheduled', 'active'])
                    ->count();

                if ($activeShifts > 0) {
                    return response()->json([
                        'success' => false,
                        'error' => "Cannot delete geofence with {$activeShifts} active shifts. Please complete shifts first."
                    ], 422);
                }

                $geofence->delete();

                return response()->json([
                    'success' => true,
                    'message' => 'Geofence deleted successfully'
                ]);
            });
        } catch (\Exception $e) {
            Log::error('Error deleting geofence', ['geofence_id' => $id, 'exception' => $e]);
            return response()->json([
                'success' => false,
                'error' => 'Unable to delete geofence. Please try again.'
            ], 500);
        }
    }

    /**
     * Remove all geofences for a specific site.
     */
    public function destroyBySite(string $siteId): JsonResponse
    {
        try {
            // Ensure the site exists before touching its geofences.
            Site::findOrFail($siteId);

            // Same lock as store(): no geofence changes while a shift is active
            // at this site.
            $siteActiveShifts = DB::table('shifts')
                ->where('site_id', $siteId)
                ->where('status', 'active')
                ->count();

            if ($siteActiveShifts > 0) {
                return response()->json([
                    'success' => false,
                    'error' => "Can't clear this site's geofence while a shift is active here. You can edit it once the shift ends."
                ], 422);
            }

            return DB::transaction(function () use ($siteId) {
                // Get all geofences for this site
                $geofences = Geofence::where('site_id', $siteId)->get();

                if ($geofences->isEmpty()) {
                    return response()->json([
                        'success' => true,
                        'message' => 'No geofences found to delete'
                    ]);
                }

                // Check for active shifts using any of these geofences
                $geofenceIds = $geofences->pluck('id');
                $activeShifts = DB::table('shifts')
                    ->whereIn('geofence_id', $geofenceIds)
                    ->whereIn('status', ['scheduled', 'active'])
                    ->count();

                if ($activeShifts > 0) {
                    $shiftWord = $activeShifts === 1 ? 'shift' : 'shifts';
                    return response()->json([
                        'success' => false,
                        'error' => "Can't clear the geofence while it's linked to {$activeShifts} ongoing or scheduled {$shiftWord}. Complete or cancel them first."
                    ], 422);
                }

                // Delete all geofences for this site
                $deletedCount = Geofence::where('site_id', $siteId)->delete();

                return response()->json([
                    'success' => true,
                    'message' => "{$deletedCount} geofence(s) deleted successfully"
                ]);
            });
        } catch (\Exception $e) {
            Log::error('Error deleting geofences for site', ['site_id' => $siteId, 'exception' => $e]);
            return response()->json([
                'success' => false,
                'error' => 'Unable to delete geofences. Please try again.'
            ], 500);
        }
    }

    /**
     * Toggle geofence active status.
     */
    public function toggleStatus(Request $request, string $id): JsonResponse
    {
        DB::beginTransaction();

        try {
            $geofence = Geofence::findOrFail($id);

            if (!$geofence->is_active) {
                // Activating this geofence - deactivate others for this site
                Geofence::where('site_id', $geofence->site_id)
                    ->where('id', '!=', $geofence->id)
                    ->update(['is_active' => false]);

                $geofence->update(['is_active' => true]);
                $message = 'Geofence activated successfully';
            } else {
                $geofence->update(['is_active' => false]);
                $message = 'Geofence deactivated successfully';
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => $message,
                'geofence' => $geofence
            ]);
        } catch (\Exception $e) {
            DB::rollback();
            Log::error('Error updating geofence status', ['geofence_id' => $id, 'exception' => $e]);
            return response()->json([
                'success' => false,
                'error' => 'Unable to update geofence status. Please try again.'
            ], 500);
        }
    }

    /**
     * Test if a point is inside the geofence using MySQL ST_CONTAINS.
     * Used for testing geofence boundaries during setup.
     */
    public function testPoint(Request $request, string $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $geofence = Geofence::findOrFail($id);
            $isInside = $geofence->containsPoint(
                $request->latitude,
                $request->longitude
            );

            return response()->json([
                'success' => true,
                'is_inside' => $isInside,
                'test_point' => [
                    'latitude' => $request->latitude,
                    'longitude' => $request->longitude
                ],
                'geofence' => [
                    'id' => $geofence->id,
                    'name' => $geofence->name
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error testing geofence point', ['geofence_id' => $id, 'exception' => $e]);
            return response()->json([
                'success' => false,
                'error' => 'Unable to test point. Please try again.'
            ], 500);
        }
    }

    /**
     * Get active geofence for a site (for shift assignment).
     */
    public function getActiveGeofence(string $siteId): JsonResponse
    {
        try {
            $geofence = Geofence::where('site_id', $siteId)
                ->where('is_active', true)
                ->with('site')
                ->first();

            if (!$geofence) {
                return response()->json([
                    'success' => false,
                    'error' => 'No active geofence found for this site'
                ], 404);
            }

            $geofence->coordinates = $geofence->getPolygonCoordinates();

            return response()->json([
                'success' => true,
                'geofence' => $geofence
            ]);
        } catch (\Exception $e) {
            Log::error('Error loading active geofence', ['site_id' => $siteId, 'exception' => $e]);
            return response()->json([
                'success' => false,
                'error' => 'Unable to load geofence. Please try again.'
            ], 500);
        }
    }
}