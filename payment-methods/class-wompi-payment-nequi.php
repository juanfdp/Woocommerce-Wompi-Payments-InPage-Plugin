<?php

class WC_Wompi_Nequi extends WC_Payment_Gateway{

    private $wompi_payments;
	
	public function __construct(){

		global $woocommerce;

		$this->id 					= 'woocommerce_wompi_nequi';
		
		$this->method_title 		= 'Nequi (Wompi)';
		$this->has_fields = true;
		$this->method_description	= 'Recibe pagos mediante Nequi';
		$this->has_fields 			= false;

		$this->icon = plugins_url( '../assets/logos/nequi_logo.png', __FILE__ );
		
		$this->init_form_fields();
		$this->init_settings();

        $this->wompi_payments = new Woocommerce_Wompi_Payments( $this->id );

		$this->title 			= $this->wompi_payments->isTest ? $this->settings['title'] . ' (Testmode)' : $this->settings['title'];
		$this->description 		= $this->wompi_payments->isTest ? $this->settings['description'] . ' (Testmode)' : $this->settings['description'];

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

	}

	public function payment_fields() {
		if ( $description = $this->get_description())
            echo wp_kses_post( wpautop( wptexturize( $description ) ) );
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
				'default' 		=> 'Nequi',
			),
			'description' => array(
				'title' 		=> 'Descripcion',
				'type' 			=> 'textarea',
				'default' 		=> 'Paga desde tu cuenta Nequi, recibiras una notificacion en tu celular para aprobar el pago.',
			),
			'debug' => array(
				'title' 		=> 'Registrar logs',
				'description' 	=> '',
				'type' 			=> 'checkbox',
				'default' 		=> 'no',
			),
		);
	} 
	
	public function admin_options(){

		echo '<table class="form-table">';
		$this->generate_settings_html();
		echo '</table>';
	}

	/**Genera un intento de pago despues de darle click en Finalizar Compra y redirige al cliente a PSE*/
	public function process_payment( $order_id ) {

		global $woocommerce;

		if ( $this->wompi_payments->debug_mode() ){
			$this->wompi_payments->log->add( $this->id, "============= ".__FUNCTION__." ===========" );
			$this->wompi_payments->log->add( $this->id, 'order_id => ' . print_r( $order_id, true ) );
			$this->wompi_payments->log->add( $this->id, '_POST => ' . print_r( $_POST, true ) );
		}

        $args = $this->wompi_payments->get_wompi_args( $order_id );

		$order = wc_get_order( $order_id );

		$phone_number = $order->get_billing_phone();

		//Extrae los 10 ultimos digitos del numero de telefono
		$phone_number = substr(str_replace( [' ', '-', '(', ')'], '', $phone_number ) , -10);

        $args['payment_method']['type'] = 'NEQUI';
        $args['payment_method']['phone_number'] = $phone_number;

        $payment_intent = $this->wompi_payments->registry_payment_intent( $order_id, $args );

        if ( $this->wompi_payments->debug_mode() ){
            $this->wompi_payments->log->add( $this->id, "============= ".__FUNCTION__." ===========" );
            $this->wompi_payments->log->add( $this->id, 'payment_intent => ' . print_r( $payment_intent, true ) );
        }

		

        if( $payment_intent['error'] ){

            wc_add_notice( $payment_intent['response'], 'error' );

			$redirect_url = $this->get_return_url( $order );
			WC()->cart->empty_cart();

			return array(
				'result' 	=> 'success',
				'redirect'	=> $redirect_url
			);
            // return parent::process_payment( $order_id );
        }

        $wompi_transferId = $payment_intent['response']['transferId'];

		$limit = 30;

		for($intent = 1; $intent <= $limit; $intent ++) {

			$payment_info = $this->wompi_payments->get_payment_info($wompi_transferId);

			if( $payment_info && isset( $payment_info->status ) ) {

				if( $payment_info->status == 'APPROVED' ){

					$redirect_url = $this->get_return_url( $order );
					$redirect_url = add_query_arg( 'wompi_payment', 'approved', $redirect_url );
					$redirect_url = add_query_arg( 'intent', $intent, $redirect_url );

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

					$order->add_order_note( $wompi_transferId . ' - ' . $payment_info->status_message );
					wc_add_notice( $payment_info->status_message, 'error' );

					WC()->cart->empty_cart();
					$redirect_url = WC()->cart->get_cart_url();

					return array(
						'result' 	=> 'success',
						'redirect'	=> $redirect_url
					);
				}
			}

			sleep(3);
		}

		wc_add_notice( 'No se logro procesar el pago. Intentalo nuevamente.', 'error' );

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
