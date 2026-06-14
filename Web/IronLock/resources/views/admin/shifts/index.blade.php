@extends('admin.layouts.app')

@section('title', 'Shift Scheduling - Admin Dashboard')
@section('page-title', 'Shift Scheduling')

@section('topbar-actions')
    <select id="week-selector" class="topbar-filter">
        <option value="current">Week: {{ date('d') }}-{{ date('d', strtotime('+6 days')) }} {{ date('M') }}</option>
        <option value="next">Week: {{ date('d', strtotime('+7 days')) }}-{{ date('d', strtotime('+13 days')) }} {{ date('M') }}</option>
        <option value="prev">Week: {{ date('d', strtotime('-7 days')) }}-{{ date('d', strtotime('-1 days')) }} {{ date('M') }}</option>
    </select>
    <button id="new-shift-btn" class="btn-primary-sm" style="background: var(--deep-security-blue); color: white; border: none; padding: 6px 12px; border-radius: 4px; font-size: 11px; font-weight: bold; cursor: pointer;">+ NEW</button>
@endsection

@section('styles')
<style>
    .calendar-container {
        background: var(--surface-dark);
        border: 1.5px solid var(--border-dark);
        border-radius: 4px;
        overflow: hidden;
        margin-bottom: 24px;
    }

    .calendar-grid {
        display: grid;
        grid-template-columns: 120px repeat(7, 1fr);
        gap: 0;
        font-size: 11px;
    }

    .calendar-header {
        background: var(--border-dark);
        padding: 8px;
        font-weight: bold;
        text-align: center;
        color: var(--text-secondary);
        border-bottom: 1px solid var(--border-dark);
    }

    .calendar-header:first-child {
        text-align: left;
        font-size: 9px;
        text-transform: uppercase;
        letter-spacing: 0.06em;
    }

    .guard-row {
        display: contents;
    }

    .guard-name {
        background: var(--surface-dark);
        padding: 12px 8px;
        border-right: 1px solid var(--border-dark);
        border-bottom: 1px solid var(--border-dark);
        font-weight: bold;
        font-size: 10px;
        display: flex;
        align-items: center;
        color: var(--text-primary);
    }

    .day-cell {
        background: var(--surface-dark);
        border-right: 1px solid var(--border-dark);
        border-bottom: 1px solid var(--border-dark);
        padding: 4px;
        min-height: 45px;
        position: relative;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: background-color 0.2s ease;
    }

    .day-cell:hover {
        background: var(--border-dark);
    }

    .shift-block {
        width: 100%;
        height: 32px;
        background: var(--deep-security-blue);
        border-radius: 2px;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-size: 8px;
        font-weight: bold;
        cursor: pointer;
        position: relative;
    }

    .shift-block.scheduled {
        background: var(--deep-security-blue);
    }

    .shift-block.active {
        background: var(--success-green);
    }

    .shift-block.completed {
        background: var(--text-muted);
    }

    .shift-block.warning {
        background: var(--warning-amber);
        color: var(--bg-dark);
    }

    .shift-block.error {
        background: var(--critical-red);
    }

    .shift-time {
        font-size: 7px;
        opacity: 0.9;
    }

    .wtr-warnings {
        margin-top: 16px;
        padding: 12px;
        background: var(--surface-dark);
        border: 1.5px solid var(--border-dark);
        border-radius: 4px;
    }

    .wtr-warning-item {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 6px 0;
        font-size: 11px;
        border-bottom: 1px solid var(--border-dark);
    }

    .wtr-warning-item:last-child {
        border-bottom: none;
    }

    .wtr-warning-item.warning {
        color: var(--warning-amber);
    }

    .wtr-warning-item.error {
        color: var(--critical-red);
    }

    .wtr-icon {
        font-weight: bold;
        font-size: 12px;
    }

    /* Shift Modal */
    .modal-overlay {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.8);
        display: none;
        z-index: 1000;
        justify-content: center;
        align-items: center;
    }

    .modal-overlay.show {
        display: flex;
    }

    .shift-modal {
        background: var(--surface-dark);
        border: 1.5px solid var(--border-dark);
        border-radius: 4px;
        width: 400px;
        max-width: 90vw;
        max-height: 90vh;
        overflow-y: auto;
        padding: 0;
    }

    .modal-header {
        padding: 16px;
        border-bottom: 1.5px solid var(--border-dark);
        display: flex;
        align-items: center;
        justify-content: space-between;
    }

    .modal-title {
        font-weight: bold;
        font-size: 13px;
    }

    .modal-close {
        background: transparent;
        border: none;
        color: var(--text-muted);
        font-size: 16px;
        cursor: pointer;
        padding: 0;
        width: 24px;
        height: 24px;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .modal-close:hover {
        color: var(--text-primary);
    }

    .modal-body {
        padding: 16px;
    }

    .form-group {
        margin-bottom: 16px;
    }

    .form-label {
        display: block;
        font-size: 10px;
        font-weight: bold;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        color: var(--text-muted);
        margin-bottom: 4px;
    }

    .form-input {
        width: 100%;
        padding: 8px;
        background: var(--bg-dark);
        border: 1.5px solid var(--border-dark);
        border-radius: 4px;
        color: var(--text-primary);
        font-size: 11px;
    }

    .form-input:focus {
        outline: none;
        border-color: var(--premium-gold);
    }

    .form-select {
        width: 100%;
        padding: 8px;
        background: var(--bg-dark);
        border: 1.5px solid var(--border-dark);
        border-radius: 4px;
        color: var(--text-primary);
        font-size: 11px;
        cursor: pointer;
    }

    .form-select:focus {
        outline: none;
        border-color: var(--premium-gold);
    }

    .form-row {
        display: flex;
        gap: 12px;
    }

    .form-row .form-group {
        flex: 1;
    }

    .wtr-check {
        padding: 12px;
        background: var(--bg-dark);
        border: 1.5px solid var(--border-dark);
        border-radius: 4px;
        margin-bottom: 16px;
        font-size: 11px;
    }

    .wtr-check.ok {
        border-color: var(--success-green);
        color: var(--success-green);
    }

    .wtr-check.warning {
        border-color: var(--warning-amber);
        color: var(--warning-amber);
    }

    .wtr-check.error {
        border-color: var(--critical-red);
        color: var(--critical-red);
    }

    /* Enhanced WTR Compliance Check */
    .wtr-compliance-check {
        padding: 14px;
        background: var(--bg-dark);
        border: 1.5px solid var(--border-dark);
        border-radius: 6px;
        margin-bottom: 16px;
        font-size: 11px;
    }

    .wtr-compliance-check.ok {
        border-color: var(--success-green);
        background: rgba(103, 194, 58, 0.05);
    }

    .wtr-compliance-check.warning {
        border-color: var(--warning-amber);
        background: rgba(255, 193, 7, 0.05);
    }

    .wtr-compliance-check.error {
        border-color: var(--critical-red);
        background: rgba(220, 53, 69, 0.05);
    }

    .wtr-status-header {
        display: flex;
        align-items: center;
        gap: 8px;
        font-weight: 600;
        margin-bottom: 8px;
    }

    .wtr-status-header .wtr-icon {
        font-size: 14px;
        font-weight: bold;
    }

    .wtr-violations {
        margin-top: 8px;
        padding-left: 20px;
    }

    .wtr-violations div {
        margin-bottom: 4px;
        color: var(--text-secondary);
        line-height: 1.4;
    }

    /* WTR Override Section */
    .wtr-override-section {
        background: var(--surface-dark);
        border: 1.5px solid var(--warning-amber);
        border-radius: 6px;
        margin-bottom: 16px;
        overflow: hidden;
    }

    .override-header {
        background: rgba(255, 193, 7, 0.1);
        padding: 12px 16px;
        border-bottom: 1px solid var(--border-dark);
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .override-header i {
        color: var(--warning-amber);
        font-size: 14px;
    }

    .override-header h4 {
        margin: 0;
        font-size: 12px;
        font-weight: 600;
        color: var(--text-primary);
    }

    .override-subtitle {
        font-size: 10px;
        color: var(--text-muted);
        margin-left: auto;
    }

    .override-content {
        padding: 16px;
    }

    .override-justification {
        resize: vertical;
        min-height: 70px;
        font-size: 11px;
        line-height: 1.4;
    }

    .field-hint {
        font-size: 10px;
        color: var(--text-muted);
        margin-top: 4px;
        font-style: italic;
    }

    .required-asterisk {
        color: var(--critical-red);
        font-weight: bold;
    }

    .override-options {
        margin: 16px 0;
        border: 1px solid var(--border-dark);
        border-radius: 4px;
        background: var(--bg-dark);
    }

    .override-option {
        border-bottom: 1px solid var(--border-dark);
    }

    .override-option:last-child {
        border-bottom: none;
    }

    .checkbox-label {
        display: flex;
        align-items: flex-start;
        gap: 12px;
        padding: 12px;
        cursor: pointer;
        transition: background-color 0.2s ease;
        margin: 0;
    }

    .checkbox-label:hover {
        background: rgba(255, 255, 255, 0.02);
    }

    .override-checkbox {
        display: none;
    }

    .checkbox-custom {
        width: 16px;
        height: 16px;
        border: 2px solid var(--border-dark);
        border-radius: 3px;
        position: relative;
        transition: all 0.2s ease;
        flex-shrink: 0;
        margin-top: 2px;
    }

    .override-checkbox:checked + .checkbox-custom {
        background: var(--warning-amber);
        border-color: var(--warning-amber);
    }

    .override-checkbox:checked + .checkbox-custom::after {
        content: "✓";
        position: absolute;
        top: -1px;
        left: 2px;
        color: var(--bg-dark);
        font-size: 12px;
        font-weight: bold;
    }

    .option-content {
        flex: 1;
    }

    .option-content strong {
        display: block;
        font-size: 11px;
        color: var(--text-primary);
        margin-bottom: 2px;
    }

    .option-content small {
        display: block;
        font-size: 10px;
        color: var(--text-secondary);
        line-height: 1.3;
    }

    .override-warning {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 10px;
        background: rgba(255, 193, 7, 0.05);
        border: 1px solid var(--warning-amber);
        border-radius: 4px;
        font-size: 10px;
        color: var(--text-muted);
        margin-top: 12px;
    }

    .override-warning i {
        color: var(--warning-amber);
        font-size: 12px;
    }

    .modal-actions {
        padding: 16px;
        border-top: 1.5px solid var(--border-dark);
        display: flex;
        gap: 8px;
        justify-content: flex-end;
    }

    .btn-modal {
        padding: 8px 16px;
        font-size: 11px;
        font-weight: bold;
        border-radius: 4px;
        cursor: pointer;
        transition: all 0.2s ease;
    }

    .btn-modal-primary {
        background: var(--deep-security-blue);
        color: white;
        border: 1px solid var(--deep-security-blue);
    }

    .btn-modal-primary:hover:not(:disabled) {
        background: transparent;
        color: var(--deep-security-blue);
    }

    .btn-modal-primary:disabled {
        opacity: 0.5;
        cursor: not-allowed;
    }

    .btn-modal-secondary {
        background: transparent;
        color: var(--text-secondary);
        border: 1px solid var(--border-dark);
    }

    .btn-modal-secondary:hover {
        border-color: var(--premium-gold);
        color: var(--premium-gold);
    }

    .empty-state {
        text-align: center;
        padding: 32px;
        color: var(--text-muted);
        font-size: 11px;
    }

    .loading-spinner {
        display: inline-block;
        width: 16px;
        height: 16px;
        border: 2px solid var(--border-dark);
        border-radius: 50%;
        border-top-color: var(--premium-gold);
        animation: spin 1s ease-in-out infinite;
    }

    @keyframes spin {
        to { transform: rotate(360deg); }
    }
</style>
@endsection

@section('content')
<div class="shifts-container">
    <!-- Calendar Grid -->
    <div class="calendar-container">
        <div class="calendar-grid" id="calendar-grid">
            <div class="calendar-header">Guard</div>
            <div class="calendar-header">Mon</div>
            <div class="calendar-header">Tue</div>
            <div class="calendar-header">Wed</div>
            <div class="calendar-header">Thu</div>
            <div class="calendar-header">Fri</div>
            <div class="calendar-header">Sat</div>
            <div class="calendar-header">Sun</div>

            <!-- Guard rows will be populated by JavaScript -->
        </div>
    </div>

    <!-- WTR Warnings -->
    <div id="wtr-warnings" class="wtr-warnings" style="display: none;">
        <div class="section-title">Working Time Regulations Warnings</div>
        <div id="wtr-warnings-list"></div>
    </div>
</div>

<!-- Shift Modal -->
<div id="shift-modal" class="modal-overlay">
    <div class="shift-modal">
        <div class="modal-header">
            <div class="modal-title">New Shift</div>
            <button class="modal-close" onclick="closeShiftModal()">&times;</button>
        </div>
        <form id="shift-form">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Guard</label>
                    <select id="guard-select" class="form-select" required>
                        <option value="">Select Guard</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Site</label>
                    <select id="site-select" class="form-select" required>
                        <option value="">Select Site</option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Date</label>
                    <input type="date" id="shift-date" class="form-input" required>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Start Time</label>
                        <input type="time" id="shift-start" class="form-input" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">End Time</label>
                        <input type="time" id="shift-end" class="form-input" required>
                    </div>
                </div>

                <!-- WTR Compliance Check -->
                <div id="wtr-check" class="wtr-compliance-check" style="display: none;">
                    <div class="wtr-status-header">
                        <i class="wtr-icon"></i>
                        <span id="wtr-check-text"></span>
                    </div>
                    <div id="wtr-violations-list" class="wtr-violations"></div>
                </div>

                <!-- WTR Override Section -->
                <div id="override-section" class="wtr-override-section" style="display: none;">
                    <div class="override-header">
                        <i class="icon-shield-alert"></i>
                        <h4>Working Time Regulations Override</h4>
                        <span class="override-subtitle">Administrative override requires documented justification</span>
                    </div>

                    <div class="override-content">
                        <div class="form-group">
                            <label class="form-label required">Justification <span class="required-asterisk">*</span></label>
                            <textarea id="override-justification" class="form-input override-justification" rows="3"
                                placeholder="Provide detailed justification for this WTR override (e.g., operational emergency, critical security requirements, etc.)..."></textarea>
                            <div class="field-hint">This override will be logged and may be subject to compliance audit.</div>
                        </div>

                        <div class="override-options">
                            <div class="override-option">
                                <label class="checkbox-label">
                                    <input type="checkbox" id="override-12hr-warning" class="override-checkbox">
                                    <span class="checkbox-custom"></span>
                                    <div class="option-content">
                                        <strong>Override 12-hour Duration Warning</strong>
                                        <small>Acknowledges shift exceeds recommended 12-hour limit</small>
                                    </div>
                                </label>
                            </div>

                            <div class="override-option">
                                <label class="checkbox-label">
                                    <input type="checkbox" id="override-11hr-rest" class="override-checkbox">
                                    <span class="checkbox-custom"></span>
                                    <div class="option-content">
                                        <strong>Override 11-hour Rest Period</strong>
                                        <small>Acknowledges insufficient rest time between shifts</small>
                                    </div>
                                </label>
                            </div>
                        </div>

                        <div class="override-warning">
                            <i class="icon-alert-triangle"></i>
                            <span>This override will be recorded for compliance monitoring and audit purposes.</span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-actions">
                <button type="button" class="btn-modal btn-modal-secondary" onclick="closeShiftModal()">Cancel</button>
                <button type="submit" class="btn-modal btn-modal-primary" id="save-shift-btn">Save Shift</button>
            </div>
        </form>
    </div>
</div>
@endsection

@section('scripts')
<script src="{{ asset('js/admin/shifts.js') }}"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        ShiftCalendar.init();
    });
</script>
@endsection