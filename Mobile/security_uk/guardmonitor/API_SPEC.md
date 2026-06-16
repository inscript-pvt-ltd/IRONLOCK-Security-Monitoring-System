# Ironlock Guard Monitor — Laravel API Specification

All responses follow this envelope:
```json
{ "success": true, "message": "...", "data": { ... } }
```
Errors use `"success": false` with an `"errors"` object (Laravel validation style).

---

## Setup

```bash
composer require laravel/sanctum
php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider"
php artisan migrate
```

`config/sanctum.php` — set token expiry as needed.  
`app/Http/Kernel.php` — add `EnsureFrontendRequestsAreStateful` to the `api` middleware group.

---

## Authentication

### POST `/api/auth/login`
Public. No auth required.

**Request**
```json
{
  "email": "j.smith@ironlock.co.uk",
  "password": "secret",
  "device_name": "ironlock_guard_app"
}
```

**Response 200**
```json
{
  "success": true,
  "message": "Login successful",
  "data": {
    "access_token": "<sanctum_token>",
    "token_type": "Bearer",
    "expires_in": 86400
  }
}
```

**Response 422** (wrong credentials)
```json
{
  "success": false,
  "message": "Incorrect email or password",
  "errors": { "email": ["These credentials do not match our records."] }
}
```

---

### POST `/api/auth/logout`
Requires: `Authorization: Bearer <token>`

**Response 200**
```json
{ "success": true, "message": "Logged out" }
```

---

## Guard Profile

### GET `/api/guard/profile`
Requires auth.

**Response 200**
```json
{
  "success": true,
  "data": {
    "id": 1,
    "email": "j.smith@ironlock.co.uk",
    "name": "James Smith",
    "employee_id": "EMP-0042",
    "site": "Westfield Shopping Centre A",
    "avatar_url": null,
    "licence_number": "SIA-123456",
    "licence_expiry": "2026-12-01"
  }
}
```

---

## Shifts

### POST `/api/shifts/start`
Requires auth.

**Request**
```json
{ "started_at": "2026-06-15T08:00:00Z" }
```

**Response 201**
```json
{
  "success": true,
  "data": {
    "id": "42",
    "shift_ref": "#SH-2847",
    "started_at": "2026-06-15T08:00:00.000000Z",
    "ended_at": null,
    "status": "active"
  }
}
```

---

### POST `/api/shifts/{id}/end`
Requires auth.

**Request**
```json
{ "ended_at": "2026-06-15T17:00:00Z" }
```

**Response 200**
```json
{
  "success": true,
  "data": {
    "id": "42",
    "shift_ref": "#SH-2847",
    "started_at": "2026-06-15T08:00:00.000000Z",
    "ended_at": "2026-06-15T17:00:00.000000Z",
    "status": "completed"
  }
}
```

---

## Photo Verification

### POST `/api/photos/upload`
Requires auth. `multipart/form-data`.

**Fields**
| Field | Type | Required |
|---|---|---|
| `photo` | file (jpg/png) | yes |
| `shift_id` | string | yes |
| `request_id` | string | no |
| `captured_at` | ISO 8601 datetime | yes |

**Response 200**
```json
{
  "success": true,
  "data": {
    "status": "validated",
    "review_url": null
  }
}
```
`status` is one of: `validated` `flagged` `failed`

---

## Welfare / Wakefulness Checks

### POST `/api/welfare/complete`
Requires auth.

**Request (success)**
```json
{
  "shift_id": "42",
  "code": "7391",
  "seconds_taken": 6,
  "result": "success",
  "completed_at": "2026-06-15T10:30:00Z"
}
```

**Request (failure)**
```json
{
  "shift_id": "42",
  "result": "failure",
  "reason": "timeout_or_wrong_code",
  "completed_at": "2026-06-15T10:30:00Z"
}
```

**Response 200**
```json
{ "success": true, "message": "Welfare check recorded" }
```

