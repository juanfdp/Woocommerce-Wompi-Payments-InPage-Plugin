<?php

class WC_Wompi_PSE extends WC_Payment_Gateway{

	public static $transferStates = [
		'DECLINED' => 'Transacción rechazada',
		'APPROVED' => 'Transacción aprobada',
		'PENDING' => 'Transacción pendiente',
	];

	public $isTest = false;

	private $public_key;
	private $debug;
	
	public function __construct(){

		global $woocommerce;

		$this->id 					= 'woocommerce_wompi_pse';
		
		$this->method_title 		= 'PSE (Wompi)';
		$this->has_fields = true;
		$this->method_description	= 'Recibe desde cualquier banco colombiano mediante PSE';
		$this->has_fields 			= true;

		$this->icon = plugins_url( '../assets/logos/pse_logo_2.png', __FILE__ );
		
		$this->init_form_fields();
		$this->init_settings();

		$this->testmode 		= $this->settings['testmode'];
		
		$this->liveurl 					= 'https://production.wompi.co/v1/';
		$this->testurl 					= 'https://sandbox.wompi.co/v1/';
		$this->view_transaction_url 	= 'https://comercios.wompi.co/transactions/%s';

		if( 'yes' === $this->testmode ){

			$this->isTest = true;

			$this->public_key 		= $this->settings['public_key_test'];
			$this->private_key 		= $this->settings['private_key_test'];
			$this->events_key 		= $this->settings['events_key_test'];
			$this->integrity_key 	= $this->settings['integrity_key_test'];

		} else {

			$this->isTest = false;

			$this->public_key 		= $this->settings['public_key'];
			$this->private_key 		= $this->settings['private_key'];
			$this->events_key 		= $this->settings['events_key'];
			$this->integrity_key 	= $this->settings['integrity_key'];
		}

		
		$this->title 			= $this->isTest ? $this->settings['title'] . ' (Testmode)' : $this->settings['title'];
		$this->description 		= $this->isTest ? $this->settings['description'] . ' (Testmode)' : $this->settings['description'];

		$this->debug 			= $this->settings['debug'];
		
		if(version_compare( WOOCOMMERCE_VERSION, '2.1', '>=')){
			$this->log = new WC_Logger();
		} else {
			$this->log = $woocommerce->logger();
		}
		
		
		/**Recibe la respuesta del pedido desde BC */
		add_action( 'woocommerce_api_' . strtolower( get_class( $this ) ), array( $this, 'check_payment_response' ) );
		
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

		// add_action( 'template_redirect', array( $this, 'fix_redirect_thank_you_page' ) );

		/**Muestra el texto del resultado de la trx en el thankyou page */
		if ( 'yes' === $this->enabled ) {
			add_filter( 'woocommerce_thankyou_order_received_text', array( $this, 'order_received_text' ), 10, 2 );
		}
	}

	public function payment_fields() {

		if ( $description = $this->get_description())
            echo wp_kses_post( wpautop( wptexturize( $description ) ) );

		$wompi_payments = new Woocommerce_Wompi_Payments();
		
		$banks = $wompi_payments->get_banks( $this->isTest );
		
		if ($banks){

			echo('<p>Tipo de persona:</p>');
			echo "<select name='wompi_pse_person_type' class='wc-enhanced-select' style='display:block' required>
					<option value='' selected>Seleccione el tipo de persona</option>
					<option selected value='0'>Natural</option>
					<option value='1'>Jurídica</option>
				</select>";

		
			echo('<p>Banco:</p>');
			echo "<select name='wompi_pse_bank' class='wc-enhanced-select' style='display:block'>";
				foreach ($banks->data as $bank):
					echo "<option value='$bank->financial_institution_code'>$bank->financial_institution_name</option>";
				endforeach;
			echo "</select>";
		}
	}

