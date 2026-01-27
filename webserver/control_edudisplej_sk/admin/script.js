// Utility functions
function confirmAction(message) {
    return confirm(message || 'Are you sure?');
}

// Format timestamps
function formatTimestamp(timestamp) {
    const date = new Date(timestamp);
    return date.toLocaleString();
}
