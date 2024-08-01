(function($){
    'use strict';
    $( 'body' ).on( 'updated_checkout', function() {
        $(".card-js").CardJs();
    } );


    var checkout_form = $( 'form.checkout' );

    $(document.body).on('checkout_error', function () {
        swal.close();
        Cookies.remove('woocommerce_wompi_credit_cards_intent');
    });

    checkout_form.on( 'checkout_place_order', function() {

        var form_checkout_payment = $('form[name="checkout"] input[name="payment_method"]:checked');

        if(form_checkout_payment.val() === 'woocommerce_wompi_credit_cards'){

            if( typeof Cookies.get('woocommerce_wompi_credit_cards_intent') === 'undefined' ){
                var inFiveMinutes = new Date(new Date().getTime() + 5 * 60 * 1000);
                Cookies.set('woocommerce_wompi_credit_cards_intent', 0, { expires: inFiveMinutes, path: '/' })
            }

            //Sweetalert to show the loading spinner
            Swal.fire({
                title: 'Procesando tu pago',
                html: 'Por favor espera un momento...',
                imageWidth: "200px",
                timerProgressBar: true,
                allowOutsideClick: false,
                allowEscapeKey: false,
                allowEnterKey: false,
                showCancelButton: false,
                showConfirmButton: false,
                showCloseButton: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            })
        }
    });



    
})(jQuery); 


