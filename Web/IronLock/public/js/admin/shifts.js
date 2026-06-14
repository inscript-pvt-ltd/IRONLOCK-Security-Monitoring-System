/**
 * Shift Calendar Management System
 *
 * Handles the weekly calendar interface for shift scheduling with:
 * - Working Time Regulations validation
 * - Real-time WTR compliance checking
 * - Guard and site assignment
 * - Interactive calendar grid
 */

class ShiftCalendar {
    constructor() {
        this.currentWeek = this.getCurrentWeekDates();
        this.guards = [];
        this.sites = [];
        this.shifts = [];
        this.editingShift = null;
        this.wtrValidationTimeout = null;

        this.bindEvents();
    }

    static init() {
        window.shiftCalendar = new ShiftCalendar();

        // Make functions globally accessible for onclick attributes
        window.closeShiftModal = () => window.shiftCalendar.closeShiftModal();
    }

    getCurrentWeekDates() {
        const today = new Date();
        const monday = new Date(today.setDate(today.getDate() - today.getDay() + 1));
        const dates = [];

        for (let i = 0; i < 7; i++) {
            const date = new Date(monday);
            date.setDate(monday.getDate() + i);
            dates.push(date);
        }

        return dates;
    }

    formatDate(date) {
        return date.toISOString().split('T')[0];
    }

    formatTime(dateTime) {
        return new Date(dateTime).toTimeString().slice(0, 5);
    }

    bindEvents() {
        // New shift button
        document.getElementById('new-shift-btn').addEventListener('click', () => {
            this.openShiftModal();
        });

        // Week selector
        document.getElementById('week-selector').addEventListener('change', (e) => {
            this.changeWeek(e.target.value);
        });

        // Shift form submission
        document.getElementById('shift-form').addEventListener('submit', (e) => {
            e.preventDefault();
            this.saveShift();
        });

        // Live WTR validation
        ['guard-select', 'shift-date', 'shift-start', 'shift-end'].forEach(id => {
            const element = document.getElementById(id);
            if (element) {
                element.addEventListener('change', () => {
                    this.validateWTR();
                });
            }
        });

        // Initial data load
        this.loadInitialData();
    }

    async loadInitialData() {
        try {
            await Promise.all([
                this.loadGuards(),
                this.loadSites(),
                this.loadShifts()
            ]);
            this.renderCalendar();
        } catch (error) {
            console.error('Failed to load initial data:', error);
            this.showError('Failed to load data. Please refresh the page.');
        }
    }

    async loadGuards() {
        try {
            const response = await fetch('/admin/guards/list');
            if (!response.ok) throw new Error('Failed to load guards');

            const data = await response.json();
            this.guards = data.guards || [];
            this.populateGuardSelect();
        } catch (error) {
            console.error('Error loading guards:', error);
            this.guards = [];
        }
    }

    async loadSites() {
        try {
            const response = await fetch('/admin/sites/list');
            if (!response.ok) throw new Error('Failed to load sites');

            const data = await response.json();
            this.sites = data.sites || [];
            this.populateSiteSelect();
        } catch (error) {
            console.error('Error loading sites:', error);
            this.sites = [];
        }
    }

    async loadShifts() {
        try {
            const startDate = this.formatDate(this.currentWeek[0]);
            const endDate = this.formatDate(this.currentWeek[6]);

            const response = await fetch(`/admin/shifts?date_from=${startDate}&date_to=${endDate}`);
            if (!response.ok) throw new Error('Failed to load shifts');

            const data = await response.json();
            this.shifts = data.shifts || [];
        } catch (error) {
            console.error('Error loading shifts:', error);
            this.shifts = [];
        }
    }

    populateGuardSelect() {
        const select = document.getElementById('guard-select');
        select.innerHTML = '<option value="">Select Guard</option>';

        this.guards.forEach(guard => {
            const option = document.createElement('option');
            option.value = guard.id;
            option.textContent = `${guard.first_name} ${guard.last_name}`;
            select.appendChild(option);
        });
    }