	function init_form_fields(){
		$this->form_fields = array(
			'enabled' => array(
				'title' 		=> 'Habilitar',
				'type' 			=> 'checkbox',
				'label' 		=> 'Habilitar PSE',
				'default' 		=> 'no',
				'description' 	=> 'Mostrar este metodo de pago como una opcion en el checkout.'
			),
			'title' => array(
				'title' 		=> 'Titulo',
				'type'			=> 'text',
				'default' 		=> 'PSE',
			),
			'description' => array(
				'title' 		=> 'Descripcion',
				'type' 			=> 'textarea',
				'default' 		=> 'Paga desde tu cuenta bancaria',
			),
			'events_url' => array(
				'title' 		=> 'URL de eventos',
				'type' 			=> 'text',
				'disabled' 			=> true,
				'default' 		=> add_query_arg('wc-api', get_class( $this ), get_site_url()),
				'description' 	=> 'Copia la URL y pégala en el campo \'URL de Eventos\' en la seccion \'Desarrolladores\' de Wompi',
			),
			'public_key' => array(
				'title' 		=> 'Llave pública',
				'type' 			=> 'text',
				'description' 	=> 'Llave pública',
				'desc_tip' 		=> true
			),
			'private_key' => array(
				'title' 		=> 'Llave privada',
				'type' 			=> 'text',
				'description' 	=> 'Llave privada',
				'desc_tip' 		=> true
			),
			'events_key' => array(
				'title' 		=> 'Eventos',
				'type' 			=> 'text',
				'description' 	=> 'Eventos',
				'desc_tip' 		=> true
			),
			'integrity_key' => array(
				'title' 		=> 'Integridad',
				'type' 			=> 'text',
				'description' 	=> 'Integridad',
				'desc_tip' 		=> true
			),
			'public_key_test' => array(
				'title' 		=> 'Llave pública (Pruebas)',
				'type' 			=> 'text',
				'description' 	=> 'Llave pública',
				'desc_tip' 		=> true
			),
			'private_key_test' => array(
				'title' 		=> 'Llave privada (Pruebas)',
				'type' 			=> 'text',
				'description' 	=> 'Llave privada',
				'desc_tip' 		=> true
			),
			'events_key_test' => array(
				'title' 		=> 'Eventos (Pruebas)',
				'type' 			=> 'text',
				'description' 	=> 'Eventos',
				'desc_tip' 		=> true
			),
			'integrity_key_test' => array(
				'title' 		=> 'Integridad (Pruebas)',
				'type' 			=> 'text',
				'description' 	=> 'Integridad',
				'desc_tip' 		=> true
			),
			'testmode' => array(
				'title' 		=> 'Modo de pruebas',
				'description' 	=> 'Habilitar el modo Sandbox',
				'type' 			=> 'checkbox',
				'default' 		=> 'no',
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

	public function fix_redirect_thank_you_page(){

		global $wp;

		if ( is_wc_endpoint_url( 'thank-you' ) && isset( $_GET['key'] ) && !isset( $_GET['redirect'] ) ) {

			$order_id =  intval( str_replace( 'finalizar-compra/thank-you/', '', $wp->request ) );

			$order = new WC_Order( $order_id );	

			if ( $order ) {

				$wompi_transferId = get_post_meta($order->get_id(), 'wompi_transferId', true);
	
				if( $wompi_transferId ){

					$url_checkout = $this->get_return_url( $order );

					$url_checkout = add_query_arg( 'redirect', 1, $url_checkout );
	
					wp_redirect($url_checkout);
	
					exit;
				}
			}
		}
	}

	public function order_received_text( $text, $order ) {

		if ( $order ) {

			$wompi_transferId = get_post_meta($order->get_id(), 'wompi_transferId', true);

			if( $wompi_transferId ){

				$wompi_payments = new Woocommerce_Wompi_Payments();
				$payment_info = $wompi_payments->get_payment_info($wompi_transferId);
	
				if( $payment_info && isset( $payment_info->status ) ){
	
					$payment_info = $payment_info->status;
	
					return esc_html__( self::$transferStates[$payment_info] );
				}
				
				return esc_html__('Tu pedido ha sido recibido, en un momento recibirás información sobre el estado de la transacción.');
			}
		}

		return $text;
	}

	/**Recibe el post desde wompi */
	public function check_payment_response(){
		@ob_clean();

		global $woocommerce;

		$body = file_get_contents('php://input');

		if ( $this->debug_mode() ){
			$this->log->add( $this->id, "============= ".__FUNCTION__." ===========" );
			$this->log->add( $this->id, '_GET => ' . print_r( $_GET, true ) );
			$this->log->add( $this->id, 'body => ' . print_r( $body, true ) );
		}

		$wompi_payments = new Woocommerce_Wompi_Payments();
		$wompi_payments->proccess_wc_api_enpoint();

		exit;

	}

	
	//function to validate the fields before processing the payment
	public function validate_fields() {
		
		if(  empty( $_POST['wompi_pse_bank'] ) || intval($_POST['wompi_pse_bank']  == 0 ) ){
			wc_add_notice( 'Por favor selecciona el banco por el cual realizar el pago', 'error' );
			return false;
		}
		return true;
	}
	
	/**Genera un intento de pago despues de darle click en Finalizar Compra y redirige al cliente a PSE*/
	public function process_payment( $order_id ) {

		if ( $this->debug_mode() ){
			$this->log->add( $this->id, "============= ".__FUNCTION__." ===========" );
			$this->log->add( $this->id, 'order_id => ' . print_r( $order_id, true ) );
			$this->log->add( $this->id, '_POST => ' . print_r( $_POST, true ) );
		}

		$wompi_payments = new Woocommerce_Wompi_Payments();

		// $wompi_transferId = get_post_meta( $order_id, 'wompi_transferId', true );

		// if( ! $wompi_transferId ){

		$args = $wompi_payments->get_wompi_args( $order_id );

		$args['payment_method']['type'] = 'PSE';
		$args['payment_method']['user_type'] = sanitize_text_field( $_POST['wompi_pse_person_type'] );
		$args['payment_method']['user_legal_id_type'] = sanitize_text_field( get_post_meta($order_id, 'billing_tipo_documento', true) );
		$args['payment_method']['user_legal_id'] = sanitize_text_field( get_post_meta($order_id, 'billing_documento_ident', true) );
		$args['payment_method']['financial_institution_code'] = sanitize_text_field( $_POST['wompi_pse_bank'] );
		$args['payment_method']['payment_description'] = get_bloginfo( 'name' )." Pedido No. {$order_id}";

		$payment_intent = $wompi_payments->registry_payment_intent( $order_id, $args );

		if ( $this->debug_mode() ){
			$this->log->add( $this->id, "============= ".__FUNCTION__." ===========" );
			$this->log->add( $this->id, 'payment_intent => ' . print_r( $payment_intent, true ) );
		}

		if( $payment_intent['error'] ){

			WC()->cart->empty_cart();
			wc_add_notice( $payment_intent['response'], 'error' );
			return parent::process_payment( $order_id );
		}

		$wompi_transferId = $payment_intent['response']['transferId'];

		// } 

		for($intent = 1; $intent < 5; $intent ++){
	
			$payment_url = $wompi_payments->get_payment_url_for_transferId( $wompi_transferId );

			if( $payment_url !== false ){

				$payment_url = add_query_arg('intent', $intent, $payment_url);

				return array(
					'result' 	=> 'success',
					'redirect'	=> $payment_url
				);
			}

			sleep(1);
		}

		wc_add_notice( 'Se presentó un error, por favor verifica la información ingresada e inténtalo nuevamente', 'error' );
		WC()->cart->empty_cart();

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
			if ( $this->testmode != 'yes' && ( ! $this->public_key || ! $this->private_key || ! $this->events_key || ! $this->integrity_key  ) ) return false;
			return true;

		endif;

		return false;
	}

	public function debug_mode(){
		if ( 'yes' == $this->debug ){
			return true;
		}

		return false;
	}

	public function createUrl() {
		
        if ( $this->isTest ){

            $url = "https://sandbox.wompi.co/v1/";

        }else{

            $url = "https://production.wompi.co/v1/";
        }
        
        return $url;
    }

	public function get_public_key(){
		return trim($this->public_key);
	}

	public function get_private_key(){
		return trim($this->private_key);
	}

	public function get_events_key(){
		return trim($this->events_key);
	}

	public function get_integrity_key(){
		return trim($this->integrity_key);
	}
	
}
