<?php

class WC_Wompi_Credit_Cards extends WC_Payment_Gateway{

    private $wompi_payments;

	private $card_token = null;
	
	public function __construct(){

		global $woocommerce;

		$this->id 					= 'woocommerce_wompi_credit_cards';
		
		$this->method_title 		= 'Tarjetas de Crédito/Débito (Wompi)';
		$this->has_fields = true;
		$this->method_description	= 'Recibe pagos mediante tarjetas de Crédito/Débito con Wompi';
		$this->has_fields 			= true;

		$this->icon = plugins_url( '../assets/logos/credit_cards.png', __FILE__ );
		
		$this->init_form_fields();
		$this->init_settings();

        $this->wompi_payments = new Woocommerce_Wompi_Payments( $this->id );

		$this->title 			= $this->wompi_payments->isTest ? $this->settings['title'] . ' (Testmode)' : $this->settings['title'];
		$this->description 		= $this->wompi_payments->isTest ? $this->settings['description'] . ' (Testmode)' : $this->settings['description'];

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

	}

	public function payment_fields() {

		$installments_enabled = $this->settings['installments_enabled'] == 'yes' ? "true" : "false";
		if ( $description = $this->get_description())
            echo wp_kses_post( wpautop( wptexturize( $description ) ) );
        ?>
        <div class="card-js" data-capture-installments="<?php echo($installments_enabled);?>" data-capture-name="true" style="background: #f4f4f4;padding: 30px;border-radius: 5px;margin-top: 15px;">
            <input class="card-number" id="card-number" name="card-number" placeholder="Número de tarjeta">
            <input class="card-number-no-spaces" id="card-number-no-spaces" name="card-number-no-spaces" placeholder="Número de tarjeta">
            <input class="name" id="card-holder-name" name="card-holder-name" placeholder="Nombre">
            <input class="expiry-month" id="card-expiry-month" name="card-expiry-month">
            <input class="expiry-year" id="card-expiry-year" name="card-expiry-year">
            <input class="cvc" id="card-cvc" name="card-cvc">
        </div>
        <?php
	}

	function init_form_fields(){
		$this->form_fields = array(
			'enabled' => array(
				'title' 		=> 'Habilitar',
				'type' 			=> 'checkbox',
				'label' 		=> '',
				'default' 		=> 'no',
				'description' 	=> 'Mostrar este metodo de pago como una opcion en el checkout.'
			),
			'title' => array(
				'title' 		=> 'Titulo',
				'type'			=> 'text',
				'default' 		=> 'Tarjeta de Crédito/Débito',
			),
			'description' => array(
				'title' 		=> 'Descripcion',
				'type' 			=> 'textarea',
				'default' 		=> 'Paga con tu tarjeta de Crédito/Débito',
			),
			'installments_enabled' => array(
				'title' 		=> 'Habilitar pago a Cuotas',
				'type' 			=> 'checkbox',
				'label' 		=> '',
				'default' 		=> 'no',
			),
		);
	} 
	
	public function admin_options(){

		echo '<table class="form-table">';
		$this->generate_settings_html();
		echo '</table>';
	}

	//function to validate the fields before processing the payment
	public function validate_fields() {
		
		/**Tokenize card */
		$tokenize_args = [];

		$tokenize_args['number'] = (string) str_replace([" ", "-"], "", sanitize_text_field( $_POST['card-number'] ));
		$tokenize_args['cvc'] = (string) sanitize_text_field( $_POST['card-cvc'] );
		$tokenize_args['exp_month'] = intval(sanitize_text_field( $_POST['card-expiry-month'] )) < 10 ? '0' . intval(sanitize_text_field( $_POST['card-expiry-month'] )) : (string) intval(sanitize_text_field( $_POST['card-expiry-month'] ));
		$tokenize_args['exp_year'] = (string) sanitize_text_field( $_POST['card-expiry-year'] );
		$tokenize_args['card_holder'] = sanitize_text_field( $_POST['card-holder-name'] );

		$tokenize = $this->wompi_payments->tokenize_card( $tokenize_args );

		if( !$tokenize ){
			return false;
		}

		$this->card_token = $tokenize;

		return true;

	}

