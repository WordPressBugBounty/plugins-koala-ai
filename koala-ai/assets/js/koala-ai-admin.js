jQuery(document).ready(function($) {
    // Function to confirm disconnect
    function confirm_disconnect() {
        var connection_button = document.getElementById('connection-button');
        if (connection_button.value === koalaAIData.disconnectText) {
            return confirm(koalaAIData.disconnectConfirm);
        }
        return true;
    }
    
    // Attach disconnect confirmation to the button
    $('#connection-button').on('click', confirm_disconnect);
    
    // Toggle auto import options visibility (but not featured image setting)
    var autoImportCheckbox = document.getElementById('koala_ai_auto_import');
    var optionsContainer = document.getElementById('koala_ai_auto_import_options');
    
    if (autoImportCheckbox && optionsContainer) {
        autoImportCheckbox.addEventListener('change', function() {
            optionsContainer.style.display = this.checked ? 'block' : 'none';
        });
    }
});