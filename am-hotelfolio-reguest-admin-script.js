(function ($) {
    'use strict';

    $(document).ready(function () {
        $('.prototype-button').on('click', function (e) {
            e.preventDefault();

            var wrapper = $('#am_hotelfolio_reguest_form_mapping');
            var name = $('#am_hotelfolio_reguest_prototypes').val();
            if (!name) {
                return;
            }

            var func = $(this).data('func');

            if (func === 'add') {
                // Check if the field already exists
                if (wrapper.find('input[data-key="' + name + '"]').length === 0) {
                    // Using a template literal for cleaner HTML string
                    var newRow = `
                        <div class="mapping-row">
                            <label for="am_hotelfolio_reguest_options_form_mapping_${name}">${name}</label>
                            <input type="text"
                                   name="am_hotelfolio_reguest_options[form_mapping][${name}]"
                                   value=""
                                   placeholder="Contact Form 7 field name"                                   
                                   data-key="${name}"
                                   class="regular-text" />
                            <button type="button" class="button button-secondary remove-mapping-row">Entfernen</button>
                        </div>`;
                    wrapper.append(newRow);
                }
            }
        });

        // Event delegation for dynamically added remove buttons
        $('#am_hotelfolio_reguest_form_mapping').on('click', '.remove-mapping-row', function (e) {
            e.preventDefault();
            $(this).closest('.mapping-row').remove();
        });
    });

})(jQuery);