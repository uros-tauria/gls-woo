jQuery(function($){
    console.log("im in");
    let $modal = $('#gls-paketomat-modal');
    let $select = $('#gls-paketomat-select');
    let $hidden = $('#gls-paketomat-hidden');

    console.log($modal);

    function toggleModal(show) {
        if (show) $modal.show();
        else $modal.hide();
    }

    $(document.body).on('updated_checkout', function () {
        let $selectedMethod = $('input[name^=shipping_method]:checked').val();
        if ($selectedMethod === 'mygls_paketomat' && !$hidden.val()) {
            toggleModal(true);
        }
    });

    $(document).on('change', 'input[name^=shipping_method]', function () {
        if ($(this).val() === 'mygls_paketomat') {
            toggleModal(true);
        }
    });

    $('#gls-paketomat-confirm').on('click', function(){
        let selectedVal = $select.val();
        let selectedText = $select.find('option:selected').text();

        if (!selectedVal) {
            alert('Prosimo, izberi paketomat.');
            return;
        }

        $hidden.val(selectedVal);
        $('#gls-paketomat-summary span').text(selectedText);
        $('#gls-paketomat-summary').show();
        toggleModal(false);
    });

    $('#gls-paketomat-modal .close').on('click', function(){
        toggleModal(false);
    });
});
