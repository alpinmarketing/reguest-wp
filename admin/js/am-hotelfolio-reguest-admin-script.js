(function ($) {
    'use strict';

    $(document).ready(function () {
        $('.prototype-button').on('click', function (e) {
            e.preventDefault();

            var wrapper = $('#am_hotelfolio_reguest_form_mapping');
            var name    = $('#am_hotelfolio_reguest_prototypes').val();
            if (!name) {
                return;
            }

            if ($(this).data('func') === 'add') {
                if (wrapper.find('input[data-key="' + name + '"]').length === 0) {
                    var $row    = $('<div class="mapping-row"></div>');
                    var $label  = $('<label></label>')
                                    .attr('for', 'am_hotelfolio_reguest_options_form_mapping_' + name)
                                    .text(name);
                    var $input  = $('<input />', {
                                    type:        'text',
                                    name:        'am_hotelfolio_reguest_options[form_mapping][' + name + ']',
                                    value:       '',
                                    placeholder: 'Contact Form 7 field name',
                                    'data-key':  name,
                                    'class':     'regular-text'
                                });
                    var $button = $('<button />', {
                                    type:    'button',
                                    'class': 'button button-secondary remove-mapping-row',
                                    text:    'Entfernen'
                                });
                    wrapper.append($row.append($label, $input, $button));
                }
            }
        });

        $('#am_hotelfolio_reguest_form_mapping').on('click', '.remove-mapping-row', function (e) {
            e.preventDefault();
            $(this).closest('.mapping-row').remove();
        });
    });

})(jQuery);