    populateSiteSelect() {
        const select = document.getElementById('site-select');
        select.innerHTML = '<option value="">Select Site</option>';

        this.sites.forEach(site => {
            const option = document.createElement('option');
            option.value = site.id;
            option.textContent = site.name;
            select.appendChild(option);
        });
    }

    renderCalendar() {
        const grid = document.getElementById('calendar-grid');

        // Clear existing guard rows (keep headers)
        const headers = grid.querySelectorAll('.calendar-header');
        grid.innerHTML = '';
        headers.forEach(header => grid.appendChild(header));

        // Group shifts by guard
        const shiftsByGuard = {};
        this.shifts.forEach(shift => {
            if (!shiftsByGuard[shift.guard_id]) {
                shiftsByGuard[shift.guard_id] = [];
            }
            shiftsByGuard[shift.guard_id].push(shift);
        });

        // Render each guard row
        this.guards.forEach(guard => {
            this.renderGuardRow(guard, shiftsByGuard[guard.id] || []);
        });

        this.renderWTRWarnings();
    }

    renderGuardRow(guard, guardShifts) {
        const grid = document.getElementById('calendar-grid');

        // Guard name cell
        const nameCell = document.createElement('div');
        nameCell.className = 'guard-name';
        nameCell.textContent = `${guard.first_name.charAt(0)}.${guard.last_name.charAt(0)}.`;
        nameCell.title = `${guard.first_name} ${guard.last_name}`;
        grid.appendChild(nameCell);

        // Day cells
        this.currentWeek.forEach((date, dayIndex) => {
            const dayCell = document.createElement('div');
            dayCell.className = 'day-cell';
            dayCell.dataset.guardId = guard.id;
            dayCell.dataset.date = this.formatDate(date);

            // Find shift for this day
            const dayShift = guardShifts.find(shift => {
                const shiftDate = new Date(shift.scheduled_start).toDateString();
                return shiftDate === date.toDateString();
            });

            if (dayShift) {
                const shiftBlock = this.createShiftBlock(dayShift);
                dayCell.appendChild(shiftBlock);
            } else {
                dayCell.addEventListener('click', () => {
                    this.openShiftModal(guard.id, date);
                });
            }

            grid.appendChild(dayCell);
        });
    }

    createShiftBlock(shift) {
        const block = document.createElement('div');
        block.className = `shift-block ${shift.status}`;

        // Determine block style based on WTR compliance
        const violations = shift.wtr_violations || [];
        if (violations.some(v => v.severity === 'ERROR')) {
            block.classList.add('error');
        } else if (violations.some(v => v.severity === 'WARNING')) {
            block.classList.add('warning');
        }

        const startTime = this.formatTime(shift.scheduled_start);
        const endTime = this.formatTime(shift.scheduled_end);

        block.innerHTML = `
            <div class="shift-time">${startTime}-${endTime}</div>
        `;

        block.addEventListener('click', () => {
            this.openShiftModal(shift.guard_id, new Date(shift.scheduled_start), shift);
        });

        return block;
    }

    renderWTRWarnings() {
        const warningsContainer = document.getElementById('wtr-warnings');
        const warningsList = document.getElementById('wtr-warnings-list');
        warningsList.innerHTML = '';

        const warnings = [];

        // Collect WTR warnings from all shifts
        this.shifts.forEach(shift => {
            const guard = this.guards.find(g => g.id === shift.guard_id);
            if (!guard) return;

            const violations = shift.wtr_violations || [];
            violations.forEach(violation => {
                warnings.push({
                    guard: `${guard.first_name} ${guard.last_name}`,
                    severity: violation.severity,
                    message: violation.message,
                    date: new Date(shift.scheduled_start).toLocaleDateString('en-GB', {
                        weekday: 'short'
                    })
                });
            });
        });

        if (warnings.length === 0) {
            warningsContainer.style.display = 'none';
            return;
        }

        warningsContainer.style.display = 'block';

        warnings.forEach(warning => {
            const item = document.createElement('div');
            item.className = `wtr-warning-item ${warning.severity.toLowerCase()}`;

            const icon = warning.severity === 'ERROR' ? '✗' : '⚠';
            item.innerHTML = `
                <span class="wtr-icon">${icon}</span>
                <span>${warning.guard}: ${warning.message} — ${warning.date}</span>
            `;

            warningsList.appendChild(item);
        });
    }

