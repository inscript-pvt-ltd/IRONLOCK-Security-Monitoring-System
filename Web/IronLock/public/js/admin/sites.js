/**
 * Site Management JavaScript - Interactive map and CRUD operations
 * Part of IronLock Phase 3: Core Location System Implementation
 */

let map, currentMarker, sites = [];
let currentMode = 'add'; // 'add', 'edit', 'view'
let currentSiteId = null;
let siteToDelete = null;

// Initialize site management system
document.addEventListener('DOMContentLoaded', function() {
    console.log('Site management JavaScript loaded successfully!');

    // Check if required elements exist
    const siteDrawer = document.getElementById('siteDrawer');
    const drawerOverlay = document.querySelector('.drawer-overlay');
    console.log('Site drawer element:', siteDrawer);
    console.log('Drawer overlay element:', drawerOverlay);

    // Test the openSiteDrawer function directly
    window.testSiteDrawer = function() {
        console.log('Testing site drawer...');
        openSiteDrawer('add');
    };

    console.log('You can test the drawer by running: testSiteDrawer() in the browser console');

    initializeMap();
    setupEventListeners();
    loadSiteMarkers();

    // Make functions globally accessible for onclick attributes
    window.openSiteDrawer = openSiteDrawer;
    window.closeSiteDrawer = closeSiteDrawer;
    window.confirmDelete = confirmDelete;
    window.cancelDelete = cancelDelete;
    window.executeDelete = executeDelete;
});

// Initialize Leaflet map
function initializeMap() {
    // Center map on UK (can be configured per deployment)
    map = L.map('siteMap').setView([54.5, -2.0], 6);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
        maxZoom: 18
    }).addTo(map);

    // Map click handler for setting coordinates
    map.on('click', function(e) {
        if (currentMode !== 'view') {
            setCoordinates(e.latlng.lat, e.latlng.lng);
        }
    });
}

// Set coordinates from map click
function setCoordinates(lat, lng) {
    document.getElementById('latitude').value = lat.toFixed(6);
    document.getElementById('longitude').value = lng.toFixed(6);
    updateCoordinateDisplay();
    updateMarker(lat, lng);
}

// Update coordinate display
function updateCoordinateDisplay() {
    const lat = document.getElementById('latitude').value;
    const lng = document.getElementById('longitude').value;
    const display = document.getElementById('coordinateDisplay');

    if (lat && lng) {
        display.textContent = `${parseFloat(lat).toFixed(6)}, ${parseFloat(lng).toFixed(6)}`;
        display.style.color = 'var(--success-green)';
    } else {
        display.textContent = 'Click on map to set coordinates';
        display.style.color = 'var(--text-muted)';
    }
}

// Update or create marker on map
function updateMarker(lat, lng) {
    if (currentMarker) {
        map.removeLayer(currentMarker);
    }

    currentMarker = L.marker([lat, lng], {
        draggable: currentMode !== 'view'
    }).addTo(map);

    if (currentMode !== 'view') {
        currentMarker.on('dragend', function(e) {
            const pos = e.target.getLatLng();
            setCoordinates(pos.lat, pos.lng);
        });
    }

    map.setView([lat, lng], 13);
}

// Load existing site markers on map
function loadSiteMarkers() {
    sites.forEach(site => {
        if (site.latitude && site.longitude) {
            const marker = L.marker([site.latitude, site.longitude])
                .bindPopup(`
                    <div style="font-size: 12px;">
                        <strong style="color: var(--deep-security-blue);">${site.name}</strong><br>
                        <div style="margin: 4px 0; color: var(--text-secondary);">${site.address}</div>
                        <div style="font-size: 10px; color: var(--text-muted);">
                            Grace: ${site.grace_period_minutes}min | Status: ${site.status}
                        </div>
                    </div>
                `)
                .addTo(map);
        }
    });
}

// Setup event listeners
function setupEventListeners() {
    // Form submission
    document.getElementById('siteForm').addEventListener('submit', handleFormSubmit);

    // Coordinate input changes
    document.getElementById('latitude').addEventListener('input', updateCoordinateDisplay);
    document.getElementById('longitude').addEventListener('input', updateCoordinateDisplay);

    // Search functionality
    document.getElementById('searchInput').addEventListener('input', handleSearch);
    document.getElementById('statusFilter').addEventListener('change', handleSearch);

    // Add Site button in topbar
    const addSiteBtn = document.querySelector('.btn-primary-sm');
    if (addSiteBtn) {
        addSiteBtn.addEventListener('click', function(e) {
            e.preventDefault();
            console.log('Add site button clicked!');
            openSiteDrawer('add');
        });
    }

    // Add your first site button (for empty state)
    const firstSiteBtn = document.querySelector('button[onclick*="openSiteDrawer"]');
    if (firstSiteBtn) {
        firstSiteBtn.addEventListener('click', function(e) {
            e.preventDefault();
            console.log('First site button clicked!');
            openSiteDrawer('add');
        });
    }

    // Error clearing on input
    setupErrorClearing();
}

