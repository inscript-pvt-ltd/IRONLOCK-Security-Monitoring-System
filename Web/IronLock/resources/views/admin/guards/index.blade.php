@extends('admin.layouts.app')

@section('title', 'Guards Management - IronLock')
@section('page-title', 'Guards')

@section('topbar-actions')
<button class="btn-sm btn-primary-sm" onclick="openGuardDrawer('add')">+ Add Guard</button>
@endsection

@section('styles')
<style>
    /* Guards Management with Right Drawer - Following D-05 Wireframe */
    .guards-layout {
        display: flex;
        min-height: calc(100vh - var(--header-h) - 32px);
        position: relative;
    }

    .guards-main {
        flex: 1;
        min-width: 0;
    }

    .filters-row {
        display: flex;
        gap: 12px;
        margin-bottom: 20px;
        align-items: center;
    }

    .search-input {
        padding: 8px 12px;
        background: var(--bg-dark);
        border: 1px solid var(--border-dark);
        border-radius: 4px;
        color: var(--text-primary);
        font-size: 11px;
        max-width: 200px;
        margin: 0;
    }

    .search-input:focus {
        outline: none;
        border-color: var(--premium-gold);
    }

    .filter-select {
        padding: 4px 10px;
        border: 1.5px solid var(--border-dark);
        background: var(--bg-dark);
        color: var(--text-secondary);
        font-size: 11px;
        border-radius: 4px;
        cursor: pointer;
    }

    .sia-warning {
        border: 1.5px dashed #888;
        background: var(--bg-dark);
        padding: 4px 8px;
        font-size: 10px;
        display: inline-block;
        color: var(--warning-amber);
    }

    /* Right Drawer */
    .guard-drawer {
        width: 260px;
        background: var(--surface-dark);
        border-left: 1.5px solid var(--border-dark);
        padding: 20px;
        position: fixed;
        right: 0;
        top: var(--header-h);
        bottom: 0;
        transform: translateX(100%);
        transition: transform 0.3s ease;
        z-index: 1000;
        overflow-y: auto;
    }

    .guard-drawer.open {
        transform: translateX(0);
    }

    /* Custom scrollbar — same blue/gold treatment as the schedule table
       (.cal-container) on the shifts page, but vertical for the drawer. */
    .guard-drawer {
        scrollbar-width: thin;
        scrollbar-color: var(--deep-security-blue) var(--bg-dark);
    }
    .guard-drawer::-webkit-scrollbar { width: 10px; }
    .guard-drawer::-webkit-scrollbar-track {
        background: var(--bg-dark);
        border-radius: 4px;
    }
    .guard-drawer::-webkit-scrollbar-thumb {
        background: var(--deep-security-blue);
        border-radius: 6px;
        border: 2px solid var(--bg-dark);
    }
    .guard-drawer::-webkit-scrollbar-thumb:hover {
        background: var(--premium-gold);
    }

    /* Info Mode Styling */
    .guard-drawer.info-mode .drawer-input {
        background: var(--bg-dark);
        border: 1px solid var(--border-dark);
        color: var(--text-secondary);
        pointer-events: none;
    }

    .info-field {
        margin-bottom: 16px;
    }

    .info-label {
        display: block;
        font-size: 10px;
        font-weight: bold;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        color: var(--text-secondary);
        margin-bottom: 6px;
    }

    .info-value {
        padding: 9px 10px;
        background: var(--bg-dark);
        border: 1px solid var(--border-dark);
        border-radius: 4px;
        color: var(--text-primary);
        font-size: 11px;
        min-height: 38px;
        display: flex;
        align-items: center;
    }

    .info-value.empty {
        color: var(--text-muted);
        font-style: italic;
    }

    .drawer-title {
        font-size: 13px;
        font-weight: bold;
        margin-bottom: 20px;
        padding-bottom: 8px;
        border-bottom: 1.5px solid var(--border-dark);
        color: var(--text-primary);
    }

    .drawer-field {
        margin-bottom: 16px;
    }

    .drawer-label {
        display: block;
        font-size: 10px;
        font-weight: bold;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        color: var(--text-secondary);
        margin-bottom: 6px;
    }

    .drawer-input {
        width: 100%;
        padding: 9px 10px;
        background: var(--bg-dark);
        border: 1.5px solid var(--border-dark);
        border-radius: 4px;
        color: var(--text-primary);
        font-size: 11px;
        font-family: inherit;
    }

    .drawer-input:focus {
        outline: none;
        border-color: var(--premium-gold);
    }

    .drawer-actions {
        display: flex;
        gap: 8px;
        margin-top: 20px;
    }

    .drawer-btn {
        padding: 7px 12px;
        font-size: 11px;
        font-weight: bold;
        border-radius: 4px;
        cursor: pointer;
        transition: all 0.2s ease;
        border: none;
    }

    .drawer-btn.primary {
        background: var(--deep-security-blue);
        color: white;
    }

    .drawer-btn.primary:hover {
        background: var(--premium-gold);
        color: var(--bg-dark);
    }

    .drawer-btn.outline {
        background: transparent;
        color: var(--text-secondary);
        border: 1px solid var(--border-dark);
    }

    .drawer-btn.outline:hover {
        border-color: var(--premium-gold);
        color: var(--premium-gold);
    }

    /* Error Styling */
    .field-error {
        display: block;
        color: var(--error-red);
        font-size: 10px;
        margin-top: 4px;
        margin-bottom: 8px;
    }

    .drawer-input.error {
        border-color: var(--error-red);
        background: rgba(239, 68, 68, 0.1);
    }

    .error-summary {
        background: rgba(239, 68, 68, 0.1);
        border: 1px solid var(--error-red);
        border-radius: 4px;
        padding: 12px;
        margin-bottom: 16px;
        display: none;
    }

    .error-summary.show {
        display: block;
    }

    .error-summary h4 {
        color: var(--error-red);
        font-size: 11px;
        font-weight: bold;
        margin-bottom: 8px;
    }

    .error-summary ul {
        margin: 0;
        padding-left: 16px;
        list-style: disc;
    }

    .error-summary li {
        color: var(--error-red);
        font-size: 10px;
        margin-bottom: 4px;
    }

    /* Overlay for mobile */
    .drawer-overlay {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.5);
        display: none;
        z-index: 999;
    }

    .drawer-overlay.show {
        display: block;
    }

    /* Table responsive adjustments for credentials column */
    .table-container {
        overflow-x: auto;
    }

    .table th,
    .table td {
        white-space: nowrap;
        min-width: fit-content;
    }

    /* Credentials column specific styling */
    .table th:last-child,
    .table td:last-child {
        text-align: center;
        min-width: 60px;
    }

    /* Delete Button Styling */
    .btn-danger-sm {
        background: var(--error-red);
        color: white;
        border: none;
        padding: 4px 8px;
        font-size: 10px;
        font-weight: bold;
        border-radius: 3px;
        cursor: pointer;
        transition: all 0.2s ease;
    }

    .btn-danger-sm:hover {
        background: #B91C1C;
        transform: translateY(-1px);
    }

    /* Delete Confirmation Modal */
    .delete-confirmation {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.7);
        display: none;
        justify-content: center;
        align-items: center;
        z-index: 9999;
    }

    .delete-confirmation.show {
        display: flex;
    }

    .delete-modal {
        background: var(--surface-dark);
        border: 1.5px solid var(--error-red);
        border-radius: 8px;
        padding: 24px;
        max-width: 400px;
        width: 90%;
    }

    .delete-modal h3 {
        color: var(--error-red);
        font-size: 14px;
        font-weight: bold;
        margin-bottom: 16px;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .delete-modal p {
        color: var(--text-primary);
        font-size: 12px;
        line-height: 1.4;
        margin-bottom: 16px;
    }

    .delete-modal .warning {
        background: rgba(239, 68, 68, 0.1);
        border-left: 3px solid var(--error-red);
        padding: 12px;
        margin-bottom: 16px;
        font-size: 11px;
        color: var(--text-secondary);
    }

    .delete-actions {
        display: flex;
        gap: 12px;
        justify-content: flex-end;
    }

    .delete-btn {
        padding: 8px 16px;
        font-size: 11px;
        font-weight: bold;
        border-radius: 4px;
        cursor: pointer;
        transition: all 0.2s ease;
        border: none;
    }

    .delete-btn.confirm {
        background: var(--error-red);
        color: white;
    }

    .delete-btn.confirm:hover {
        background: #B91C1C;
    }

    .delete-btn.cancel {
        background: transparent;
        color: var(--text-secondary);
        border: 1px solid var(--border-dark);
    }

    .delete-btn.cancel:hover {
        border-color: var(--premium-gold);
        color: var(--premium-gold);
    }

    /* Copy Credentials Button */
    .btn-copy-sm {
        background: var(--deep-security-blue);
        color: white;
        border: none;
        padding: 4px 8px;
        font-size: 10px;
        font-weight: bold;
        border-radius: 3px;
        cursor: pointer;
        transition: all 0.2s ease;
        position: relative;
        min-width: 45px;
    }

    .btn-copy-sm:hover {
        background: var(--premium-gold);
        color: var(--bg-dark);
        transform: translateY(-1px);
    }

    .btn-copy-sm.copied {
        background: var(--success-green);
        color: white;
    }

    .copied-text {
        display: none !important;
    }

    .btn-copy-sm.copied .copy-text {
        display: none;
    }

    .btn-copy-sm.copied .copied-text {
        display: inline !important;
    }

    /* Recent Shifts drawer view */
    .shifts-drawer-sub {
        font-size: 11px;
        color: var(--text-muted);
        margin-bottom: 14px;
        line-height: 1.4;
    }

    .shift-list-item {
        border: 1px solid var(--border-dark);
        border-radius: 4px;
        padding: 10px 12px;
        margin-bottom: 8px;
        background: var(--bg-dark);
    }

    .shift-list-ref {
        font-weight: bold;
        color: var(--premium-gold);
        font-size: 11px;
        letter-spacing: 0.02em;
    }

    .shift-list-meta {
        font-size: 10px;
        color: var(--text-secondary);
        margin-top: 4px;
        line-height: 1.6;
    }

    .shift-list-view {
        display: inline-block;
        margin-top: 8px;
        padding: 4px 10px;
        font-size: 10px;
        font-weight: bold;
        border-radius: 3px;
        text-decoration: none;
        background: var(--deep-security-blue);
        color: #fff;
        border: 1px solid var(--deep-security-blue);
        transition: all 0.2s ease;
    }

    .shift-list-view:hover {
        background: var(--premium-gold);
        color: var(--bg-dark);
        border-color: var(--premium-gold);
    }

    .shifts-empty,
    .shifts-loading {
        text-align: center;
        padding: 30px 10px;
        color: var(--text-muted);
        font-size: 11px;
    }
</style>
@endsection

@section('content')
<div class="guards-layout">
    <!-- Main Guards Area -->
    <div class="guards-main">
        <!-- Filters Row -->
        <form method="GET" class="filters-row">
            <input type="text" name="search" placeholder="Search name or SIA no..." class="search-input" value="{{ request('search') }}">
            <select name="site" class="filter-select">
                <option value="">Site ▼</option>
                <!-- TODO: Add actual sites -->
            </select>
            <select name="status" class="filter-select">
                <option value="">Status ▼</option>
                <option value="active" {{ request('status') === 'active' ? 'selected' : '' }}>Active</option>
                <option value="inactive" {{ request('status') === 'inactive' ? 'selected' : '' }}>Inactive</option>
                <option value="suspended" {{ request('status') === 'suspended' ? 'selected' : '' }}>Suspended</option>
            </select>
            <select name="sia_expiry" class="filter-select">
                <option value="">SIA Expiry ▼</option>
                <option value="expired">Expired</option>
                <option value="expiring_soon">Expiring Soon</option>
                <option value="valid">Valid</option>
            </select>
            <button type="submit" class="btn-sm btn-secondary-sm">Filter</button>
        </form>

        <!-- Guards Table -->
        <div class="table-container">
            @if ($guards->count() > 0)
                <table class="table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>SIA License No</th>
                            <th>SIA Expiry</th>
                            <th>Site(s)</th>
                            <th>Status</th>
                            <th>Actions</th>
                            <th>Recent Shifts</th>
                            <th>Credentials</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($guards as $guard)
                            <tr>
                                <td>{{ $guard->first_name }} {{ $guard->last_name }}</td>
                                <td>{{ $guard->sia_licence_number ?? 'Not Set' }}</td>
                                <td>
                                    @if ($guard->sia_licence_expiry)
                                        @if ($guard->isSiaLicenceExpired())
                                            <span class="sia-warning">⚠ {{ $guard->sia_licence_expiry->format('M Y') }}</span>
                                        @elseif ($guard->isSiaLicenceExpiringSoon())
                                            <span class="sia-warning">⚠ {{ $guard->sia_licence_expiry->format('M Y') }}</span>
                                        @else
                                            {{ $guard->sia_licence_expiry->format('M Y') }}
                                        @endif
                                    @else
                                        <span style="color: var(--text-muted);">Not Set</span>
                                    @endif
                                </td>
                                <td>
                                    <!-- TODO: Add actual site relationships -->
                                    <span style="color: var(--text-muted);">No Site</span>
                                </td>
                                <td>
                                    <div class="status-chip {{ $guard->employment_status === 'active' ? '' : 'unresponsive' }}">
                                        {{ $guard->employment_status === 'active' ? 'Active' : 'Inactive' }}
                                    </div>
                                </td>
                                <td>
                                    <div style="display: flex; gap: 4px;">
                                        <button class="btn-sm btn-secondary-sm" onclick="showGuardInfo('{{ $guard->id }}')">Info</button>
                                        <button class="btn-sm btn-secondary-sm" onclick="editGuard('{{ $guard->id }}')">Edit</button>
                                        @if ($guard->employment_status === 'active')
                                            <button class="btn-sm btn-secondary-sm" onclick="toggleGuardStatus('{{ $guard->id }}', 'deactivate')">Deactivate</button>
                                        @else
                                            <button class="btn-sm btn-secondary-sm" onclick="toggleGuardStatus('{{ $guard->id }}', 'activate')">Activate</button>
                                        @endif
                                        <button class="btn-sm btn-danger-sm" onclick="deleteGuard('{{ $guard->id }}', '{{ $guard->first_name }} {{ $guard->last_name }}')">Delete</button>
                                    </div>
                                </td>
                                <td>
                                    <button class="btn-sm btn-secondary-sm" onclick="showGuardShifts('{{ $guard->id }}')">View</button>
                                </td>
                                <td>
                                    <button class="btn-sm btn-copy-sm" onclick="copyCredentials('{{ $guard->username }}', '{{ $guard->first_name }}', '{{ $guard->last_name }}')">
                                        <span class="copy-text">Copy</span>
                                        <span class="copied-text" style="display: none;">Copied!</span>
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                @if ($guards->hasPages())
                    <div style="display: flex; justify-content: space-between; margin-top: 16px; font-size: 11px; color: var(--text-muted); align-items: center;">
                        <button class="btn-sm btn-secondary-sm" {{ $guards->onFirstPage() ? 'disabled' : '' }}>← Prev</button>
                        <span>Page {{ $guards->currentPage() }} of {{ $guards->lastPage() }}</span>
                        <button class="btn-sm btn-secondary-sm" {{ !$guards->hasMorePages() ? 'disabled' : '' }}>Next →</button>
                    </div>
                @endif
            @else
                <div style="text-align: center; padding: 40px; color: var(--text-muted);">
                    <p>No guards found.</p>
                    <button class="btn-sm btn-primary-sm" style="margin-top: 16px;" onclick="openGuardDrawer('add')">Add your first guard</button>
                </div>
            @endif
        </div>
    </div>
</div>

<!-- Guard Drawer (Slides in from right) -->
<div class="drawer-overlay" onclick="closeGuardDrawer()"></div>
<div class="guard-drawer" id="guardDrawer">
    <div class="drawer-title" id="drawerTitle">Add / Edit Guard</div>

    <!-- Edit Form (shown when editing/adding) -->
    <form id="guardForm" style="display: block;">
        @csrf
        <input type="hidden" id="guardId" name="guard_id">

        <!-- Error Summary -->
        <div id="errorSummary" class="error-summary">
            <h4>Please fix the following errors:</h4>
            <ul id="errorList"></ul>
        </div>

        <div class="drawer-field">
            <label class="drawer-label">First name</label>
            <input type="text" class="drawer-input" id="first_name" name="first_name" required>
            <span class="field-error" id="error_first_name"></span>
        </div>

        <div class="drawer-field">
            <label class="drawer-label">Last name</label>
            <input type="text" class="drawer-input" id="last_name" name="last_name" required>
            <span class="field-error" id="error_last_name"></span>
        </div>

        <div class="drawer-field">
            <label class="drawer-label">Username</label>
            <input type="text" class="drawer-input" id="username" name="username" required>
            <span class="field-error" id="error_username"></span>
        </div>

        <div class="drawer-field">
            <label class="drawer-label">Email</label>
            <input type="email" class="drawer-input" id="email" name="email" required>
            <span class="field-error" id="error_email"></span>
        </div>

        <div class="drawer-field" id="passwordField">
            <label class="drawer-label">Password</label>
            <input type="password" class="drawer-input" id="password" name="password">
            <span class="field-error" id="error_password"></span>
            <input type="password" class="drawer-input" id="password_confirmation" name="password_confirmation" placeholder="Confirm Password" style="margin-top: 6px;">
            <span class="field-error" id="error_password_confirmation"></span>
            <div style="font-size: 10px; color: var(--text-muted); margin-top: 4px;">Leave blank to keep current password</div>
        </div>

        <div class="drawer-field">
            <label class="drawer-label">SIA License No</label>
            <input type="text" class="drawer-input" id="sia_licence_number" name="sia_licence_number" maxlength="8" pattern="[0-9]{8}" placeholder="12345678">
            <span class="field-error" id="error_sia_licence_number"></span>
        </div>

        <div class="drawer-field">
            <label class="drawer-label">SIA Expiry Date</label>
            <input type="date" class="drawer-input" id="sia_licence_expiry" name="sia_licence_expiry">
            <span class="field-error" id="error_sia_licence_expiry"></span>
        </div>

        <div class="drawer-field">
            <label class="drawer-label">SIA License Type</label>
            <select class="drawer-input" id="sia_licence_type" name="sia_licence_type">
                <option value="">Select type</option>
                <option value="Door Supervision">Door Supervision</option>
                <option value="Security Guarding">Security Guarding</option>
                <option value="Public Space Surveillance">Public Space Surveillance</option>
                <option value="Key Holding">Key Holding</option>
            </select>
            <span class="field-error" id="error_sia_licence_type"></span>
        </div>

        <div class="drawer-field">
            <label class="drawer-label">Phone</label>
            <input type="tel" class="drawer-input" id="phone" name="phone" required>
            <span class="field-error" id="error_phone"></span>
        </div>

        <div class="drawer-field">
            <label class="drawer-label">Hire Date</label>
            <input type="date" class="drawer-input" id="hire_date" name="hire_date" required>
            <span class="field-error" id="error_hire_date"></span>
        </div>

        <div class="drawer-field">
            <label class="drawer-label">Employment Status</label>
            <select class="drawer-input" id="employment_status" name="employment_status" required>
                <option value="">Select status</option>
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
                <option value="suspended">Suspended</option>
            </select>
            <span class="field-error" id="error_employment_status"></span>
        </div>

        <div class="drawer-actions">
            <button type="submit" class="drawer-btn primary">SAVE</button>
            <button type="button" class="drawer-btn outline" onclick="closeGuardDrawer()">CANCEL</button>
        </div>
    </form>

    <!-- Info View (shown when viewing details) -->
    <div id="guardInfoView" style="display: none;">
        <div class="info-field">
            <label class="info-label">Employee Code</label>
            <div class="info-value" id="info_employee_code">-</div>
        </div>

        <div class="info-field">
            <label class="info-label">Full Name</label>
            <div class="info-value" id="info_full_name">-</div>
        </div>

        <div class="info-field">
            <label class="info-label">Username</label>
            <div class="info-value" id="info_username">-</div>
        </div>

        <div class="info-field">
            <label class="info-label">Email</label>
            <div class="info-value" id="info_email">-</div>
        </div>

        <div class="info-field">
            <label class="info-label">Phone</label>
            <div class="info-value" id="info_phone">-</div>
        </div>

        <div class="info-field">
            <label class="info-label">SIA License No</label>
            <div class="info-value" id="info_sia_licence">-</div>
        </div>

        <div class="info-field">
            <label class="info-label">SIA Expiry Date</label>
            <div class="info-value" id="info_sia_expiry">-</div>
        </div>

        <div class="info-field">
            <label class="info-label">SIA License Type</label>
            <div class="info-value" id="info_sia_type">-</div>
        </div>

        <div class="info-field">
            <label class="info-label">Employment Status</label>
            <div class="info-value" id="info_status">-</div>
        </div>

        <div class="info-field">
            <label class="info-label">Hire Date</label>
            <div class="info-value" id="info_hire_date">-</div>
        </div>

        <div class="info-field">
            <label class="info-label">Last Login</label>
            <div class="info-value" id="info_last_login">-</div>
        </div>

        <div class="drawer-actions">
            <button type="button" class="drawer-btn primary" onclick="editCurrentGuard()">EDIT</button>
            <button type="button" class="drawer-btn outline" onclick="closeGuardDrawer()">CLOSE</button>
        </div>
    </div>

    <!-- Recent Shifts View (shown when viewing completed shifts) -->
    <div id="guardShiftsView" style="display: none;">
        <div class="shifts-drawer-sub" id="shiftsGuardName">Completed shifts</div>
        <div id="guardShiftsList">
            <div class="shifts-loading">Loading…</div>
        </div>
        <div class="drawer-actions">
            <button type="button" class="drawer-btn outline" onclick="closeGuardDrawer()">CLOSE</button>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="delete-confirmation" id="deleteConfirmation">
    <div class="delete-modal">
        <h3>⚠️ Delete Guard Account</h3>
        <p>You are about to <strong>permanently delete</strong> the guard account for:</p>
        <p style="text-align: center; font-weight: bold; color: var(--premium-gold);" id="deleteGuardName">-</p>

        <div class="warning">
            <strong>GDPR Compliance Notice:</strong><br>
            • Personal data will be permanently removed<br>
            • Audit trails (shifts, alerts, compliance records) will be preserved<br>
            • This action cannot be undone<br>
            • Use "Deactivate" if you want to retain the account
        </div>

        <div class="delete-actions">
            <button type="button" class="delete-btn cancel" onclick="cancelDelete()">Cancel</button>
            <button type="button" class="delete-btn confirm" onclick="confirmDelete()">DELETE PERMANENTLY</button>
        </div>
    </div>
</div>
@endsection

@section('scripts')
<script>
    let currentGuardId = null;
    let currentGuardData = null; // Store guard data for reuse

    // Success message function for consistency
    function showSuccessToast(message) {
        const successMsg = document.createElement('div');
        successMsg.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            background: var(--success-green);
            color: white;
            padding: 12px 16px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: bold;
            z-index: 9999;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        `;
        successMsg.textContent = message;
        document.body.appendChild(successMsg);

        // Remove success message after 3 seconds
        setTimeout(() => {
            if (successMsg.parentNode) {
                successMsg.parentNode.removeChild(successMsg);
            }
        }, 3000);
    }

    // Error handling functions
    function clearAllErrors() {
        // Clear error summary
        document.getElementById('errorSummary').classList.remove('show');
        document.getElementById('errorList').innerHTML = '';

        // Clear all field errors
        const errorElements = document.querySelectorAll('.field-error');
        errorElements.forEach(element => {
            element.textContent = '';
        });

        // Remove error styling from inputs
        const inputs = document.querySelectorAll('.drawer-input.error');
        inputs.forEach(input => {
            input.classList.remove('error');
        });
    }

    function showFieldError(fieldName, message) {
        const errorElement = document.getElementById(`error_${fieldName}`);
        const inputElement = document.getElementById(fieldName);

        if (errorElement) {
            errorElement.textContent = message;
        }
        if (inputElement) {
            inputElement.classList.add('error');
        }
    }

    function showErrorSummary(errors) {
        const errorSummary = document.getElementById('errorSummary');
        const errorList = document.getElementById('errorList');

        errorList.innerHTML = '';

        for (let field in errors) {
            const messages = Array.isArray(errors[field]) ? errors[field] : [errors[field]];
            messages.forEach(message => {
                const li = document.createElement('li');
                li.textContent = message;
                errorList.appendChild(li);

                // Also show field-level error
                showFieldError(field, message);
            });
        }

        errorSummary.classList.add('show');

        // Scroll to top of drawer to show error summary
        document.getElementById('guardDrawer').scrollTop = 0;
    }

    function openGuardDrawer(mode, guardId = null) {
        const drawer = document.getElementById('guardDrawer');
        const overlay = document.querySelector('.drawer-overlay');
        const title = document.getElementById('drawerTitle');
        const form = document.getElementById('guardForm');
        const infoView = document.getElementById('guardInfoView');
        const shiftsView = document.getElementById('guardShiftsView');

        // Reset drawer state
        drawer.classList.remove('info-mode');
        form.style.display = 'none';
        infoView.style.display = 'none';
        shiftsView.style.display = 'none';

        // Clear all errors
        clearAllErrors();

        if (mode === 'add') {
            title.textContent = 'Add Guard';
            form.reset();
            currentGuardId = null;
            currentGuardData = null;
            document.getElementById('guardId').value = '';
            // Set default hire date to today
            document.getElementById('hire_date').value = new Date().toISOString().split('T')[0];
            // Set default employment status
            document.getElementById('employment_status').value = 'active';
            // Make password required for new guards
            document.getElementById('password').required = true;
            document.getElementById('password_confirmation').required = true;
            document.getElementById('passwordField').style.display = 'block';
            form.style.display = 'block';
        } else if (mode === 'edit') {
            title.textContent = 'Edit Guard';
            currentGuardId = guardId;
            document.getElementById('guardId').value = guardId;
            // Make password optional for edits
            document.getElementById('password').required = false;
            document.getElementById('password_confirmation').required = false;
            document.getElementById('passwordField').style.display = 'block';
            form.style.display = 'block';
        } else if (mode === 'info') {
            title.textContent = 'Guard Information';
            currentGuardId = guardId;
            drawer.classList.add('info-mode');
            infoView.style.display = 'block';
        } else if (mode === 'shifts') {
            title.textContent = 'Recent Shifts';
            currentGuardId = guardId;
            shiftsView.style.display = 'block';
        }

        drawer.classList.add('open');
        overlay.classList.add('show');
        document.body.style.overflow = 'hidden';
    }

    function showGuardInfo(guardId) {
        // Load guard data via AJAX
        fetch(`/admin/guards/${guardId}`, {
            method: 'GET',
            headers: {
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const guard = data.guard;

                // Store guard data for reuse in edit mode
                currentGuardData = guard;

                // Populate info view
                document.getElementById('info_employee_code').textContent = guard.employee_code || 'Not Set';
                document.getElementById('info_full_name').textContent = `${guard.first_name} ${guard.last_name}`;
                document.getElementById('info_username').textContent = guard.username || 'Not Set';
                document.getElementById('info_email').textContent = guard.email || 'Not Set';
                document.getElementById('info_phone').textContent = guard.phone || 'Not Set';
                document.getElementById('info_sia_licence').textContent = guard.sia_licence_number || 'Not Set';

                // Format SIA expiry date
                if (guard.sia_licence_expiry) {
                    const date = new Date(guard.sia_licence_expiry);
                    document.getElementById('info_sia_expiry').textContent = date.toLocaleDateString('en-GB');
                } else {
                    document.getElementById('info_sia_expiry').textContent = 'Not Set';
                }

                document.getElementById('info_sia_type').textContent = guard.sia_licence_type || 'Not Set';
                document.getElementById('info_status').textContent = guard.employment_status || 'Unknown';
                document.getElementById('info_hire_date').textContent = guard.hire_date ? new Date(guard.hire_date).toLocaleDateString('en-GB') : 'Not Set';
                document.getElementById('info_last_login').textContent = guard.last_login_at ? new Date(guard.last_login_at).toLocaleDateString('en-GB') : 'Never';

                openGuardDrawer('info', guardId);
            } else {
                alert('Error loading guard information');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error loading guard information');
        });
    }

    // Escape user-supplied values before injecting into innerHTML (site name,
    // reference). Route-generated URLs are safe and not passed through here.
    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, function(ch) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch];
        });
    }

    // Open the drawer in "shifts" mode and load the guard's completed shifts.
    function showGuardShifts(guardId) {
        openGuardDrawer('shifts', guardId);

        const list = document.getElementById('guardShiftsList');
        const nameEl = document.getElementById('shiftsGuardName');
        list.innerHTML = '<div class="shifts-loading">Loading…</div>';
        nameEl.textContent = 'Completed shifts';

        fetch(`/admin/guards/${guardId}/shifts`, {
            method: 'GET',
            headers: {
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            }
        })
        .then(response => response.json())
        .then(data => {
            if (!data.success) {
                list.innerHTML = '<div class="shifts-empty">Unable to load shifts.</div>';
                return;
            }

            if (data.guard && data.guard.name) {
                nameEl.textContent = `Completed shifts — ${data.guard.name}`;
            }

            renderGuardShifts(data.shifts || []);
        })
        .catch(error => {
            console.error('Error loading guard shifts:', error);
            list.innerHTML = '<div class="shifts-empty">Error loading shifts.</div>';
        });
    }

    function renderGuardShifts(shifts) {
        const list = document.getElementById('guardShiftsList');

        if (!shifts.length) {
            list.innerHTML = '<div class="shifts-empty">No completed shifts yet.</div>';
            return;
        }

        // Timestamps arrive as UTC ISO strings; render in the admin's local time.
        list.innerHTML = shifts.map(shift => {
            const start = shift.scheduled_start ? new Date(shift.scheduled_start) : null;
            const end = shift.scheduled_end ? new Date(shift.scheduled_end) : null;
            const dateLabel = start
                ? start.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' })
                : '—';
            const startTime = start
                ? start.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' })
                : '—';
            const endTime = end
                ? end.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' })
                : '—';
            const site = escapeHtml(shift.site_name || 'No site');
            const ref = escapeHtml(shift.reference || '—');

            return `
                <div class="shift-list-item">
                    <div class="shift-list-ref">#${ref}</div>
                    <div class="shift-list-meta">
                        ${escapeHtml(dateLabel)} · ${escapeHtml(startTime)}–${escapeHtml(endTime)}<br>
                        ${site}
                    </div>
                    <a href="${shift.timeline_url}" class="shift-list-view">View timeline →</a>
                </div>
            `;
        }).join('');
    }

    function editCurrentGuard() {
        if (currentGuardId && currentGuardData) {
            // Switch from info view to edit form using stored data
            const drawer = document.getElementById('guardDrawer');
            const form = document.getElementById('guardForm');
            const infoView = document.getElementById('guardInfoView');
            const title = document.getElementById('drawerTitle');

            // Populate form with stored guard data
            const guard = currentGuardData;
            document.getElementById('first_name').value = guard.first_name || '';
            document.getElementById('last_name').value = guard.last_name || '';
            document.getElementById('username').value = guard.username || '';
            document.getElementById('email').value = guard.email || '';
            document.getElementById('phone').value = guard.phone || '';
            document.getElementById('sia_licence_number').value = guard.sia_licence_number || '';
            document.getElementById('sia_licence_expiry').value = guard.sia_licence_expiry || '';
            document.getElementById('sia_licence_type').value = guard.sia_licence_type || '';
            document.getElementById('hire_date').value = guard.hire_date || '';
            document.getElementById('employment_status').value = guard.employment_status || '';

            // Set up for editing
            document.getElementById('guardId').value = currentGuardId;
            document.getElementById('password').required = false;
            document.getElementById('password_confirmation').required = false;

            // Clear any errors
            clearAllErrors();

            // Switch from info view to edit form
            title.textContent = 'Edit Guard';
            drawer.classList.remove('info-mode');
            infoView.style.display = 'none';
            form.style.display = 'block';
        }
    }

    function closeGuardDrawer() {
        const drawer = document.getElementById('guardDrawer');
        const overlay = document.querySelector('.drawer-overlay');

        drawer.classList.remove('open');
        overlay.classList.remove('show');
        document.body.style.overflow = '';
        currentGuardId = null;
        currentGuardData = null;
    }

    function editGuard(guardId) {
        // Load guard data via AJAX
        fetch(`/admin/guards/${guardId}`, {
            method: 'GET',
            headers: {
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Store guard data for future use
                currentGuardData = data.guard;

                // Populate form with guard data
                const guard = data.guard;
                document.getElementById('first_name').value = guard.first_name || '';
                document.getElementById('last_name').value = guard.last_name || '';
                document.getElementById('username').value = guard.username || '';
                document.getElementById('email').value = guard.email || '';
                document.getElementById('phone').value = guard.phone || '';
                document.getElementById('sia_licence_number').value = guard.sia_licence_number || '';
                document.getElementById('sia_licence_expiry').value = guard.sia_licence_expiry || '';
                document.getElementById('sia_licence_type').value = guard.sia_licence_type || '';
                document.getElementById('hire_date').value = guard.hire_date || '';
                document.getElementById('employment_status').value = guard.employment_status || '';

                openGuardDrawer('edit', guardId);
            } else {
                alert('Error loading guard data');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error loading guard data');
        });
    }

    function toggleGuardStatus(guardId, action) {
        if (confirm(`Are you sure you want to ${action} this guard?`)) {
            fetch(`/admin/guards/${guardId}/toggle-status`, {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
                },
                body: JSON.stringify({ action: action })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showSuccessToast(data.message);
                    setTimeout(() => {
                        location.reload(); // Refresh page to show updated status
                    }, 1000);
                } else {
                    alert(data.error || 'Error updating guard status');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error updating guard status');
            });
        }
    }

    // Handle form submission
    document.getElementById('guardForm').addEventListener('submit', function(e) {
        e.preventDefault();

        // Clear previous errors
        clearAllErrors();

        const formData = new FormData(this);
        const isEdit = currentGuardId !== null;

        // For PUT requests, we need to add the method spoofing
        if (isEdit) {
            formData.append('_method', 'PUT');
        }

        const url = isEdit ? `/admin/guards/${currentGuardId}` : '/admin/guards';

        // Disable submit button to prevent double submission
        const submitBtn = this.querySelector('button[type="submit"]');
        const originalText = submitBtn.textContent;
        submitBtn.textContent = 'SAVING...';
        submitBtn.disabled = true;

        fetch(url, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            },
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Show success toast message
                showSuccessToast(data.message);

                closeGuardDrawer();
                setTimeout(() => {
                    location.reload(); // Refresh page to show updated data
                }, 1000);
            } else {
                // Handle validation errors
                if (data.errors) {
                    showErrorSummary(data.errors);
                } else {
                    // Show generic error
                    const genericError = { general: [data.message || 'An error occurred while saving the guard'] };
                    showErrorSummary(genericError);
                }
            }
        })
        .catch(error => {
            console.error('Error:', error);
            const networkError = { general: ['Network error occurred. Please check your connection and try again.'] };
            showErrorSummary(networkError);
        })
        .finally(() => {
            // Re-enable submit button
            submitBtn.textContent = originalText;
            submitBtn.disabled = false;
        });
    });

    // Clear field error when user starts typing
    function clearFieldError(fieldName) {
        const errorElement = document.getElementById(`error_${fieldName}`);
        const inputElement = document.getElementById(fieldName);

        if (errorElement) {
            errorElement.textContent = '';
        }
        if (inputElement) {
            inputElement.classList.remove('error');
        }

        // Hide error summary if no errors remain
        const remainingErrors = document.querySelectorAll('.field-error:not(:empty)');
        if (remainingErrors.length === 0) {
            document.getElementById('errorSummary').classList.remove('show');
        }
    }

    // Add event listeners to clear errors on input
    document.addEventListener('DOMContentLoaded', function() {
        const formInputs = document.querySelectorAll('#guardForm input, #guardForm select');
        formInputs.forEach(input => {
            input.addEventListener('input', function() {
                clearFieldError(this.name);
            });
            input.addEventListener('change', function() {
                clearFieldError(this.name);
            });
        });
    });

    // Delete guard functionality
    let guardToDelete = null;

    function deleteGuard(guardId, guardName) {
        guardToDelete = guardId;
        document.getElementById('deleteGuardName').textContent = guardName;
        document.getElementById('deleteConfirmation').classList.add('show');
        document.body.style.overflow = 'hidden';
    }

    function cancelDelete() {
        guardToDelete = null;
        document.getElementById('deleteConfirmation').classList.remove('show');
        document.body.style.overflow = '';
    }

    function confirmDelete() {
        if (!guardToDelete) return;

        const confirmBtn = document.querySelector('.delete-btn.confirm');
        const originalText = confirmBtn.textContent;
        confirmBtn.textContent = 'DELETING...';
        confirmBtn.disabled = true;

        fetch(`/admin/guards/${guardToDelete}`, {
            method: 'DELETE',
            headers: {
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            }
        })
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                showSuccessToast(data.message);
                cancelDelete();
                setTimeout(() => {
                    location.reload(); // Refresh page to show updated data
                }, 1000);
            } else {
                alert(data.error || 'Error deleting guard');
                confirmBtn.textContent = originalText;
                confirmBtn.disabled = false;
            }
        })
        .catch(error => {
            console.error('Error deleting guard:', error);
            alert('Error deleting guard: ' + error.message);
            confirmBtn.textContent = originalText;
            confirmBtn.disabled = false;
        });
    }

    // Copy credentials functionality with fallback methods
    function copyCredentials(username, firstName, lastName) {
        const guardFullName = `${firstName} ${lastName}`;

        // Professional security credentials message based on PROJECT_MASTER_SPEC requirements
        const credentialsMessage = `IronLock Security Guard System - Shift Access Credentials

Guard: ${guardFullName}
Username: ${username}
Password: [ADMIN: REPLACE WITH ASSIGNED PASSWORD]

IMPORTANT SECURITY PROTOCOLS:
• Login access is ONLY available during your assigned shift window
• Authentication attempts outside shift times will be rejected and logged
• One active session per guard - new login will invalidate previous session
• Failed login attempts (5+) will temporarily lock your account

SHIFT ACCESS PROCEDURE:
1. Download IronLock Guard app from your assigned app store
2. Login using the username and password provided above
3. Begin shift only when you arrive at your assigned site
4. Maintain continuous GPS tracking throughout shift
5. Respond to all wakefulness checks within 10 seconds
6. Complete photo verification requests within 90 seconds

PASSWORD SECURITY:
• Change your password immediately after first login if instructed
• Never share your credentials with other personnel
• Report lost or compromised credentials to your supervisor immediately

SUPPORT:
For technical assistance or password reset, contact your shift supervisor.

This message contains sensitive security information. Do not share with unauthorized personnel.

Generated: ${new Date().toLocaleDateString('en-GB')} ${new Date().toLocaleTimeString('en-GB')}`;

        // Find the button that was clicked
        const button = event.target.closest('.btn-copy-sm');

        // Try modern clipboard API first
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(credentialsMessage).then(() => {
                showCopySuccess(button);
            }).catch(err => {
                console.error('Modern clipboard failed, trying fallback:', err);
                fallbackCopyToClipboard(credentialsMessage, button);
            });
        } else {
            // Use fallback method for non-HTTPS or older browsers
            fallbackCopyToClipboard(credentialsMessage, button);
        }
    }

    // Fallback clipboard method using execCommand
    function fallbackCopyToClipboard(text, button) {
        // Create a temporary textarea element
        const textArea = document.createElement('textarea');
        textArea.value = text;

        // Make it invisible
        textArea.style.position = 'fixed';
        textArea.style.left = '-999999px';
        textArea.style.top = '-999999px';
        textArea.style.opacity = '0';
        textArea.style.pointerEvents = 'none';

        document.body.appendChild(textArea);

        try {
            // Select and copy the text
            textArea.focus();
            textArea.select();
            textArea.setSelectionRange(0, 99999); // For mobile devices

            const successful = document.execCommand('copy');
            if (successful) {
                showCopySuccess(button);
            } else {
                throw new Error('execCommand failed');
            }
        } catch (err) {
            console.error('Fallback copy failed:', err);
            // Final fallback - show the text in a modal for manual copy
            showCredentialsModal(text);
        } finally {
            document.body.removeChild(textArea);
        }
    }

    // Show copy success feedback
    function showCopySuccess(button) {
        button.classList.add('copied');
        showSuccessToast('Credentials copied to clipboard');

        // Reset after 2 seconds
        setTimeout(() => {
            button.classList.remove('copied');
        }, 2000);
    }

    // Final fallback - show credentials in a modal for manual copy
    function showCredentialsModal(credentialsText) {
        // Create modal elements
        const modal = document.createElement('div');
        modal.style.cssText = `
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.7);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 10000;
        `;

        const modalContent = document.createElement('div');
        modalContent.style.cssText = `
            background: var(--surface-dark);
            border: 1.5px solid var(--border-dark);
            border-radius: 8px;
            padding: 24px;
            max-width: 600px;
            width: 90%;
            max-height: 80vh;
            overflow-y: auto;
        `;

        modalContent.innerHTML = `
            <h3 style="color: var(--premium-gold); font-size: 14px; margin-bottom: 16px;">Guard Credentials - Manual Copy</h3>
            <p style="color: var(--text-secondary); font-size: 12px; margin-bottom: 16px;">
                Automatic copy failed. Please manually select and copy the text below:
            </p>
            <textarea readonly style="
                width: 100%;
                height: 400px;
                background: var(--bg-dark);
                border: 1px solid var(--border-dark);
                border-radius: 4px;
                color: var(--text-primary);
                font-family: monospace;
                font-size: 11px;
                padding: 12px;
                resize: none;
            ">${credentialsText}</textarea>
            <div style="display: flex; gap: 12px; justify-content: flex-end; margin-top: 16px;">
                <button onclick="this.closest('[style*=position]').remove()" style="
                    padding: 8px 16px;
                    background: var(--deep-security-blue);
                    color: white;
                    border: none;
                    border-radius: 4px;
                    cursor: pointer;
                    font-size: 11px;
                    font-weight: bold;
                ">Close</button>
            </div>
        `;

        modal.appendChild(modalContent);
        document.body.appendChild(modal);

        // Auto-select the textarea content
        const textarea = modalContent.querySelector('textarea');
        setTimeout(() => {
            textarea.focus();
            textarea.select();
        }, 100);

        // Close modal on background click
        modal.addEventListener('click', (e) => {
            if (e.target === modal) {
                modal.remove();
            }
        });
    }

    // Close delete modal with Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            if (document.getElementById('deleteConfirmation').classList.contains('show')) {
                cancelDelete();
            } else {
                closeGuardDrawer();
            }
        }
    });
</script>
@endsection
