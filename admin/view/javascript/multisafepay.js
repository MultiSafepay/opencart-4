function togglePaymentOptionFieldStatus(payment_method_panel, is_active) {
    const id = payment_method_panel.find('.panel-body .group-id .input-group:first select.form-select');
    if (is_active) {
        id.val(0).change();
    } else {
        id.val(1).change();
    }
}

function togglePaymentOptionIconStatus(payment_method_panel, status) {
    const id = payment_method_panel.find('.panel-heading .panel-title .status');
    if (status === '1') {
        id.addClass('active');
    } else {
        id.removeClass('active');
    }
}

function togglePaymentOptionBackgroundColor(payment_method_panel, is_collapsed) {
    const id = payment_method_panel.find('.panel-heading .panel-title .enable-disable-payment');
    const id_margin = payment_method_panel.find('.panel-heading .panel-title a .drag-and-drop-control');
    if (is_collapsed) {
        id.removeClass('background-blue-icon').addClass('background-gray-icon');
        id_margin.css('margin-top', '5px');
    } else {
        id.removeClass('background-gray-icon').addClass('background-blue-icon');
        id_margin.css('margin-top', '6px');
    }
}

function toggleTokenizationFieldStatus(payment_component_gateway) {
    for (let i = 0; i < payment_component_gateway.length; i++) {
        if (payment_component_gateway[i]) {
            const payment_component_id = $('#payment-multisafepay-' + payment_component_gateway[i] + '-payment-component');
            const tokenization_id = $('#payment-multisafepay-' + payment_component_gateway[i] + '-tokenization');
            const tokenization_field = $('#payment-multisafepay-' + payment_component_gateway[i] + '-tokenization-field');
            const tokenization_display = $('#payment-multisafepay-' + payment_component_gateway[i] + '-display-tokenization-field');

            if (payment_component_id.find('option').filter(':selected').val() === '0') {
                tokenization_id.prop('selectedIndex', 0);
                tokenization_field.slideUp();
                tokenization_display.slideUp();
            }

            if (payment_component_id.find('option').filter(':selected').val() === '1') {
                tokenization_display.css('display', 'block');
                tokenization_field.slideDown();
            }
        }
    }
}

$(document).ready(function() {
    $('#input-filter-payment-method').on('change', function() {
        const selected = $(this).val();
        if ((selected !== 'gateway') || (selected !== 'giftcard') || (selected !== 'generic')) {
            $('.payment-type-giftcard, .payment-type-gateway, .payment-type-generic, .drag-and-drop-control').show();
        }
        if (selected === 'gateway') {
            $('.payment-type-gateway').show();
            $('.payment-type-giftcard, .payment-type-generic, .drag-and-drop-control').hide();
        }
        if (selected === 'giftcard') {
            $('.payment-type-giftcard').show();
            $('.payment-type-gateway, .payment-type-generic, .drag-and-drop-control').hide();
        }
        if (selected === 'generic') {
            $('.payment-type-generic').show();
            $('.payment-type-giftcard, .payment-type-gateway, .drag-and-drop-control').hide();
        }
    });

    let default_drake = dragula([document.querySelector('#dragula-container #accordion'), document.querySelector('#dragula-container #accordion')], {
        direction: 'vertical',
        moves: function(el, container, handle) {
            return handle.classList.contains('drag-and-drop-control');
        },
    });
    default_drake.on('drag', function(el) {
        $(el).find('.panel-heading').parent('.payment-method-panel').addClass('drag-active gu-transit');
        $(el).find('.panel-heading .panel-title .status').parent('.enable-disable-payment').removeClass('background-gray-icon').addClass('background-gray-icon-no-border');
        $(el).find('.panel-heading .panel-title .title').addClass('change-payment-title-color');
    });
    default_drake.on('drop', function(el) {
        $(el).find('.panel-heading').parent('.payment-method-panel').removeClass('drag-active gu-transit');
        $(el).find('.panel-heading .panel-title .status').parent('.enable-disable-payment').removeClass('background-gray-icon-no-border').addClass('background-gray-icon');
        $(el).find('.panel-heading .panel-title .title').removeClass('change-payment-title-color');
    });
    default_drake.on('cancel', function(el) {
        $(el).find('.panel-heading').parent('.payment-method-panel').removeClass('drag-active gu-transit');
        $(el).find('.panel-heading .panel-title .status').parent('.enable-disable-payment').removeClass('background-gray-icon-no-border').addClass('background-gray-icon');
        $(el).find('.panel-heading .panel-title .title').removeClass('change-payment-title-color');
    });
    default_drake.on('dragend', function() {
        $('#dragula-container #accordion .payment-method-panel').each(function(i, obj) {
            $(obj).find('.sort-order').attr('value', i + 1);
        });
    });

    $('.multisafepay-admin-page #tab-payment-methods .panel-group .panel').each(function() {
        const payment_method_panel = $(this);
        payment_method_panel.find('.panel-heading .panel-title .status').click(function(event) {
            event.preventDefault();
            event.stopPropagation();
            togglePaymentOptionFieldStatus(payment_method_panel, $(this).hasClass('active'));
        });
        payment_method_panel.find('.panel-body .group-id:first select.form-select').change(function() {
            togglePaymentOptionIconStatus(payment_method_panel, $(this).val());
        });

        payment_method_panel.find('.panel-heading .panel-title .collapsed').click(function(event) {
            event.preventDefault();
            event.stopPropagation();
            togglePaymentOptionBackgroundColor(payment_method_panel, $(this).hasClass('collapsed'));
        });
    });

    let tokenizable_gateways = [];
    $('div[id*=-tokenization-field]').each(function() {
        let gateway = $(this).attr('data-gateway');
        if (gateway) {
            tokenizable_gateways.push(gateway);
        }
    });

    $('select[id*=-payment-component]').each(function() {
        $(this).on('change', function() {
            toggleTokenizationFieldStatus(tokenizable_gateways);
        });
    });
    toggleTokenizationFieldStatus(tokenizable_gateways);
});
