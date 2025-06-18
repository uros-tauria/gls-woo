jQuery(function($) {
    console.log("im in");
    let $modal = $('#gls-paketomat-modal');
    let $select = $('#gls-paketomat-select');
    let $hidden = $('#gls-paketomat-hidden');
    let $summary = $('#gls-paketomat-summary');

    function toggleModal(show) {
        if (show) $modal.fadeIn();
        else $modal.fadeOut();
    }

    // Open on "Uredi"
    $(document).on('click', '#edit-paketomat', function() {
        toggleModal(true);
    });

    // Show modal on shipping method select
    $(document).on('change', 'input[name^=shipping_method]', function () {
        if ($(this).val() === 'mygls_paketomat') {
            toggleModal(true);
        }
    });

    // Confirm selection
    $('#gls-paketomat-confirm').on('click', function() {
        let selectedId = $select.val();
        let selectedText = $select.find('option:selected').text();

        if (!selectedId) {
            alert('Prosimo, izberi paketomat.');
            return;
        }

        $hidden.val(selectedId).trigger('change'); // trigger change to ensure form picks it up
        $summary.find('span').text(selectedText);
        $summary.show();
        toggleModal(false);
    });

    // Close modal
    $('#gls-paketomat-modal .close').on('click', function(){
        toggleModal(false);
    });

    // Auto-open modal on reload if GLS is selected and locker not set
    $(document.body).on('updated_checkout', function () {
        let $selectedMethod = $('input[name^=shipping_method]:checked').val();
        if ($selectedMethod === 'mygls_paketomat' && !$hidden.val()) {
            toggleModal(true);
        }

        // If locker is already selected, show it
        if ($hidden.val()) {
            $summary.find('span').text($select.find('option[value="' + $hidden.val() + '"]').text());
            $summary.show();
        }
    });

    $('form.checkout').on('checkout_place_order', function() {
    const selectedId = $select.val();
    if ($('input[name^=shipping_method]:checked').val() === 'mygls_paketomat' && !selectedId) {
        alert('Prosimo, izberi paketomat.');
        return false; // stop form submission
    }
    // Make sure hidden input is updated even if they didn't click "Confirm"
    $hidden.val(selectedId);
});
$(document).on('change', 'input[name^=shipping_method]', function () {
    if ($(this).val() === 'mygls_paketomat') {
        toggleModal(true);
        $('#gls-paketomat-trigger-container').show();
    } else {
        $('#gls-paketomat-trigger-container').hide();
        $hidden.val('');
        $summary.hide();
    }
});

});
