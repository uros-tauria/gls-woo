jQuery(function($) {

    let $modal, $select, $hidden, $summary;

    function cacheElements() {
        $modal = $('#gls-paketomat-modal');
        $select = $('#gls-paketomat-select');
        $hidden = $('#gls-paketomat-hidden');
        $summary = $('#gls-paketomat-summary');
    }

    function toggleModal(show) {
        if (show) $modal.fadeIn();
        else $modal.fadeOut();
    }

    $(document).on('click', '#edit-paketomat', function() {
        toggleModal(true);
    });

    $('#gls-paketomat-confirm').on('click', function() {
        let selectedId = $select.val();
        let selectedText = $select.find('option:selected').text();

        if (!selectedId) {
            alert('Prosimo, izberi paketomat.');
            return;
        }

        $hidden.val(selectedId).trigger('change');
        $summary.find('span').text(selectedText);
        $summary.show();
        toggleModal(false);
    });

    $('#gls-paketomat-modal .close').on('click', function(){
        toggleModal(false);
    });


    $('form.checkout').on('checkout_place_order', function() {
        const selectedShipping = $('input[name^=shipping_method]:checked').val();
        const lockerId = $('#gls-paketomat-hidden').val();

        if (selectedShipping === 'mygls_paketomat' && !lockerId) {
            alert('Prosimo, izberi paketomat.');
            return false; // Prevent order
        }

            $('#gls-paketomat-hidden').val($('#gls-paketomat-select').val());

        return true;
    });


    // Handle WooCommerce updated checkout
    $(document.body).on('updated_checkout', function () {
        cacheElements();
        let selectedMethod = $('input[name^=shipping_method]:checked').val();
        if (selectedMethod === 'mygls_paketomat') {
            if (!$hidden.val()) toggleModal(true);
            if ($select.val()) {
                console.log("inside");
                $hidden.val($select.val());
                $summary.find('span').text($select.find('option:selected').text());
                $summary.show();
                console.log($summary);
                console.log($select.val());
            }else{
                console.log("fail");
            }

            console.log('Hidden value after update:', $hidden.val());



            $('#gls-paketomat-trigger-container').show();
        } else {
            $('#gls-paketomat-trigger-container').hide();
            $hidden.val('');
            $summary.hide();
        }
    });


    cacheElements();
});