    openShiftModal(guardId = null, date = null, shift = null) {
        this.editingShift = shift;
        const modal = document.getElementById('shift-modal');
        const title = document.querySelector('.modal-title');

        title.textContent = shift ? 'Edit Shift' : 'New Shift';

        // Reset form
        document.getElementById('shift-form').reset();
        document.getElementById('wtr-check').style.display = 'none';
        document.getElementById('override-section').style.display = 'none';

        // Pre-fill form if data provided
        if (guardId) {
            document.getElementById('guard-select').value = guardId;
        }

        if (date) {
            document.getElementById('shift-date').value = this.formatDate(date);
        }

        if (shift) {
            document.getElementById('guard-select').value = shift.guard_id;
            document.getElementById('site-select').value = shift.site_id;
            document.getElementById('shift-date').value = this.formatDate(new Date(shift.scheduled_start));
            document.getElementById('shift-start').value = this.formatTime(shift.scheduled_start);
            document.getElementById('shift-end').value = this.formatTime(shift.scheduled_end);
        }

        modal.classList.add('show');

        // Validate WTR if form has data
        if (guardId && date) {
            this.validateWTR();
        }
    }

    closeShiftModal() {
        const modal = document.getElementById('shift-modal');
        modal.classList.remove('show');
        this.editingShift = null;
    }