// Setup error clearing for form inputs
function setupErrorClearing() {
    const inputs = document.querySelectorAll('.drawer-input');
    inputs.forEach(input => {
        input.addEventListener('input', function() {
            clearFieldError(input.name || input.id);
        });
    });
}

// Open site drawer for add/edit/view
function openSiteDrawer(mode, siteId = null) {
    console.log('Opening site drawer in mode:', mode); // Debug log

    currentMode = mode;
    currentSiteId = siteId;

    const drawer = document.getElementById('siteDrawer');
    const overlay = document.querySelector('.drawer-overlay');
    const title = document.getElementById('drawerTitle');
    const submitBtn = document.getElementById('submitBtn');

    // Debug: Check if elements exist
    console.log('Drawer element:', drawer);
    console.log('Overlay element:', overlay);

    if (!drawer || !overlay) {
        console.error('Missing drawer elements!');
        return;
    }

    // Clear previous errors
    clearAllErrors();

    // Set mode-specific configurations
    switch(mode) {
        case 'add':
            title.textContent = 'Add New Site';
            submitBtn.textContent = 'Create Site';
            submitBtn.style.display = 'block';
            resetForm();
            break;

        case 'edit':
            title.textContent = 'Edit Site';
            submitBtn.textContent = 'Update Site';
            submitBtn.style.display = 'block';
            loadSiteData(siteId);
            break;

        case 'view':
            title.textContent = 'Site Details';
            submitBtn.style.display = 'none';
            loadSiteData(siteId);
            setFormReadonly(true);
            break;
    }

    drawer.classList.add('open');
    overlay.classList.add('show');
    document.body.style.overflow = 'hidden';
}

// Close site drawer
function closeSiteDrawer() {
    const drawer = document.getElementById('siteDrawer');
    const overlay = document.querySelector('.drawer-overlay');

    drawer.classList.remove('open');
    overlay.classList.remove('show');
    document.body.style.overflow = '';

    setFormReadonly(false);

    if (currentMarker) {
        map.removeLayer(currentMarker);
        currentMarker = null;
    }
}

// Reset form to defaults
function resetForm() {
    document.getElementById('siteForm').reset();
    document.getElementById('siteId').value = '';
    document.getElementById('grace_period_minutes').value = 5;
    updateCoordinateDisplay();

    if (currentMarker) {
        map.removeLayer(currentMarker);
        currentMarker = null;
    }
}

// Load site data for edit/view
async function loadSiteData(siteId) {
    try {
        const response = await fetch(`/admin/sites/${siteId}`, {
            headers: {
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            }
        });

        const data = await response.json();

        if (data.success) {
            const site = data.site;

            // Populate form fields
            document.getElementById('siteId').value = site.id;
            document.getElementById('name').value = site.name || '';
            document.getElementById('address').value = site.address || '';
            document.getElementById('latitude').value = site.latitude || '';
            document.getElementById('longitude').value = site.longitude || '';
            document.getElementById('grace_period_minutes').value = site.grace_period_minutes || 5;
            document.getElementById('contact_person').value = site.contact_person || '';
            document.getElementById('contact_phone').value = site.contact_phone || '';
            document.getElementById('instructions').value = site.instructions || '';
            document.getElementById('status').value = site.status || 'active';

            updateCoordinateDisplay();

            // Update map marker if coordinates exist
            if (site.latitude && site.longitude) {
                updateMarker(parseFloat(site.latitude), parseFloat(site.longitude));
            }
        } else {
            showErrorToast('Error loading site data');
        }
    } catch (error) {
        console.error('Error loading site:', error);
        showErrorToast('Error loading site data');
    }
}

// Set form readonly state
function setFormReadonly(readonly) {
    const inputs = document.querySelectorAll('.drawer-input');
    inputs.forEach(input => {
        input.readOnly = readonly;
        if (readonly) {
            input.style.backgroundColor = 'var(--bg-dark)';
            input.style.pointerEvents = 'none';
        } else {
            input.style.backgroundColor = '';
            input.style.pointerEvents = '';
        }
    });
}

// Handle form submission
async function handleFormSubmit(e) {
    e.preventDefault();

    const submitBtn = document.getElementById('submitBtn');
    const originalText = submitBtn.textContent;
    submitBtn.textContent = 'Saving...';
    submitBtn.disabled = true;

    try {
        const formData = new FormData(document.getElementById('siteForm'));
        const url = currentMode === 'add' ? '/admin/sites' : `/admin/sites/${currentSiteId}`;
        const method = currentMode === 'add' ? 'POST' : 'PUT';

        // Convert FormData to regular object for PUT requests
        const data = {};
        for (let [key, value] of formData.entries()) {
            data[key] = value;
        }

        const response = await fetch(url, {
            method: method,
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            },
            body: JSON.stringify(data)
        });

        const result = await response.json();

        if (result.success) {
            showSuccessToast(result.message);
            closeSiteDrawer();
            setTimeout(() => location.reload(), 1000);
        } else {
            if (result.errors) {
                displayValidationErrors(result.errors);
            } else {
                showErrorToast(result.error || 'Error saving site');
            }
        }
    } catch (error) {
        console.error('Error saving site:', error);
        showErrorToast('Error saving site');
    } finally {
        submitBtn.textContent = originalText;
        submitBtn.disabled = false;
    }
}

