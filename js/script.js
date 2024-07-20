document.addEventListener('DOMContentLoaded', function() {
    var syncForm = document.getElementById('syncForm');
    var syncButton = document.getElementById('syncButton');

    if (localStorage.getItem('syncing') == 'true'){
        syncButton.disabled = true;
        syncButton.innerHTML = 'Syncing...';
    }
    syncForm.addEventListener('submit', function(event) {
        event.preventDefault(); // Prevent the form from submitting via the browser

        // Disable the button and show loading text
        syncButton.disabled = true;
        syncButton.innerHTML = 'Syncing...';
        localStorage.setItem('syncing', 'true');

        // Send the form data via AJAX
        var xhr = new XMLHttpRequest();
        xhr.open('POST', syncForm.action, true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onreadystatechange = function() {
            if (xhr.readyState === XMLHttpRequest.DONE) {
                if (xhr.status === 200) {
                    // Re-enable the button and restore text
                    syncButton.disabled = false;
                    syncButton.innerHTML = 'Sync All Orders';
                    localStorage.setItem('syncing', 'false');
                    // Optionally handle the response
                    alert('Orders synced successfully!');
                } else {
                    // Handle any errors
                    syncButton.disabled = false;
                    syncButton.innerHTML = 'Sync All Orders';
                    localStorage.setItem('syncing', 'false');
                    alert('An error occurred: ' + xhr.statusText);
                }
            }
        };
        xhr.send(new URLSearchParams(new FormData(syncForm)).toString());
    });
});