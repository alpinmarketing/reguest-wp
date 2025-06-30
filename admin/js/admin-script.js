jQuery(document).ready(function($) { // Use $ alias for jQuery
    var $wrapper = $('#webx_reguest_form_mappings');

    $('#webx_reguest_add_mapping').on('click', function() {
        var fieldName = $('#webx_reguest_prototypes').val();
        if (fieldName && $wrapper.find('#webx-reguest-row-' + fieldName).length === 0) {
            var newRow = `
                <div class="webx-reguest-mapping-row" id="webx-reguest-row-${fieldName}">
                    <label for="webx_reguest_form_${fieldName}">${fieldName}</label>
                    <input type="text" name="webx_reguest_form[${fieldName}]" value="" placeholder="${webxReguestAdmin.cf7FieldNamePlaceholder}" id="webx_reguest_form_${fieldName}" class="regular-text" />
                    <button type="button" class="button button-secondary webx-reguest-remove-mapping" data-field-name="${fieldName}">${webxReguestAdmin.removeButtonText}</button>
                </div>
            `;
            $wrapper.append(newRow);
            $('#webx_reguest_prototypes').val(''); // Reset select
        }
    });

    // Use event delegation for dynamically added remove buttons
    $wrapper.on('click', '.webx-reguest-remove-mapping', function() {
        var fieldName = $(this).data('field-name');
        $('#webx-reguest-row-' + fieldName).remove();
    });
});