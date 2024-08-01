;(function( $ ) {
    'use strict';

    const checkout_form = $( 'form.checkout' );

    $(document.body).on('checkout_error', function () {
        swal.close();
    });

    checkout_form.on( 'checkout_place_order', function() {

        const form_checkout_payment = $('form[name="checkout"] input[name="payment_method"]:checked');

        if(form_checkout_payment.val() === 'woocommerce_wompi_nequi'){

            Swal.fire({
                title: 'Verifica tu celular',
                html: wompi_payments_plugin_nequi.check_your_phone_msg,
                imageUrl: wompi_payments_plugin_nequi.logo_url,
                imageWidth: "200px",
                timer: wompi_payments_plugin_nequi.seconds_to_wait * 1000,
                timerProgressBar: true,
                allowOutsideClick: false,
                allowEscapeKey: false,
                allowEnterKey: false,
                showCancelButton: false,
                showConfirmButton: false,
                showCloseButton: false,
                didOpen: () => {
                    Swal.showLoading();
                    const timer = Swal.getPopup().querySelector("b");
                    timerInterval = setInterval(() => {
                        const timerLeft = Swal.getTimerLeft();
                        let minutes = Math.floor(timerLeft / 60000);
                        let seconds = Math.floor((timerLeft % 60000) / 1000);

                        if(minutes < 10){ minutes = `0${minutes}`; }
                        if(seconds < 10){ seconds = `0${seconds}`; }
                        timer.textContent = `${minutes}:${seconds}`;

                    }, 500);
                },
                willClose: () => {
                    if(typeof timerInterval !== 'undefined'){
                        clearInterval(timerInterval);
                    }
                }
            })
        }
    });



})( jQuery );