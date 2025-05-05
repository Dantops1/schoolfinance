// Add any custom JavaScript here
// Example: basic form validation or UI enhancements
console.log("School Finance App Loaded!");

// Example: Confirm before deleting something (you'd add delete features later)
// document.querySelectorAll('.confirm-delete').forEach(button => {
//     button.addEventListener('click', function(event) {
//         if (!confirm('Are you sure you want to delete this item?')) {
//             event.preventDefault();
//         }
//     });
// });

// js/script.js

// Add any custom JavaScript here
// Example: basic form validation or UI enhancements
console.log("School Finance App Loaded!");

// --- PWA: Register Service Worker ---
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        // The path to the Service Worker file is relative to the domain root,
        // but needs to be correct based on where sw.js is placed.
        // If sw.js is in /public_html/school_finance/, the path is /school_finance/sw.js
        navigator.serviceWorker.register('/school_finance/sw.js')
            .then(registration => {
                console.log('Service Worker registered with scope:', registration.scope);
            })
            .catch(error => {
                console.error('Service Worker registration failed:', error);
            });
    });
}