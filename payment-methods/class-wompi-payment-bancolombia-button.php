<?php

class WC_Wompi_Bancolombia_Button extends WC_Payment_Gateway{

    private $wompi_payments;
	
	public function __construct(){

		global $woocommerce;

		$this->id 					= 'woocommerce_wompi_bancolombia_button';
		
		$this->method_title 		= 'Boton Bancolombia (Wompi)';
		$this->has_fields = true;
		$this->method_description	= 'Recibe pagos mediante Boton Bancolombia con Wompi';
		$this->has_fields 			= false;

		$this->icon = plugins_url( '../assets/logos/bancolombia_logo.png', __FILE__ );
		
		$this->init_form_fields();
		$this->init_settings();

        $this->wompi_payments = new Woocommerce_Wompi_Payments( $this->id );

		$this->title 			= $this->wompi_payments->isTest ? $this->settings['title'] . ' (Testmode)' : $this->settings['title'];
		$this->description 		= $this->wompi_payments->isTest ? $this->settings['description'] . ' (Testmode)' : $this->settings['description'];

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

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
				'default' 		=> 'Boton Bancolombia (Wompi)',
			),
			'description' => array(
				'title' 		=> 'Descripcion',
				'type' 			=> 'textarea',
				'default' 		=> 'Realiza tu pago mediante Boton Bancolombia con Wompi',
			),
		);
			
	} 

    public function payment_fields() {

		if ( $description = $this->get_description())
            echo wp_kses_post( wpautop( wptexturize( $description ) ) );
	}
	
	public function admin_options(){

		echo '<table class="form-table">';
		$this->generate_settings_html();
		echo '</table>';
	}
    
	/**Genera un intento de pago despues de darle click en Finalizar Compra y redirige al cliente a PSE*/
	public function process_payment( $order_id ) {

        if ( $this->wompi_payments->debug_mode() ){
			$this->wompi_payments->log->add( $this->id, "============= ".__FUNCTION__." ===========" );
			$this->wompi_payments->log->add( $this->id, 'order_id => ' . print_r( $order_id, true ) );
		}

        $args = $this->wompi_payments->get_wompi_args( $order_id );

        $args['payment_method']['type'] = 'BANCOLOMBIA_TRANSFER';
        $args['payment_method']['user_type'] = 'PERSON';

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

		for($intent = 1; $intent < 5; $intent ++){
	
			$payment_url = $this->wompi_payments->get_payment_url_for_transferId( $wompi_transferId );

			if( $payment_url !== false ){

				$payment_url = add_query_arg('intent', $intent, $payment_url);

				return array(
					'result' 	=> 'success',
					'redirect'	=> $payment_url
				);
			}
		}

		wc_add_notice( 'No se logro obtener la URL de pago, intentalo nuevamente', 'error' );

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