---

## Alerts

### GET `/api/alerts`
Requires auth. Returns all alerts for the authenticated guard.

**Response 200**
```json
{
  "success": true,
  "data": [
    {
      "id": "a1",
      "severity": "urgent",
      "title": "Welfare check not completed",
      "description": "A check-in code was not entered in time — your supervisor has been notified",
      "time": "4m ago",
      "dismissed": false
    }
  ]
}
```
`severity` is one of: `urgent` `notice` `reminder`

---

### POST `/api/alerts/{id}/dismiss`
Requires auth.

**Response 200**
```json
{ "success": true, "message": "Alert dismissed" }
```

---

## Laravel Migration Schema (reference)

```php
// guards table
Schema::create('guards', function (Blueprint $table) {
    $table->id();
    $table->string('email')->unique();
    $table->string('name');
    $table->string('employee_id')->unique();
    $table->string('site');
    $table->string('licence_number')->nullable();
    $table->date('licence_expiry')->nullable();
    $table->string('avatar_url')->nullable();
    $table->string('password');
    $table->timestamps();
});

// shifts table
Schema::create('shifts', function (Blueprint $table) {
    $table->id();
    $table->foreignId('guard_id')->constrained('guards');
    $table->string('shift_ref')->unique();
    $table->timestamp('started_at');
    $table->timestamp('ended_at')->nullable();
    $table->enum('status', ['active', 'completed'])->default('active');
    $table->timestamps();
});

// welfare_checks table
Schema::create('welfare_checks', function (Blueprint $table) {
    $table->id();
    $table->foreignId('shift_id')->constrained('shifts');
    $table->enum('result', ['success', 'failure']);
    $table->string('code')->nullable();
    $table->unsignedTinyInteger('seconds_taken')->nullable();
    $table->string('reason')->nullable();
    $table->timestamp('completed_at');
    $table->timestamps();
});

// photos table
Schema::create('photos', function (Blueprint $table) {
    $table->id();
    $table->foreignId('shift_id')->constrained('shifts');
    $table->string('request_id')->nullable();
    $table->string('file_path');
    $table->enum('status', ['validated', 'flagged', 'failed']);
    $table->string('review_url')->nullable();
    $table->timestamp('captured_at');
    $table->timestamps();
});

// alerts table
Schema::create('alerts', function (Blueprint $table) {
    $table->id();
    $table->foreignId('guard_id')->constrained('guards');
    $table->enum('severity', ['urgent', 'notice', 'reminder']);
    $table->string('title');
    $table->text('description');
    $table->string('time');
    $table->boolean('dismissed')->default(false);
    $table->timestamps();
});
```

---

## Route Registration (`routes/api.php`)

```php
Route::post('/auth/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout',          [AuthController::class, 'logout']);
    Route::get('/guard/profile',         [GuardController::class, 'profile']);
    Route::post('/shifts/start',         [ShiftController::class, 'start']);
    Route::post('/shifts/{shift}/end',   [ShiftController::class, 'end']);
    Route::post('/photos/upload',        [PhotoController::class, 'upload']);
    Route::post('/welfare/complete',     [WelfareController::class, 'complete']);
    Route::get('/alerts',                [AlertController::class, 'index']);
    Route::post('/alerts/{alert}/dismiss', [AlertController::class, 'dismiss']);
});
```

## Base URL Configuration

In the Flutter app, set the `API_BASE_URL` build environment variable:

```bash
# Android emulator (maps to host localhost)
flutter run --dart-define=API_BASE_URL=http://10.0.2.2:8000/api

# iOS simulator
flutter run --dart-define=API_BASE_URL=http://127.0.0.1:8000/api

# Real device (use your machine's local network IP)
flutter run --dart-define=API_BASE_URL=http://192.168.1.x:8000/api

# Production
flutter build apk --dart-define=API_BASE_URL=https://api.ironlock.co.uk/api
```