// Display validation errors
function displayValidationErrors(errors) {
    clearAllErrors();

    const errorSummary = document.getElementById('errorSummary');
    const errorList = document.getElementById('errorList');
    errorList.innerHTML = '';

    Object.keys(errors).forEach(field => {
        const messages = Array.isArray(errors[field]) ? errors[field] : [errors[field]];

        messages.forEach(message => {
            const li = document.createElement('li');
            li.textContent = message;
            errorList.appendChild(li);

            // Highlight field with error
            const fieldElement = document.getElementById(field);
            const errorElement = document.getElementById(`error_${field}`);

            if (fieldElement) {
                fieldElement.classList.add('error');
            }

            if (errorElement) {
                errorElement.textContent = message;
            }
        });
    });

    errorSummary.classList.add('show');
}

// Clear field error
function clearFieldError(fieldName) {
    const fieldElement = document.getElementById(fieldName);
    const errorElement = document.getElementById(`error_${fieldName}`);

    if (fieldElement) {
        fieldElement.classList.remove('error');
    }

    if (errorElement) {
        errorElement.textContent = '';
    }

    // Check if all errors are cleared
    const remainingErrors = document.querySelectorAll('.field-error');
    const hasErrors = Array.from(remainingErrors).some(el => el.textContent.trim() !== '');

    if (!hasErrors) {
        document.getElementById('errorSummary').classList.remove('show');
    }
}

// Clear all errors
function clearAllErrors() {
    document.getElementById('errorSummary').classList.remove('show');
    document.querySelectorAll('.field-error').forEach(el => el.textContent = '');
    document.querySelectorAll('.drawer-input').forEach(el => el.classList.remove('error'));
}

// Toggle site status
async function toggleSiteStatus(siteId, action) {
    try {
        const response = await fetch(`/admin/sites/${siteId}/toggle-status`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            }
        });

        const result = await response.json();

        if (result.success) {
            showSuccessToast(result.message);
            setTimeout(() => location.reload(), 1000);
        } else {
            showErrorToast(result.error || 'Error updating site status');
        }
    } catch (error) {
        console.error('Error toggling site status:', error);
        showErrorToast('Error updating site status');
    }
}

// Delete site functionality
function deleteSite(siteId, siteName) {
    siteToDelete = siteId;
    document.getElementById('deleteSiteName').textContent = siteName;
    document.getElementById('deleteConfirmation').style.display = 'flex';
}

function cancelDelete() {
    siteToDelete = null;
    document.getElementById('deleteConfirmation').style.display = 'none';
}

async function confirmDelete() {
    if (!siteToDelete) return;

    const confirmBtn = document.querySelector('.delete-btn.confirm');
    const originalText = confirmBtn.textContent;
    confirmBtn.textContent = 'DELETING...';
    confirmBtn.disabled = true;

    try {
        const response = await fetch(`/admin/sites/${siteToDelete}`, {
            method: 'DELETE',
            headers: {
                'Accept': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
            }
        });

        const result = await response.json();

        if (result.success) {
            showSuccessToast(result.message);
            cancelDelete();
            setTimeout(() => location.reload(), 1000);
        } else {
            showErrorToast(result.error || 'Error deleting site');
        }
    } catch (error) {
        console.error('Error deleting site:', error);
        showErrorToast('Error deleting site');
    } finally {
        confirmBtn.textContent = originalText;
        confirmBtn.disabled = false;
    }
}

// Search and filter functionality
function handleSearch() {
    const searchTerm = document.getElementById('searchInput').value.toLowerCase();
    const statusFilter = document.getElementById('statusFilter').value;

    // In a real implementation, this would make an AJAX request
    // For now, we'll reload the page with query parameters
    const params = new URLSearchParams();
    if (searchTerm) params.append('search', searchTerm);
    if (statusFilter) params.append('status', statusFilter);

    const newUrl = window.location.pathname + (params.toString() ? '?' + params.toString() : '');
    // Debounced reload to avoid too many requests
    clearTimeout(window.searchTimeout);
    window.searchTimeout = setTimeout(() => {
        window.location.href = newUrl;
    }, 500);
}

// Success toast notification
function showSuccessToast(message) {
    const toast = document.getElementById('successToast');
    toast.textContent = message;
    toast.classList.add('show');

    setTimeout(() => {
        toast.classList.remove('show');
    }, 3000);
}

// Error toast notification
function showErrorToast(message) {
    // Create error toast if it doesn't exist
    let errorToast = document.getElementById('errorToast');
    if (!errorToast) {
        errorToast = document.createElement('div');
        errorToast.id = 'errorToast';
        errorToast.className = 'toast';
        errorToast.style.backgroundColor = 'var(--critical-red)';
        document.body.appendChild(errorToast);
    }

    errorToast.textContent = message;
    errorToast.classList.add('show');

    setTimeout(() => {
        errorToast.classList.remove('show');
    }, 4000);
}