jQuery(function($) {
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

        $hidden.val(selectedId);
        $summary.find('span').text(selectedText);
        $summary.show();
        toggleModal(false);
    });

    // Close modal
    $('#gls-paketomat-modal .close').on('click', function(){
        toggleModal(false);
    });

    // Also support "Izberi" on reload if user already selected before
    $(document.body).on('updated_checkout', function () {
        let $selectedMethod = $('input[name^=shipping_method]:checked').val();
        if ($selectedMethod === 'mygls_paketomat' && !$hidden.val()) {
            toggleModal(true);
        }
    });
});