    async validateWTR() {
        const guardId = document.getElementById('guard-select').value;
        const date = document.getElementById('shift-date').value;
        const startTime = document.getElementById('shift-start').value;
        const endTime = document.getElementById('shift-end').value;

        if (!guardId || !date || !startTime || !endTime) {
            document.getElementById('wtr-check').style.display = 'none';
            return;
        }

        const scheduledStart = `${date}T${startTime}:00`;
        const scheduledEnd = `${date}T${endTime}:00`;

        // Clear previous timeout
        if (this.wtrValidationTimeout) {
            clearTimeout(this.wtrValidationTimeout);
        }

        // Debounce validation requests
        this.wtrValidationTimeout = setTimeout(async () => {
            try {
                const response = await fetch('/admin/shifts/check-wtr', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                    },
                    body: JSON.stringify({
                        guard_id: guardId,
                        scheduled_start: scheduledStart,
                        scheduled_end: scheduledEnd
                    })
                });

                if (!response.ok) throw new Error('WTR validation failed');

                const data = await response.json();
                this.displayWTRResults(data.wtr_compliance);
            } catch (error) {
                console.error('WTR validation error:', error);
            }
        }, 500);
    }

    displayWTRResults(wtrData) {
        const wtrCheck = document.getElementById('wtr-check');
        const wtrText = document.getElementById('wtr-check-text');
        const wtrViolationsList = document.getElementById('wtr-violations-list');
        const overrideSection = document.getElementById('override-section');
        const saveBtn = document.getElementById('save-shift-btn');

        wtrCheck.style.display = 'block';
        wtrCheck.className = 'wtr-compliance-check';

        // Clear previous violations
        wtrViolationsList.innerHTML = '';

        if (wtrData.compliant) {
            wtrCheck.classList.add('ok');
            wtrText.innerHTML = '✓ Working Time Regulations: Compliant';
            overrideSection.style.display = 'none';
            saveBtn.disabled = false;
        } else {
            const hasBlockers = wtrData.violations.some(v => v.severity === 'ERROR');
            const hasWarnings = wtrData.violations.some(v => v.severity === 'WARNING');

            if (hasBlockers) {
                wtrCheck.classList.add('error');
                wtrText.innerHTML = '✗ WTR Compliance: Violations Detected';
                overrideSection.style.display = 'none';
                saveBtn.disabled = true;
            } else if (hasWarnings) {
                wtrCheck.classList.add('warning');
                wtrText.innerHTML = '⚠ WTR Compliance: Warnings Detected';
                overrideSection.style.display = 'block';
                saveBtn.disabled = false;
            }

            // Show violation details in separate list
            wtrData.violations.forEach(violation => {
                const violationDiv = document.createElement('div');
                const icon = violation.severity === 'ERROR' ? '✗' : '⚠';
                violationDiv.innerHTML = `${icon} ${violation.message}`;
                wtrViolationsList.appendChild(violationDiv);
            });

            // Automatically check relevant override checkboxes based on violations
            wtrData.violations.forEach(violation => {
                if (violation.type === 'DURATION_12HR_WARNING') {
                    // Don't auto-check, let admin consciously decide
                }
                if (violation.type === 'REST_PERIOD_11HR') {
                    // Don't auto-check, let admin consciously decide
                }
            });
        }
    }

    async saveShift() {
        const saveBtn = document.getElementById('save-shift-btn');
        const originalText = saveBtn.textContent;

        try {
            saveBtn.disabled = true;
            saveBtn.innerHTML = '<span class="loading-spinner"></span> Saving...';

            const formData = {
                guard_id: document.getElementById('guard-select').value,
                site_id: document.getElementById('site-select').value,
                scheduled_start: `${document.getElementById('shift-date').value}T${document.getElementById('shift-start').value}:00`,
                scheduled_end: `${document.getElementById('shift-date').value}T${document.getElementById('shift-end').value}:00`,
                override_12hr_warning: document.getElementById('override-12hr-warning').checked,
                override_11hr_rest: document.getElementById('override-11hr-rest').checked,
                override_justification: document.getElementById('override-justification').value
            };

            const url = this.editingShift
                ? `/admin/shifts/${this.editingShift.id}`
                : '/admin/shifts';

            const method = this.editingShift ? 'PUT' : 'POST';

            const response = await fetch(url, {
                method: method,
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                },
                body: JSON.stringify(formData)
            });

            const data = await response.json();

            if (!data.success) {
                if (data.wtr_warning) {
                    this.displayWTRResults({
                        compliant: false,
                        violations: data.violations
                    });
                    throw new Error(data.message || 'WTR compliance issues detected');
                }
                throw new Error(data.error || 'Failed to save shift');
            }

            this.showSuccess('Shift saved successfully');
            this.closeShiftModal();
            await this.loadShifts();
            this.renderCalendar();

        } catch (error) {
            console.error('Save shift error:', error);
            this.showError(error.message);
        } finally {
            saveBtn.disabled = false;
            saveBtn.textContent = originalText;
        }
    }

    changeWeek(direction) {
        const offset = direction === 'next' ? 7 : direction === 'prev' ? -7 : 0;

        this.currentWeek = this.currentWeek.map(date => {
            const newDate = new Date(date);
            newDate.setDate(date.getDate() + offset);
            return newDate;
        });

        this.loadShifts().then(() => {
            this.renderCalendar();
        });
    }

    showSuccess(message) {
        // Could integrate with the flash message system or show a toast
        console.log('Success:', message);
    }

    showError(message) {
        // Could integrate with the flash message system or show a toast
        console.error('Error:', message);
        alert(message); // Simple fallback for now
    }
}

// Global click handler for modal backdrop
document.addEventListener('click', function(e) {
    if (e.target.classList.contains('modal-overlay')) {
        const modal = document.getElementById('shift-modal');
        if (modal && modal.classList.contains('show')) {
            window.shiftCalendar.closeShiftModal();
        }
    }
});

// Initialize when DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', ShiftCalendar.init);
} else {
    ShiftCalendar.init();
}