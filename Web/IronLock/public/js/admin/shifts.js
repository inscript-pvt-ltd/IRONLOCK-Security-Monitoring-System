class ShiftCalendar {
    constructor() {
        this.currentWeek = this.getCurrentWeekDates();
        this.guards = [];
        this.sites = [];
        this.shifts = [];
        this.activeShifts = [];
        this.editingShift = null;
        this.wtrValidationTimeout = null;

        this.bindEvents();
    }

    static init() {
        // Idempotent: guard against being called more than once (e.g. a stray
        // inline init in the view). A second instance would bind a duplicate
        // form submit handler and double-fire saveShift().
        if (window.shiftCalendar) return;
        window.shiftCalendar = new ShiftCalendar();
        window.closeShiftDrawer = () => window.shiftCalendar.closeShiftDrawer();
    }

    // ── Week helpers ───────────────────────────────────────────

    getCurrentWeekDates() {
        const today = new Date();
        const monday = new Date(today);
        monday.setDate(today.getDate() - today.getDay() + 1);
        monday.setHours(0, 0, 0, 0);

        const dates = [];
        for (let i = 0; i < 7; i++) {
            const d = new Date(monday);
            d.setDate(monday.getDate() + i);
            dates.push(d);
        }
        return dates;
    }

    formatDate(date) {
        const p = n => String(n).padStart(2, '0');
        return `${date.getFullYear()}-${p(date.getMonth() + 1)}-${p(date.getDate())}`;
    }

    formatTime(dateTimeStr) {
        return new Date(dateTimeStr).toTimeString().slice(0, 5);
    }

    // ── Event binding ──────────────────────────────────────────

    bindEvents() {
        document.getElementById('new-shift-btn').addEventListener('click', () => {
            this.openShiftDrawer();
        });

        // Close drawer when clicking outside it and outside the calendar table
        document.addEventListener('click', (e) => {
            const drawer = document.getElementById('shift-drawer');
            if (!drawer || !drawer.classList.contains('open')) return;
            if (drawer.contains(e.target)) return;
            if (e.target.closest('#calendar-table, #new-shift-btn, #week-selector')) return;
            this.closeShiftDrawer();
        });

        document.getElementById('week-selector').addEventListener('change', (e) => {
            this.changeWeek(e.target.value);
        });

        document.getElementById('shift-form').addEventListener('submit', (e) => {
            e.preventDefault();
            this.saveShift();
        });

        // In-drawer resolve flow (missed / ended-early shifts).
        document.getElementById('resolve-open-btn').addEventListener('click', () => {
            this.showResolveForm(this.editingShift);
        });
        document.getElementById('resolve-back-btn').addEventListener('click', () => {
            this.hideResolveForm();
        });
        document.getElementById('resolve-confirm-btn').addEventListener('click', () => {
            this.submitResolve();
        });
        document.getElementById('resolve-outcome').addEventListener('change', () => {
            this.updateResolveGuardRow();
        });

        // In-drawer cancel flow (pre-start shifts).
        document.getElementById('cancel-shift-btn').addEventListener('click', () => {
            this.showCancelForm();
        });
        document.getElementById('cancel-back-btn').addEventListener('click', () => {
            this.hideCancelForm();
        });
        document.getElementById('cancel-confirm-btn').addEventListener('click', () => {
            this.submitCancel();
        });

        ['guard-select', 'shift-date', 'shift-start', 'shift-duration'].forEach(id => {
            const el = document.getElementById(id);
            if (el) {
                el.addEventListener('change', () => {
                    this.clearDrawerError();
                    this.updateEndTimeDisplay();
                    this.validateWTR();
                });
                // also react to typing in the duration number input
                if (id === 'shift-duration') {
                    el.addEventListener('input', () => {
                        this.clearDrawerError();
                        this.updateEndTimeDisplay();
                    });
                }
            }
        });

        this.loadInitialData();
        this.startAutoRefresh();
    }

    // ── Data loading ───────────────────────────────────────────

    async loadInitialData() {
        try {
            await Promise.all([this.loadGuards(), this.loadSites(), this.loadShifts()]);
            this.renderCalendar();
        } catch (error) {
            console.error('Failed to load initial data:', error);
        }
    }

    async loadGuards() {
        try {
            const res = await fetch('/admin/guards/list');
            if (!res.ok) throw new Error('Failed to load guards');
            const data = await res.json();
            this.guards = data.guards || [];
            this.populateGuardSelect();
        } catch (e) {
            console.error(e);
            this.guards = [];
        }
    }

    async loadSites() {
        try {
            const res = await fetch('/admin/sites/list');
            if (!res.ok) throw new Error('Failed to load sites');
            const data = await res.json();
            this.sites = data.sites || [];
            this.populateSiteSelect();
        } catch (e) {
            console.error(e);
            this.sites = [];
        }
    }

    async loadShifts() {
        try {
            const from = this.formatDate(this.currentWeek[0]);
            const to   = this.formatDate(this.currentWeek[6]);
            const res  = await fetch(`/admin/shifts?date_from=${from}&date_to=${to}`, {
                headers: { 'Accept': 'application/json' }
            });
            if (!res.ok) throw new Error('Failed to load shifts');
            const data = await res.json();
            // Shift times are stored in UTC on the server (ISO 8601 with a "Z").
            // Keep the zone intact so `new Date()` converts each timestamp to the
            // admin's local timezone for display, and round-trips back to UTC on
            // save. This is what keeps the stored instant aligned with the moment
            // the admin actually meant — and what the mobile login-window check
            // (which runs in UTC) compares against.
            this.shifts = data.shifts || [];
        } catch (e) {
            console.error(e);
            this.shifts = [];
        }

        // Active shifts are NOT week-bound — a shift in progress should always
        // show regardless of which week the calendar is viewing — so they are
        // fetched separately by status. Reuses the existing index endpoint.
        await this.loadActiveShifts();
    }

    async loadActiveShifts() {
        try {
            const res = await fetch('/admin/shifts?status=active', {
                headers: { 'Accept': 'application/json' }
            });
            if (!res.ok) throw new Error('Failed to load active shifts');
            const data = await res.json();
            this.activeShifts = data.shifts || [];
        } catch (e) {
            console.error(e);
            this.activeShifts = [];
        }
    }

    // ── Auto-refresh ───────────────────────────────────────────
    // The calendar reflects the *stored* shift status, not a live clock. Once
    // the backend `shifts:mark-missed` sweep flips an expired shift to
    // "missed", the cell only turns red after a re-fetch. Poll on an interval
    // so an open dashboard repaints itself without a manual reload.
    //
    // Skipped while a drawer is open (don't yank the calendar out from under an
    // admin mid-edit) and while the tab is hidden (no needless requests). The
    // 60s cadence matches the every-minute backend sweep — refreshing faster
    // wouldn't surface a change any sooner.
    startAutoRefresh(intervalMs = 60000) {
        if (this.refreshTimer) clearInterval(this.refreshTimer);
        this.refreshTimer = setInterval(() => {
            if (document.hidden) return;
            const drawer = document.getElementById('shift-drawer');
            if (drawer && drawer.classList.contains('open')) return;
            this.loadShifts().then(() => this.renderCalendar());
        }, intervalMs);
    }

    // Convert a wall-clock date+time the admin typed (interpreted in the admin's
    // own browser timezone) into a UTC ISO-8601 string for the API. The server
    // stores UTC, so applying the browser's offset here makes the stored instant
    // match the real moment the admin meant — and the mobile login-window check
    // lines up regardless of where the server, admin, or guard physically are.
    // Also handles British Summer Time automatically: a UK admin's browser knows
    // it is on BST, so 15:30 typed in summer becomes 14:30Z, not 15:30Z.
    localToUtcIso(dateTimeStr) {
        return new Date(dateTimeStr).toISOString();
    }

    populateGuardSelect() {
        const sel = document.getElementById('guard-select');
        sel.innerHTML = '<option value="">Select Guard</option>';
        this.guards.forEach(g => {
            const opt = document.createElement('option');
            opt.value = g.id;
            opt.textContent = `${g.first_name} ${g.last_name}`;
            sel.appendChild(opt);
        });
    }

    populateSiteSelect() {
        const sel = document.getElementById('site-select');
        sel.innerHTML = '<option value="">Select Site</option>';
        this.sites.forEach(s => {
            const opt = document.createElement('option');
            opt.value = s.id;
            opt.textContent = s.name;
            sel.appendChild(opt);
        });
    }

    // ── Calendar rendering ─────────────────────────────────────

    renderCalendar() {
        const table = document.getElementById('calendar-table');
        const days  = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

        const thead = `<thead><tr>
            <th style="width:90px;text-align:left;">Guard</th>
            ${this.currentWeek.map((d, i) => `<th>${days[i]} ${d.getDate()}</th>`).join('')}
        </tr></thead>`;

        const shiftsByGuard = {};
        this.shifts.forEach(s => {
            if (!shiftsByGuard[s.guard_id]) shiftsByGuard[s.guard_id] = [];
            shiftsByGuard[s.guard_id].push(s);
        });

        let tbody;
        if (this.guards.length === 0) {
            tbody = `<tbody><tr>
                <td colspan="8" style="text-align:center;padding:28px;color:var(--text-muted);font-size:11px;">
                    No guards found. Add guards first to schedule shifts.
                </td>
            </tr></tbody>`;
        } else {
            const rows = this.guards.map(g => this.buildGuardRow(g, shiftsByGuard[g.id] || [])).join('');
            tbody = `<tbody>${rows}</tbody>`;
        }

        table.innerHTML = thead + tbody;
        this.attachCellHandlers();
        this.renderActiveShifts();
        this.renderWTRWarnings();
        this.renderWeeklyHours();
        this.renderOverrideLog();
    }

    // ── Active shifts table ────────────────────────────────────

    renderActiveShifts() {
        const tbody = document.getElementById('active-shifts-tbody');
        if (!tbody) return;

        const rows = this.activeShifts || [];
        if (rows.length === 0) {
            tbody.innerHTML = `<tr><td colspan="5" class="active-empty">No shifts are currently active.</td></tr>`;
            return;
        }

        tbody.innerHTML = rows.map(shift => {
            // Prefer the eager-loaded relation; fall back to the guards list
            // already in memory (keyed by guard_id, as the calendar uses).
            const guard = shift.assigned_guard
                || this.guards.find(g => g.id === shift.guard_id)
                || {};
            const guardName = (guard.first_name || guard.last_name)
                ? `${guard.first_name || ''} ${guard.last_name || ''}`.trim()
                : 'Unassigned';
            const siteName = (shift.site && shift.site.name) ? shift.site.name : '—';

            const sched = `${this.fmtAmPm(new Date(shift.scheduled_start))}–${this.fmtAmPm(new Date(shift.scheduled_end))}`;
            const started = shift.actual_start
                ? ` · <span class="started">started ${this.fmtAmPm(new Date(shift.actual_start))}</span>`
                : '';

            const ref = shift.reference ? `#${shift.reference}` : '—';
            const viewUrl = `/admin/shifts/${shift.id}/timeline`;

            return `<tr>
                <td class="active-ref">${ref}</td>
                <td>${this.escapeHtml(siteName)}</td>
                <td class="active-guard">${this.escapeHtml(guardName)}</td>
                <td class="active-timeline">${sched}${started}</td>
                <td style="text-align:right;"><a href="${viewUrl}" class="active-view-btn">View</a></td>
            </tr>`;
        }).join('');
    }

    escapeHtml(str) {
        return String(str).replace(/[&<>"']/g, c => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        }[c]));
    }

    buildGuardRow(guard, guardShifts) {
        const name = `${guard.first_name.charAt(0)}. ${guard.last_name}`;

        const cells = [];
        const processedShifts = new Set(); // Track shifts we've already rendered to avoid duplicates

        // New logic: Overnight shifts that span exactly 2 days within the current week
        // are merged into a single cell with colspan="2" to avoid edit conflicts.

        for (let i = 0; i < this.currentWeek.length; i++) {
            const date = this.currentWeek[i];
            const dateStr = this.formatDate(date);

            // Skip this cell if it's already been consumed by an overnight shift
            if (cells.length > i) continue;

            // Shift that STARTS on this day
            const dayShift = guardShifts.find(s => {
                return new Date(s.scheduled_start).toDateString() === date.toDateString()
                       && !processedShifts.has(s.id);
            });

            if (dayShift) {
                processedShifts.add(dayShift.id);

                const startFmt = this.fmtAmPm(new Date(dayShift.scheduled_start));
                const endFmt   = this.fmtAmPm(new Date(dayShift.scheduled_end));
                const isOvernight = new Date(dayShift.scheduled_end).toDateString() !== date.toDateString();

                // Check if the overnight shift ends within our current week
                const endsInWeek = isOvernight && i < this.currentWeek.length - 1
                    && new Date(dayShift.scheduled_end).toDateString() === this.currentWeek[i + 1].toDateString();

                const violations = dayShift.wtr_violations || [];
                const hasWarn    = violations.some(v => v.severity === 'WARNING');
                const hasError   = violations.some(v => v.severity === 'ERROR');

                // A shift awaiting supervisor resolution (missed, or completed
                // but ended early) overrides the normal status colour: it turns
                // solid red and carries a ⚠ triangle so the admin can spot it.
                const needsResolve = !!dayShift.needs_resolution;

                let cls = 'shift-blk';
                if (needsResolve)                          cls += ' needs-resolve';
                else if (dayShift.status === 'active')     cls += ' active';
                else if (dayShift.status === 'checked_in') cls += ' checked-in';
                else if (dayShift.status === 'completed')  cls += ' completed';
                else if (dayShift.status === 'cancelled')  cls += ' cancelled';
                else if (dayShift.status === 'missed')     cls += ' missed';
                if (!needsResolve && (hasWarn || hasError)) cls += ' wtr-warn-block';

                const warnIcon = (!needsResolve && (hasWarn || hasError)) ? ' ⚠' : '';
                // Build the chip's inner HTML: a leading ⚠ flag for resolve, or
                // the trailing WTR warning icon otherwise.
                const wrap = (label) => needsResolve
                    ? `<span class="resolve-flag">⚠</span>${label}`
                    : `${label}${warnIcon}`;

                if (isOvernight && endsInWeek) {
                    // Overnight shift that ends within the week - merge into one cell spanning two days
                    const label = `${startFmt}–${endFmt}`;
                    cells.push(`<td colspan="2" class="overnight-merged shift-cell" data-shift-id="${dayShift.id}"><span class="${cls}" data-shift-id="${dayShift.id}">${wrap(label)}</span></td>`);

                    // Add placeholder for the consumed next cell so our indexing stays correct
                    cells.push(null); // This will be skipped in the final join
                } else {
                    // Regular shift (single day) or overnight that extends beyond the week
                    const label = isOvernight ? `${startFmt}–${endFmt} →` : `${startFmt}–${endFmt}`;
                    cells.push(`<td class="shift-cell" data-shift-id="${dayShift.id}"><span class="${cls}" data-shift-id="${dayShift.id}">${wrap(label)}</span></td>`);
                }
                continue;
            }

            // Check for overnight continuation from previous day (only if not already processed as merged cell)
            const prevDay = new Date(date);
            prevDay.setDate(date.getDate() - 1);
            const overnight = guardShifts.find(s => {
                return new Date(s.scheduled_start).toDateString() === prevDay.toDateString()
                    && new Date(s.scheduled_end).toDateString()   === date.toDateString()
                    && !processedShifts.has(s.id);
            });

            if (overnight) {
                // This should only happen if the shift started before our current week view
                processedShifts.add(overnight.id);
                const until = this.fmtAmPm(new Date(overnight.scheduled_end));
                cells.push(`<td class="shift-cell" data-shift-id="${overnight.id}"><span class="shift-blk shift-cont" data-shift-id="${overnight.id}" title="Overnight from previous day — until ${until}">← ${until}</span></td>`);
                continue;
            }

            // Empty cell — click to create a new shift
            cells.push(`<td class="day-cell" data-guard-id="${guard.id}" data-date="${dateStr}"></td>`);
        }

        // Filter out null placeholders and join
        const cellsHtml = cells.filter(cell => cell !== null).join('');

        return `<tr>
            <td class="guard-col" title="${guard.first_name} ${guard.last_name}">${name}</td>
            ${cellsHtml}
        </tr>`;
    }

    attachCellHandlers() {
        // Empty cells → open drawer for a NEW shift
        document.querySelectorAll('#calendar-table td.day-cell[data-guard-id]').forEach(td => {
            td.addEventListener('click', () => {
                const date = new Date(td.dataset.date + 'T00:00:00');
                this.openShiftDrawer(td.dataset.guardId, date);
            });
        });

        // Occupied cells → open drawer for EDITING. The whole cell is clickable
        // (not just the small text chip) so a click anywhere on the shift always
        // edits it. This prevents the dead-zone where clicking the cell padding
        // did nothing and the user re-created the shift via the New form, which
        // then collided with the existing one as a "conflict".
        document.querySelectorAll('#calendar-table td.shift-cell[data-shift-id]').forEach(td => {
            td.addEventListener('click', () => {
                const shift = this.shifts.find(s => s.id === td.dataset.shiftId);
                if (!shift) return;
                // Both missed and ended-early shifts open the edit drawer in
                // "resolve" mode (read-only fields + a Resolve button) rather
                // than the normal editable form. openShiftDrawer() detects the
                // needs_resolution flag and adjusts itself.
                this.openShiftDrawer(shift.guard_id, new Date(shift.scheduled_start), shift);
            });
        });
    }

    // ── Resolve flagged shift (supervisor recovery, in-drawer) ──
    // A missed or ended-early shift opens the edit drawer in resolve mode: the
    // form fields are shown read-only for context with an info banner and a
    // Resolve button. Resolve swaps the form for the resolve view; Back returns
    // to it. Everything lives inside #shift-drawer (no separate modal).

    // Human-readable explanation of why the shift needs resolving.
    resolutionMessage(shift) {
        const kind = shift.resolution_kind;
        if (kind === 'never_checked_in') {
            return {
                title: 'Missed — guard never checked in',
                body: 'The guard never signed in within the allowed window, so the check-in period expired. Choose how to resolve it.'
            };
        }
        if (kind === 'checked_in_no_start') {
            return {
                title: 'Missed — checked in but never started',
                body: 'The guard checked in but never started the shift before the start window closed. Choose how to resolve it.'
            };
        }
        if (kind === 'ended_early') {
            const end   = shift.actual_end ? this.fmtAmPm(new Date(shift.actual_end)) : '—';
            const sched = shift.scheduled_end ? this.fmtAmPm(new Date(shift.scheduled_end)) : '—';
            return {
                title: 'Ended early',
                body: `The guard ended the shift at ${end}, but it was scheduled until ${sched}. Approve the early finish or flag it as an incident.`
            };
        }
        return { title: 'Needs resolution', body: 'This shift needs supervisor attention.' };
    }

    // Outcome options offered for the shift's resolution kind.
    resolveOutcomes(shift) {
        if (shift.resolution_kind === 'ended_early') {
            return [
                { value: 'accept_early_end', label: 'Approve early finish (authorised)' },
                { value: 'flag_early_end',   label: 'Flag as incident (unexcused)' },
            ];
        }
        return [
            { value: 'authorize_late',  label: 'Authorize late check-in' },
            { value: 'excuse',          label: "Excuse (won't attend)" },
            { value: 'reassign',        label: 'Reassign to another guard' },
            { value: 'confirm_no_show', label: 'Confirm no-show' },
        ];
    }

    // Put the open drawer into resolve mode for a flagged shift.
    enterResolveFlaggedMode(shift) {
        const msg = this.resolutionMessage(shift);
        const banner = document.getElementById('resolve-banner');
        banner.innerHTML = `<strong>${this.escapeHtml(msg.title)}</strong>${this.escapeHtml(msg.body)}`;
        banner.style.display = 'block';

        document.getElementById('drawer-title-text').textContent = 'Resolve Shift';

        // Fields are context-only here — disable so the shift can't be edited
        // through the normal save path (which the backend blocks anyway).
        ['guard-select', 'site-select', 'shift-date', 'shift-start', 'shift-duration']
            .forEach(id => { const el = document.getElementById(id); if (el) el.disabled = true; });

        // Swap the Save button for Resolve.
        document.getElementById('save-shift-btn').style.display = 'none';
        document.getElementById('resolve-open-btn').style.display = 'block';
    }

    // Reset any resolve/cancel-mode UI back to the normal editable form. Called
    // on every drawer open so a previous session never leaks through.
    resetResolveUi() {
        document.getElementById('resolve-banner').style.display = 'none';
        document.getElementById('resolve-view').style.display = 'none';
        document.getElementById('shift-form').style.display = '';
        document.getElementById('save-shift-btn').style.display = 'block';
        document.getElementById('resolve-open-btn').style.display = 'none';
        this.resolvingShift = null;

        // Cancel-shift UI.
        document.getElementById('cancel-view').style.display = 'none';
        document.getElementById('cancel-shift-row').style.display = 'none';

        ['guard-select', 'site-select', 'shift-date', 'shift-start', 'shift-duration']
            .forEach(id => { const el = document.getElementById(id); if (el) el.disabled = false; });

        const err = document.getElementById('resolve-view-error');
        if (err) { err.style.display = 'none'; err.textContent = ''; }
        const cErr = document.getElementById('cancel-view-error');
        if (cErr) { cErr.style.display = 'none'; cErr.textContent = ''; }
    }

    // Swap the (read-only) edit form for the resolve view.
    showResolveForm(shift) {
        if (!shift) return;
        this.resolvingShift = shift;

        const msg = this.resolutionMessage(shift);
        const info = document.getElementById('resolve-view-info');
        info.innerHTML = `<strong>${this.escapeHtml(msg.title)}</strong>${this.escapeHtml(msg.body)}`;

        // Outcome options for this kind.
        const outcomeSel = document.getElementById('resolve-outcome');
        outcomeSel.innerHTML = this.resolveOutcomes(shift)
            .map(o => `<option value="${o.value}">${this.escapeHtml(o.label)}</option>`)
            .join('');

        // Reassign guard list (exclude the currently assigned guard).
        const guardSel = document.getElementById('resolve-guard');
        guardSel.innerHTML = '<option value="">Select guard</option>' + this.guards
            .filter(g => g.id !== shift.guard_id)
            .map(g => `<option value="${g.id}">${this.escapeHtml(g.first_name + ' ' + g.last_name)}</option>`)
            .join('');

        // Reset transient fields.
        document.getElementById('resolve-reason').value = 'emergency';
        document.getElementById('resolve-note').value = '';
        const err = document.getElementById('resolve-view-error');
        err.style.display = 'none'; err.textContent = '';
        this.updateResolveGuardRow();

        document.getElementById('shift-form').style.display = 'none';
        document.getElementById('resolve-view').style.display = 'block';
    }

    // Back from the resolve view to the (read-only) edit form.
    hideResolveForm() {
        document.getElementById('resolve-view').style.display = 'none';
        document.getElementById('shift-form').style.display = '';
    }

    // Show the reassign-guard row only for the reassign outcome.
    updateResolveGuardRow() {
        const outcome = document.getElementById('resolve-outcome').value;
        document.getElementById('resolve-guard-row').style.display =
            outcome === 'reassign' ? 'block' : 'none';
    }

    async submitResolve() {
        const shift = this.resolvingShift;
        if (!shift) return;

        const outcome = document.getElementById('resolve-outcome').value;
        const reason  = document.getElementById('resolve-reason').value;
        const note    = document.getElementById('resolve-note').value;
        const guardId = document.getElementById('resolve-guard').value;
        const errEl   = document.getElementById('resolve-view-error');
        const confirmBtn = document.getElementById('resolve-confirm-btn');

        errEl.style.display = 'none';

        if (outcome === 'reassign' && !guardId) {
            errEl.textContent = 'Please choose a guard to reassign the shift to.';
            errEl.style.display = 'block';
            return;
        }

        const body = { outcome, reason, note };
        if (outcome === 'reassign') body.guard_id = guardId;

        try {
            confirmBtn.disabled = true;
            confirmBtn.textContent = 'Resolving…';

            const res = await fetch(`/admin/shifts/${shift.id}/resolve`, {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                },
                body: JSON.stringify(body)
            });

            const data = await res.json();

            if (!data.success) {
                if (data.errors) {
                    errEl.textContent = Object.values(data.errors).flat().join('\n');
                } else if (data.conflicts && data.conflicts.length) {
                    errEl.textContent = data.error || 'The selected guard is already booked for that time.';
                } else {
                    errEl.textContent = data.error || 'Unable to resolve the shift.';
                }
                errEl.style.display = 'block';
                return;
            }

            this.closeShiftDrawer();
            await this.loadShifts();
            this.renderCalendar();
        } catch (e) {
            console.error('Resolve shift error:', e);
            errEl.textContent = 'Something went wrong. Please try again.';
            errEl.style.display = 'block';
        } finally {
            confirmBtn.disabled = false;
            confirmBtn.textContent = 'Resolve';
        }
    }

    // ── Cancel shift (pre-start: scheduling error / emergency) ──
    // Swaps the edit form for the cancel view (reason + note). Back returns.

    showCancelForm() {
        if (!this.editingShift) return;
        document.getElementById('cancel-reason').value = 'scheduling_error';
        document.getElementById('cancel-note').value = '';
        const err = document.getElementById('cancel-view-error');
        err.style.display = 'none'; err.textContent = '';

        document.getElementById('shift-form').style.display = 'none';
        document.getElementById('cancel-view').style.display = 'block';
    }

    hideCancelForm() {
        document.getElementById('cancel-view').style.display = 'none';
        document.getElementById('shift-form').style.display = '';
    }

    async submitCancel() {
        const shift = this.editingShift;
        if (!shift) return;

        const reason = document.getElementById('cancel-reason').value;
        const note   = document.getElementById('cancel-note').value;
        const errEl  = document.getElementById('cancel-view-error');
        const confirmBtn = document.getElementById('cancel-confirm-btn');

        errEl.style.display = 'none';

        try {
            confirmBtn.disabled = true;
            confirmBtn.textContent = 'Cancelling…';

            const res = await fetch(`/admin/shifts/${shift.id}/cancel`, {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                },
                body: JSON.stringify({ reason, note })
            });

            const data = await res.json();

            if (!data.success) {
                errEl.textContent = data.errors
                    ? Object.values(data.errors).flat().join('\n')
                    : (data.error || 'Unable to cancel the shift.');
                errEl.style.display = 'block';
                return;
            }

            this.closeShiftDrawer();
            await this.loadShifts();
            this.renderCalendar();
        } catch (e) {
            console.error('Cancel shift error:', e);
            errEl.textContent = 'Something went wrong. Please try again.';
            errEl.style.display = 'block';
        } finally {
            confirmBtn.disabled = false;
            confirmBtn.textContent = 'Cancel Shift';
        }
    }

    // ── WTR warnings below calendar ───────────────────────────

    renderWTRWarnings() {
        const section = document.getElementById('wtr-warnings-section');
        section.innerHTML = '';

        this.shifts.forEach(shift => {
            const guard = this.guards.find(g => g.id === shift.guard_id);
            if (!guard) return;

            const violations = shift.wtr_violations || [];
            violations.forEach(v => {
                const isError = v.severity === 'ERROR';
                const icon    = isError ? '✗' : '⚠';
                const dateStr = new Date(shift.scheduled_start).toLocaleDateString('en-GB', {
                    weekday: 'short', day: 'numeric', month: 'short'
                });

                const div = document.createElement('div');
                div.className = `wtr-warn${isError ? ' error dashed' : ''}`;
                div.textContent = `${icon} ${guard.first_name} ${guard.last_name}: ${v.message} — ${dateStr}`;
                section.appendChild(div);
            });
        });
    }

    // ── Weekly hours tracking ──────────────────────────────────

    renderWeeklyHours() {
        const tbody = document.getElementById('weekly-hours-tbody');
        if (!tbody) return;
        tbody.innerHTML = '';

        if (this.guards.length === 0) {
            tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;color:var(--text-muted);padding:12px;">No guards</td></tr>';
            return;
        }

        this.guards.forEach(guard => {
            const gShifts = this.shifts.filter(s => s.guard_id === guard.id && s.status !== 'cancelled');
            const totalMins = gShifts.reduce((sum, s) => {
                return sum + (new Date(s.scheduled_end) - new Date(s.scheduled_start)) / 60000;
            }, 0);
            const totalH = Math.round(totalMins / 60 * 10) / 10;

            let chipCls  = 'ok', chipTxt = '✓ OK';
            if (totalH > 48)      { chipCls = 'over'; chipTxt = '✗ Over 48h'; }
            else if (totalH >= 40){ chipCls = 'warn'; chipTxt = '⚠ Approaching 48h avg'; }

            const tr = document.createElement('tr');
            tr.innerHTML = `
                <td>${guard.first_name} ${guard.last_name}</td>
                <td>${totalH}h</td>
                <td style="color:var(--text-muted);">—</td>
                <td><span class="wtr-status-chip ${chipCls}">${chipTxt}</span></td>
            `;
            tbody.appendChild(tr);
        });
    }

    // ── Override log ───────────────────────────────────────────

    renderOverrideLog() {
        const allOverrides = [];
        this.shifts.forEach(shift => {
            (shift.working_time_overrides || []).forEach(ov => {
                allOverrides.push({ ov, shift });
            });
        });

        const summary = document.getElementById('override-log-summary');
        const toggle  = document.getElementById('override-log-toggle');
        const entries = document.getElementById('override-history-entries');
        const btn     = document.getElementById('override-history-btn');

        if (!summary) return;

        if (allOverrides.length === 0) {
            summary.textContent = '[ No overrides this week ]';
            toggle.style.display = 'none';
            entries.style.display = 'none';
            entries.innerHTML = '';
            return;
        }

        const n = allOverrides.length;
        summary.textContent = `${n} override${n !== 1 ? 's' : ''} this week`;
        toggle.style.display = 'inline';

        // Reset to collapsed on each re-render
        entries.style.display = 'none';
        btn.textContent = 'View override history ↓';

        entries.innerHTML = allOverrides.map(({ ov, shift }) => {
            const guard = this.guards.find(g => g.id === shift.guard_id);
            const guardName = guard ? `${guard.first_name} ${guard.last_name}` : 'Unknown guard';
            const shiftDate = new Date(shift.scheduled_start).toLocaleDateString('en-GB', {
                weekday: 'short', day: 'numeric', month: 'short'
            });
            const typeLabel = ov.override_type === 'duration_12hr'
                ? '12h duration warning'
                : '11h rest period';
            const adminName = ov.approved_by && typeof ov.approved_by === 'object'
                ? ov.approved_by.name
                : 'Admin';
            const approvedAt = ov.approved_at
                ? new Date(ov.approved_at).toLocaleDateString('en-GB', {
                    day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit'
                  })
                : '';
            return `<div class="override-entry">
                <strong>${guardName}</strong> — ${shiftDate} — ${typeLabel}<br>
                <span style="color:var(--text-secondary);">${ov.justification}</span><br>
                <span style="color:var(--text-muted);font-size:9px;">${adminName} · ${approvedAt}</span>
            </div>`;
        }).join('');

        btn.onclick = (e) => {
            e.preventDefault();
            const open = entries.style.display !== 'none';
            entries.style.display = open ? 'none' : 'block';
            btn.textContent = open ? 'View override history ↓' : 'Hide override history ↑';
        };
    }

    // ── Drawer open / close ────────────────────────────────────

    openShiftDrawer(guardId = null, date = null, shift = null) {
        this.editingShift = shift;

        document.getElementById('drawer-title-text').textContent = shift ? 'Edit Shift' : 'New Shift';

        // Reset all transient state
        document.getElementById('shift-form').reset();
        document.getElementById('wtr-check').style.display = 'none';
        document.getElementById('wtr-check').className = 'wtr-inline';
        document.getElementById('override-section').style.display = 'none';
        document.getElementById('computed-end-time').style.display = 'none';
        this.clearDrawerError();
        // Clear any leftover resolve-mode UI from a previous open.
        this.resetResolveUi();

        const saveBtn = document.getElementById('save-shift-btn');
        saveBtn.disabled   = false;
        saveBtn.textContent = 'Save';

        // Pre-fill
        if (guardId) document.getElementById('guard-select').value = guardId;
        if (date)    document.getElementById('shift-date').value   = this.formatDate(date);

        if (shift) {
            document.getElementById('guard-select').value = shift.guard_id;
            document.getElementById('site-select').value  = shift.site_id;
            document.getElementById('shift-date').value   = this.formatDate(new Date(shift.scheduled_start));
            document.getElementById('shift-start').value  = this.formatTime(shift.scheduled_start);
            const durationMs = new Date(shift.scheduled_end) - new Date(shift.scheduled_start);
            document.getElementById('shift-duration').value = Math.round(durationMs / 3600000 * 10) / 10;
            this.updateEndTimeDisplay();

            // Load existing WTR overrides if any (not in resolve mode — the
            // shift can't be edited there, so the WTR preview is irrelevant).
            if (!shift.needs_resolution) {
                this.loadExistingOverrides(shift);
            }
        }

        // A flagged shift (missed / ended-early) opens in resolve mode instead
        // of the editable form.
        const flagged = !!(shift && shift.needs_resolution);
        if (flagged) {
            this.enterResolveFlaggedMode(shift);
        } else if (shift && (shift.status === 'scheduled' || shift.status === 'checked_in')) {
            // Editing a shift that hasn't started yet — offer Cancel Shift.
            document.getElementById('cancel-shift-row').style.display = 'flex';
        }

        document.getElementById('shift-drawer').classList.add('open');
        document.querySelector('.shifts-main').classList.add('drawer-open');

        if (guardId && date && !flagged) this.validateWTR();
    }

    closeShiftDrawer() {
        document.getElementById('shift-drawer').classList.remove('open');
        document.querySelector('.shifts-main').classList.remove('drawer-open');
        this.editingShift = null;
    }

    // Load existing WTR overrides when editing a shift
    loadExistingOverrides(shift) {
        const overrides = shift.working_time_overrides || [];
        if (overrides.length === 0) return;

        // Show override section since we have existing overrides
        document.getElementById('override-section').style.display = 'block';

        // Get the most recent override justification (they should all have the same justification)
        const mostRecentOverride = overrides[overrides.length - 1];
        if (mostRecentOverride.justification) {
            document.getElementById('override-justification').value = mostRecentOverride.justification;
        }

        // Check the appropriate override checkboxes based on what overrides exist
        const has12hrOverride = overrides.some(ov => ov.override_type === 'duration_12hr');
        const has11hrOverride = overrides.some(ov => ov.override_type === 'rest_period_11hr');

        document.getElementById('override-12hr-warning').checked = has12hrOverride;
        document.getElementById('override-11hr-rest').checked = has11hrOverride;

        // Also trigger WTR validation to show current status
        setTimeout(() => this.validateWTR(), 100);
    }

    // ── Time formatting ────────────────────────────────────────

    // Returns compact AM/PM label: "9AM", "4PM", "3:25AM", "11:30PM"
    fmtAmPm(dt) {
        const h = dt.getHours(), m = dt.getMinutes();
        const period = h >= 12 ? 'PM' : 'AM';
        const h12 = h % 12 || 12;
        return m === 0 ? `${h12}${period}` : `${h12}:${m.toString().padStart(2, '0')}${period}`;
    }

    // ── End-time helpers ───────────────────────────────────────

    computeScheduledEnd() {
        const date     = document.getElementById('shift-date').value;
        const start    = document.getElementById('shift-start').value;
        const duration = parseFloat(document.getElementById('shift-duration').value);
        if (!date || !start || isNaN(duration) || duration <= 0) return null;
        return new Date(new Date(`${date}T${start}:00`).getTime() + duration * 3600000);
    }

    updateEndTimeDisplay() {
        const endDt   = this.computeScheduledEnd();
        const display = document.getElementById('computed-end-time');
        const span    = document.getElementById('computed-end-display');
        if (!endDt) { display.style.display = 'none'; return; }

        const startDate = document.getElementById('shift-date').value;
        const endDate   = this.formatDate(endDt);
        const ampm = endDt.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true });
        span.textContent = endDate !== startDate ? `${ampm} (+1 day)` : ampm;
        display.style.display = 'block';
    }

    // ── WTR live validation ────────────────────────────────────

    async validateWTR() {
        const guardId = document.getElementById('guard-select').value;
        const date    = document.getElementById('shift-date').value;
        const start   = document.getElementById('shift-start').value;
        const endDt   = this.computeScheduledEnd();

        if (!guardId || !date || !start || !endDt) {
            document.getElementById('wtr-check').style.display = 'none';
            return;
        }

        if (this.wtrValidationTimeout) clearTimeout(this.wtrValidationTimeout);

        this.wtrValidationTimeout = setTimeout(async () => {
            try {
                const res = await fetch('/admin/shifts/check-wtr', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                    },
                    body: JSON.stringify({
                        guard_id:        guardId,
                        scheduled_start: this.localToUtcIso(`${date}T${start}:00`),
                        scheduled_end:   endDt.toISOString(),
                        // When editing, exclude this shift from its own rest-period
                        // check so the live preview matches what saving will do.
                        shift_id:        this.editingShift ? this.editingShift.id : null
                    })
                });

                if (!res.ok) throw new Error('WTR check failed');
                const data = await res.json();
                this.displayWTRResults(data.wtr_compliance);
            } catch (e) {
                console.error('WTR validation error:', e);
            }
        }, 500);
    }

    displayWTRResults(wtrData) {
        const wtrCheck      = document.getElementById('wtr-check');
        const wtrText       = document.getElementById('wtr-check-text');
        const violationsList = document.getElementById('wtr-violations-list');
        const overrideSec   = document.getElementById('override-section');
        const saveBtn       = document.getElementById('save-shift-btn');

        wtrCheck.style.display = 'block';
        wtrCheck.className     = 'wtr-inline';
        violationsList.innerHTML = '';

        if (wtrData.compliant) {
            wtrCheck.classList.add('ok');
            wtrText.textContent = '✓ OK — Compliant';
            overrideSec.style.display = 'none';
            this.toggleOverrideRow('12hr', false);
            this.toggleOverrideRow('11hr', false);
            saveBtn.disabled    = false;
            return;
        }

        const hasBlockers = wtrData.violations.some(v => v.severity === 'ERROR');
        const hasWarnings = wtrData.violations.some(v => v.severity === 'WARNING');

        // Only surface the override checkbox for a warning that is actually
        // active. A 12.5h shift with no neighbouring shift triggers the 12h
        // warning but cannot breach the 11h rest rule, so showing (and letting
        // the admin tick) the rest override would record an override the backend
        // has nothing to persist — it silently vanishes on reload.
        const has12hr = wtrData.violations.some(v => v.type === 'DURATION_12HR_WARNING');
        const has11hr = wtrData.violations.some(v => v.type === 'REST_PERIOD_11HR');
        this.toggleOverrideRow('12hr', has12hr);
        this.toggleOverrideRow('11hr', has11hr);

        if (hasBlockers) {
            wtrCheck.classList.add('error');
            wtrText.textContent = '✗ Blocked — WTR Violation';
            overrideSec.style.display = 'none';
            saveBtn.disabled    = true;
            saveBtn.textContent = 'Save';
        } else if (hasWarnings) {
            wtrCheck.classList.add('warning');
            wtrText.textContent = '⚠ Warning — Confirm to Save';
            overrideSec.style.display = 'block';
            saveBtn.disabled    = false;
            saveBtn.textContent = 'Save';
        }

        wtrData.violations.forEach(v => {
            const div  = document.createElement('div');
            const icon = v.severity === 'ERROR' ? '✗' : '⚠';
            div.textContent = `${icon} ${v.message}`;
            violationsList.appendChild(div);
        });
    }

    // Show/hide a single override checkbox row. When hidden, the checkbox is
    // cleared so a previously-ticked acknowledgement for a warning that no
    // longer applies is never submitted (the backend would discard it anyway).
    toggleOverrideRow(kind, show) {
        const row = document.getElementById(`override-row-${kind}`);
        const cb  = document.getElementById(kind === '12hr' ? 'override-12hr-warning' : 'override-11hr-rest');
        if (row) row.style.display = show ? 'flex' : 'none';
        if (cb && !show) cb.checked = false;
    }

    // ── Save shift ─────────────────────────────────────────────

    async saveShift() {
        const saveBtn = document.getElementById('save-shift-btn');

        const date      = document.getElementById('shift-date').value;
        const startTime = document.getElementById('shift-start').value;
        const endDt     = this.computeScheduledEnd();

        if (!endDt) {
            this.showError('Please set a start time and duration.');
            return;
        }

        try {
            saveBtn.disabled  = true;
            saveBtn.innerHTML = '<span class="loading-spinner"></span> Saving…';

            const formData = {
                guard_id:              document.getElementById('guard-select').value,
                site_id:               document.getElementById('site-select').value,
                scheduled_start:       this.localToUtcIso(`${date}T${startTime}:00`),
                scheduled_end:         endDt.toISOString(),
                override_12hr_warning: document.getElementById('override-12hr-warning').checked,
                override_11hr_rest:    document.getElementById('override-11hr-rest').checked,
                override_justification: document.getElementById('override-justification').value
            };

            const url    = this.editingShift ? `/admin/shifts/${this.editingShift.id}` : '/admin/shifts';
            const method = this.editingShift ? 'PUT' : 'POST';

            const res  = await fetch(url, {
                method,
                headers: {
                    'Content-Type': 'application/json',
                    'Accept':       'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                },
                body: JSON.stringify(formData)
            });

            const data = await res.json();

            if (!data.success) {
                // WTR warning returned from backend
                if (data.wtr_warning) {
                    this.displayWTRResults({ compliant: false, violations: data.violations });
                    throw new Error(data.message || 'WTR compliance issues detected.');
                }
                // Laravel validation errors
                if (data.errors) {
                    const msgs = Object.values(data.errors).flat();
                    throw new Error(msgs.join('\n'));
                }
                // Shift conflict details
                if (data.conflicts && data.conflicts.length) {
                    const lines = data.conflicts.map(c => {
                        const startDt = new Date(c.start);
                        const endDt   = new Date(c.end);
                        const start = this.fmtAmPm(startDt);
                        const end   = this.fmtAmPm(endDt);
                        const day   = startDt.toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short' });
                        return `• ${c.site_name}: ${day} ${start}–${end} (${c.status})`;
                    });
                    // When creating, the overlap is usually the shift the user
                    // actually meant to edit. Point them at the edit path instead
                    // of leaving them stuck re-saving a duplicate.
                    const hint = this.editingShift
                        ? ''
                        : '\n\nThis guard is already booked for that time. To change the existing shift, close this and click it on the calendar instead of creating a new one.';
                    throw new Error('Shift conflicts with existing schedule:\n' + lines.join('\n') + hint);
                }
                throw new Error(data.error || 'Failed to save shift.');
            }

            this.closeShiftDrawer();
            await this.loadShifts();
            this.renderCalendar();

        } catch (error) {
            console.error('Save shift error:', error);
            this.showError(error.message);
        } finally {
            saveBtn.disabled    = false;
            saveBtn.textContent = 'Save';
        }
    }

    // ── Week navigation ────────────────────────────────────────

    changeWeek(direction) {
        const offset = direction === 'next' ? 7 : direction === 'prev' ? -7 : 0;
        this.currentWeek = this.currentWeek.map(d => {
            const nd = new Date(d);
            nd.setDate(d.getDate() + offset);
            return nd;
        });
        this.loadShifts().then(() => this.renderCalendar());
    }

    // ── Error helpers ──────────────────────────────────────────

    showError(message) {
        const el = document.getElementById('shift-drawer-error');
        if (el) { el.textContent = message; el.style.display = 'block'; }
    }

    clearDrawerError() {
        const el = document.getElementById('shift-drawer-error');
        if (el) { el.textContent = ''; el.style.display = 'none'; }
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', ShiftCalendar.init);
} else {
    ShiftCalendar.init();
}
