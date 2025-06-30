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
                                   data-key="${name}" />
                        </div>`;
                    wrapper.append(newRow);
                }
            } else if (func === 'del') {
                wrapper.find('input[data-key="' + name + '"]').closest('.mapping-row').remove();
            }
        });
    });

})(jQuery);