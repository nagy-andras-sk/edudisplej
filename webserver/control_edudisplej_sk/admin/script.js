// Utility functions
function confirmAction(message) {
    return confirm(message || 'Are you sure?');
}

// Format timestamps
function formatTimestamp(timestamp) {
    const date = new Date(timestamp);
    return date.toLocaleString();
}

// Fetch and update location for a kiosk
async function fetchLocation(kioskId, ipAddress) {
    if (!ipAddress) return;
    
    try {
        const response = await fetch(`../api/geolocation.php?ip=${encodeURIComponent(ipAddress)}`);
        const data = await response.json();
        
        if (data.success && data.location) {
            // Update the location on the server
            const updateResponse = await fetch('../api/update_location.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    kiosk_id: kioskId,
                    location: data.location
                })
            });
            
            const updateData = await updateResponse.json();
            
            if (updateData.success) {
                // Update the UI
                const locationCell = document.querySelector(`tr[data-kiosk-id="${kioskId}"] .location-cell`);
                if (locationCell) {
                    locationCell.textContent = data.location;
                }
                return data.location;
            }
        }
    } catch (error) {
        console.error('Error fetching location:', error);
    }
}

// Auto-fetch locations for kiosks with IP but no location
function autoFetchLocations() {
    const rows = document.querySelectorAll('tr[data-kiosk-id]');
    rows.forEach(row => {
        const locationCell = row.querySelector('.location-cell');
        const ipAddress = row.getAttribute('data-ip');
        const kioskId = row.getAttribute('data-kiosk-id');
        
        if (locationCell && (!locationCell.textContent || locationCell.textContent === '-') && ipAddress) {
            // Delay to avoid API rate limiting
            setTimeout(() => {
                fetchLocation(kioskId, ipAddress);
            }, Math.random() * 2000);
        }
    });
}

// Assign company to kiosk
async function assignCompany(kioskId, companyId) {
    try {
        const response = await fetch('../api/assign_company.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                kiosk_id: kioskId,
                company_id: companyId === '' ? null : parseInt(companyId)
            })
        });
        
        const data = await response.json();
        
        if (data.success) {
            // Reload the page to show updated assignments
            window.location.reload();
        } else {
            alert('Failed to assign company: ' + data.message);
        }
    } catch (error) {
        console.error('Error assigning company:', error);
        alert('Error assigning company');
    }
}

// Search and filter table
function initializeSearch() {
    const searchBox = document.getElementById('kioskSearch');
    if (!searchBox) return;
    
    searchBox.addEventListener('input', function() {
        const searchTerm = this.value.toLowerCase();
        const rows = document.querySelectorAll('#kioskTable tbody tr');
        
        rows.forEach(row => {
            const text = row.textContent.toLowerCase();
            if (text.includes(searchTerm)) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
    });
}

// Make table sortable
function initializeSorting() {
    const table = document.getElementById('kioskTable');
    if (!table) return;
    
    const headers = table.querySelectorAll('th[data-sort]');
    
    headers.forEach(header => {
        header.style.cursor = 'pointer';
        header.addEventListener('click', function() {
            const sortKey = this.getAttribute('data-sort');
            sortTable(sortKey);
        });
    });
}

function sortTable(column) {
    const table = document.getElementById('kioskTable');
    const tbody = table.querySelector('tbody');
    const rows = Array.from(tbody.querySelectorAll('tr'));
    
    // Determine sort direction
    const currentDirection = table.getAttribute('data-sort-direction') || 'asc';
    const newDirection = currentDirection === 'asc' ? 'desc' : 'asc';
    table.setAttribute('data-sort-direction', newDirection);
    
    // Sort rows
    rows.sort((a, b) => {
        let aValue = a.getAttribute(`data-${column}`) || '';
        let bValue = b.getAttribute(`data-${column}`) || '';
        
        // Handle numeric values
        if (!isNaN(aValue) && !isNaN(bValue)) {
            aValue = parseFloat(aValue);
            bValue = parseFloat(bValue);
        }
        
        if (aValue < bValue) return newDirection === 'asc' ? -1 : 1;
        if (aValue > bValue) return newDirection === 'asc' ? 1 : -1;
        return 0;
    });
    
    // Re-append rows in sorted order
    rows.forEach(row => tbody.appendChild(row));
}

// Highlight offline kiosks
function highlightOfflineKiosks() {
    const now = Date.now();
    const tenMinutes = 10 * 60 * 1000; // 10 minutes in milliseconds
    
    const rows = document.querySelectorAll('tr[data-kiosk-id]');
    rows.forEach(row => {
        const lastSeen = row.getAttribute('data-last-seen');
        if (lastSeen) {
            const lastSeenTime = new Date(lastSeen).getTime();
            if (now - lastSeenTime > tenMinutes) {
                row.classList.add('offline-alert');
            }
        }
    });
}

// Initialize all features on page load
document.addEventListener('DOMContentLoaded', function() {
    initializeSearch();
    initializeSorting();
    highlightOfflineKiosks();
    
    // Auto-fetch locations after a short delay
    setTimeout(autoFetchLocations, 1000);
});
