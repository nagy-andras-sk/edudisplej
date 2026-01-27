/**
 * Admin Panel Scripts
 * EduDisplej Control Panel
 */

// Auto-refresh page data periodically (optional)
// Uncomment if you want auto-refresh functionality
/*
document.addEventListener('DOMContentLoaded', function() {
    // Refresh every 30 seconds if on dashboard
    if (window.location.pathname.includes('index.php') || window.location.pathname.endsWith('/admin') || window.location.pathname.endsWith('/admin/')) {
        setInterval(function() {
            // Only refresh if not in a form or modal
            if (!document.querySelector('input:focus') && !document.querySelector('textarea:focus')) {
                location.reload();
            }
        }, 30000);
    }
});
*/

// Utility functions
function confirmAction(message) {
    return confirm(message || 'Are you sure?');
}

// Format timestamps
function formatTimestamp(timestamp) {
    const date = new Date(timestamp);
    return date.toLocaleString();
}