	/**Genera un intento de pago despues de darle click en Finalizar Compra y redirige al cliente a PSE*/
	public function process_payment( $order_id ) {

		if ( $this->wompi_payments->debug_mode() ){
			$this->wompi_payments->log->add( $this->id, "============= ".__FUNCTION__." ===========" );
			$this->wompi_payments->log->add( $this->id, 'order_id => ' . print_r( $order_id, true ) );
			$this->wompi_payments->log->add( $this->id, '_POST => ' . print_r( $_POST, true ) );
		}
		
		if( $this->card_token !== null ){

			$args = $this->wompi_payments->get_wompi_args( $order_id );

			$args['payment_method']['type'] = 'CARD';
			$args['payment_method']['token'] = $this->card_token;
			$args['payment_method']['installments'] = intval( $_POST['card-installments']) === 0 ? 1 : intval( $_POST['card-installments']);

			$payment_intent = $this->wompi_payments->registry_payment_intent( $order_id, $args );
	
			if ( $this->wompi_payments->debug_mode() ){
				$this->wompi_payments->log->add( $this->id, "============= ".__FUNCTION__." ===========" );
				$this->wompi_payments->log->add( $this->id, 'payment_intent => ' . print_r( $payment_intent, true ) );
			}
	
			if( $payment_intent['error'] ){
	
				wc_add_notice( $payment_intent['response'], 'error' );
				
				return parent::process_payment( $order_id );
			}

			$wompi_transferId = $payment_intent['response']['transferId'];

			$order = wc_get_order( $order_id );

			$limit = 8;

			for($intent = 1; $intent <= $limit; $intent ++) {
	
				$payment_info = $this->wompi_payments->get_payment_info($wompi_transferId);

				if( $payment_info && isset( $payment_info->status ) ) {
					if( $payment_info->status == 'APPROVED' ){

						do_action('wc_wompi_on_payment_approved', $args );

						$redirect_url = $this->get_return_url( $order );
						$redirect_url = add_query_arg( 'wompi_payment', 'approved', $redirect_url );
						$redirect_url = add_query_arg( 'intent', $intent, $redirect_url );

						update_post_meta( $order_id, 'franchise', $payment_info->payment_method->extra->brand );
						update_post_meta( $order_id, 'cc_number', $payment_info->payment_method->extra->last_four );
						update_post_meta( $order_id, 'payment_method_name', $payment_info->payment_method->extra->brand );
						update_post_meta( $order_id, 'card_type', $payment_info->payment_method->extra->card_type );

						WC()->cart->empty_cart();

						return array(
							'result' 	=> 'success',
							'redirect'	=> $redirect_url
						);
					}

					if( $intent === $limit && $payment_info->status == 'PENDING' ){
						
						$redirect_url = $this->get_return_url( $order );
						$redirect_url = add_query_arg( 'wompi_payment', 'pending', $redirect_url );
						$redirect_url = add_query_arg( 'intent', $intent, $redirect_url );

						WC()->cart->empty_cart();

						return array(
							'result' 	=> 'success',
							'redirect'	=> $redirect_url
						);
					}

					if( $payment_info->status == 'DECLINED' ){

						// $order->add_order_note( $wompi_transferId . ' - ' . $payment_info->status_message );
						wc_add_notice( $payment_info->status_message, 'error' );
						wc_add_notice( 'Transacción rechazada, consulta los datos de la tarjeta e intenta nuevamente.', 'error' );

						$order->update_status( 'failed', $wompi_transferId . ' - ' . $payment_info->status_message );

						return;
					}
				}

				sleep(3);
			}

			return parent::process_payment( $order_id );

		} else {

			wc_add_notice( 'No se pudo procesar el pago, por favor intentalo nuevamente.', 'error' );
		}

		return parent::process_payment( $order_id );

	}

	public function is_valid_currency() {
		if ( ! in_array( get_woocommerce_currency(), array( 'COP' ) ) ) return false;

		return true;
	}

	public function is_available() {
		global $woocommerce;

		if ( $this->enabled=="yes" ) :

			if ( !$this->is_valid_currency()) return false;
			return true;

		endif;

		return false;
	}
}
