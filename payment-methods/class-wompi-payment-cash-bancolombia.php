<?php

class WC_Wompi_Cash_Bancolombia extends WC_Payment_Gateway{

    private $wompi_payments;
	
	public function __construct(){

		global $woocommerce;

		$this->id 					= 'woocommerce_wompi_cash_bancolombia';
		
		$this->method_title 		= 'Corresponsal Bancolombia (Wompi)';
		$this->has_fields = true;
		$this->method_description	= 'Recibe pagos en cualquier corresponsal Bancolombia';
		$this->has_fields 			= false;

		$this->icon = plugins_url( '../assets/logos/cash_bancolombia_logo.png', __FILE__ );
		
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
				'default' 		=> 'Corresponsal Bancolombia',
			),
			'description' => array(
				'title' 		=> 'Descripcion',
				'type' 			=> 'textarea',
				'default' 		=> 'Realiza el pago de tu pedido en cualquier corresponsal Bancolombia',
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

        $args['payment_method']['type'] = 'BANCOLOMBIA_COLLECT';

        $payment_intent = $this->wompi_payments->registry_payment_intent( $order_id, $args );

        if ( $this->wompi_payments->debug_mode() ){
            $this->wompi_payments->log->add( $this->id, "============= ".__FUNCTION__." ===========" );
            $this->wompi_payments->log->add( $this->id, 'payment_intent => ' . print_r( $payment_intent, true ) );
        }

		$order = wc_get_order( $order_id );

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


		// wc_add_notice( 'No se logro procesar el pago. Intentalo nuevamente.', 'error' );

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
