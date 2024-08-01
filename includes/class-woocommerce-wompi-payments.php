<?php

class Woocommerce_Wompi_Payments extends WC_Wompi_PSE {

    private $isSandBox = false;

    public static $card_field = [
		'cvc' => 'Número de seguridad (CVC)',
        'card_holder' => 'Nombre del titular',
        'exp_month' => 'Mes de expiración',
        'exp_year' => 'Año de expiración',
        'number' => 'Número de tarjeta',
	];

    public function __construct( $payment_method_id = null ){
        parent::__construct();

        if( !is_null( $payment_method_id ) ){
            $this->id = $payment_method_id;
        }
    }

    /**Obtiene el listado de bancos disponibles */
    public function get_banks( $isTest = false ){

        $this->isSandBox = $isTest;
        
        $url = $this->get_payment_url() . 'pse/financial_institutions/';

        $args = array(
            'method'      => 'GET',
            'timeout'     => 5,
            'headers'     => array(
                'authorization' => 'Bearer ' . $this->get_public_key(),
            ),
            'sslverify'   => false
        );

        $request = wp_remote_post( $url, $args );

        if ( is_wp_error( $request ) || wp_remote_retrieve_response_code( $request ) != 200 ) {

            if ( $this->debug_mode() ){
                $this->log->add( $this->id, "============= ".__FUNCTION__." ===========" );
                $this->log->add( $this->id, print_r( $request, true ) );
            }

            return false;

        } else {

            $response = json_decode( wp_remote_retrieve_body( $request ) );

            return $response;
        }
    }

    public function proccess_wc_api_enpoint(){

        global $wp;

        $body = file_get_contents('php://input');

        if ( $this->debug_mode() ){
			$this->log->add( $this->id, "============= ".__FUNCTION__." ===========" );
			$this->log->add( $this->id, '_GET => ' . print_r( $_GET, true ) );
			$this->log->add( $this->id, 'body => ' . print_r( $body, true ) );
		}

        if ( ! empty( $body ) && !isset( $_GET['order_id'] ) ) {

            try {

                $body = json_decode ($body, null, 512 );
                
                if ( json_last_error() !== JSON_ERROR_NONE ) {
                    header( 'HTTP/1.1 400' );
                    echo(json_encode(['error' => true, 'msg' => json_last_error_msg()]));
                    exit;
                }

                if ( $this->debug_mode() ){
                    $this->log->add( $this->id, 'body => ' . print_r( $body, true ) );
                }

                header( 'HTTP/1.1 200 OK' );

                $this->proccess_payment_response( $body );

            } catch (Exception $e) {

                header( 'HTTP/1.1 400' );
                echo(json_encode(['error' => true, 'msg' => 'Error decoding json: ' . $e->GetMessage()]));
                exit;
            }

        /**Is from redirect (is client) */
        } elseif( isset( $_GET['order_id'] ) && empty( $body ) ){

            if ( $this->debug_mode() ){
                $this->log->add( $this->id, "============= IS CLIENT ===========" );
            }

            $order_id = explode( '?', $_GET['order_id'] )[0];

            $order = wc_get_order($order_id);

            if( $order ){

                $wompi_transferId = get_post_meta($order->get_id(), 'wompi_transferId', true);

                if( $wompi_transferId ){

                    $payment_info = $this->get_payment_info($wompi_transferId);
	
                    if( $payment_info && isset( $payment_info->status ) ){
        
                        $payment_info_status = $payment_info->status;
        
                        if( 'APPROVED' === $payment_info_status ){
        
                            $url_checkout = $this->get_return_url( $order );

                            if ( $this->debug_mode() ){
                                $this->log->add( $this->id, 'url_checkout => ' . print_r( $url_checkout, true ) );
                            }

                            WC()->cart->empty_cart();
                            wp_redirect($url_checkout);

                            exit;
        
                        } else {

                            if( !empty( $payment_info->status_message ) ){

                                $order->add_order_note( $wompi_transferId . ' - ' . $payment_info->status_message );
                                wc_add_notice( $payment_info->status_message, 'error' );
                            }
                        }
                    }

                    wp_redirect( wc_get_checkout_url() );
                    exit;
                }
            }

        } else {
            
            header( 'HTTP/1.1 400' );
            echo(json_encode(['error' => true, 'msg' => 'Bad request']));
            exit;
        }
    }

    private function validate_checksum( $posted ){

        $remote_checksum = $posted->signature->checksum;

        $local_checksum = hash ("sha256", trim( $posted->data->transaction->id . $posted->data->transaction->status . $posted->data->transaction->amount_in_cents . $posted->timestamp . $this->get_events_key() ) );

        return $remote_checksum === $local_checksum;

    }

    /**Procesa el pago de acuerdo a la respuesta de Wompi */
    public function proccess_payment_response( $posted ) {

        global $woocommerce, $wp_filesystem;

        if ( $this->debug_mode() ){
            $this->log->add( $this->id, "============= ".__FUNCTION__." ===========" );
            $this->log->add( $this->id, 'posted => ' . print_r( $posted, true ) );
        }

        if( 
            !$posted->data->transaction->id
            || !$posted->data->transaction->reference
            || !$posted->data->transaction->status
            || !$posted->event
        ){
            $response = 'Bad request';
            if ( $this->debug_mode() )
                $this->log->add( $this->id, 'response => ' .  $response);
            echo(json_encode(['error' => true, 'msg' => $response]));

            exit;
        }

        if( !$this->validate_checksum( $posted ) ){
            $response = 'Invalid checksum';
            if ( $this->debug_mode() )
                $this->log->add( $this->id, 'response => ' .  $response);
            echo(json_encode(['error' => true, 'msg' => $response]));
            exit;
        }
        
        $order_id = explode( '-', $posted->data->transaction->reference )[0];

        $order = wc_get_order($order_id);

        if( !$order ){

            $response = 'Invalid order';
            if ( $this->debug_mode() )
                $this->log->add( $this->id, 'response => ' .  $response);

            echo(json_encode(['error' => true, 'msg' => $response]));
            exit;
        }

        $wompi_transferId = get_post_meta($order->get_id(), 'wompi_transferId', true);
        if ( $this->debug_mode() )
            $this->log->add( $this->id, 'wompi_transferId => ' .  print_r($wompi_transferId, true));
	
        if( !$wompi_transferId || empty( $wompi_transferId ) ){
            $response = 'Invalid payment method';
            if ( $this->debug_mode() )
                $this->log->add( $this->id, 'response => ' .  $response);

            echo(json_encode(['error' => true, 'msg' => $response]));
            exit;
        }

        $order_wompi_reference = $posted->data->transaction->id;
        $order_wompi_status = $posted->data->transaction->status;
        $order_wompi_status_msg = $posted->data->transaction->status_message;
        $environment = $posted->environment;

        if( ! $order->needs_payment() && $order_wompi_status !== 'VOIDED' ){

            $response = 'Order already processed';
            if ( $this->debug_mode() )
                $this->log->add( $this->id, 'response => ' .  $response);

            echo(json_encode(['error' => true, 'msg' => $response]));
            exit;
        }


        if( 'APPROVED' === $order_wompi_status && $order->needs_payment() && 'prod' === $environment){
            
            $order->payment_complete( $order_wompi_reference, sprintf('Pago completado mediante %s, ID de la transaccion %s', $posted->data->transaction->payment_method_type, $order_wompi_reference));
            $order->reduce_order_stock();

            $response = 'Order processed (APPROVED)';
            if ( $this->debug_mode() )
                $this->log->add( $this->id, 'response => ' .  $response);

            echo(json_encode(['error' => false, 'msg' => $response]));
            exit;

        } elseif( 'VOIDED' === $order_wompi_status && !$order->needs_payment() && 'prod' === $environment ){
            
            $order->update_status( 'cancelled', 'Pago anulado en Wompi de manera manual, pedido pasado a Cancelado');

            $response = 'Order processed ('.$order_wompi_status.')';
            if ( $this->debug_mode() )
                $this->log->add( $this->id, 'response => ' .  $response);

            echo(json_encode(['error' => false, 'msg' => $response]));
            exit;

        } elseif( ( in_array( $order_wompi_status, ['ERROR', 'DECLINED'] ) ) && $order->needs_payment() && 'prod' === $environment ){

            $order->update_status( 'failed', 'Pago fallido en Wompi, pedido pasado a Fallido');

            $response = 'Order processed ('.$order_wompi_status.')';
            if ( $this->debug_mode() )
                $this->log->add( $this->id, 'response => ' .  $response);

            echo(json_encode(['error' => false, 'msg' => $response]));
            exit;

        } else {

            if( !is_null($order_wompi_status_msg) ){

                $response = $order_wompi_status_msg . ' ('.$order_wompi_status.')';

            } else {
                
                $response = 'Order processed ('.$order_wompi_status.')';
            }

            $order->add_order_note( $order_wompi_reference . ' - ' . $response );

            
            if ( $this->debug_mode() )
                $this->log->add( $this->id, 'response => ' .  $response);

            echo(json_encode(['error' => false, 'msg' => $response]));
            exit;

        }

        die(json_encode(['error' => false, 'msg' => 'Success']));
        
    }

    /**Crea la transaccion en wompi para posteriormente consultar la URL y redirigir */
    public function registry_payment_intent( $order_id, $wompi_args ) {

        $url = $this->get_payment_url() . 'transactions';

        if ( $this->debug_mode() ){
            $this->log->add( $this->id, $order_id  . " - " . "============= ".__FUNCTION__." ===========" );
            $this->log->add( $this->id, $order_id  . " - " . 'wompi_args => ' . print_r( $wompi_args, true ) );
        }

        $order = wc_get_order($order_id);

        $order->add_order_note( 'Nuevo intento de pago via ' . $wompi_args['payment_method']['type'] );

        $args = array(
            'method'      => 'POST',
            'timeout'     => 5,
            'sslverify'   => false,
            'headers'     => array(
                'authorization' => 'Bearer ' . $this->get_public_key(),
            ),
            'body'        => json_encode($wompi_args, JSON_UNESCAPED_SLASHES ),
        );

        if ( $this->debug_mode() ){
            $this->log->add( $this->id, $order_id  . " - " . 'args => ' . print_r( $args, true ) );
        }

        $request = wp_remote_request( $url, $args );

        $response = json_decode(wp_remote_retrieve_body( $request ));

        if ( is_wp_error( $request ) || wp_remote_retrieve_response_code( $request ) != 201 ) {
            
            $this->log->add('error_' . $this->id, $order_id  . " - " . "============================================");
            $this->log->add('error_' . $this->id, $order_id  . " - " . "============= payment_intent url ===========");
            $this->log->add('error_' . $this->id, $order_id  . " - " . print_r( $url, true ) );
            
            $this->log->add('error_' . $this->id, $order_id  . " - " . "============= payment_intent args ===========");
            $this->log->add('error_' . $this->id, $order_id  . " - " . print_r( $args, true ) );
            
            // $this->log->add('error_' . $this->id, $order_id  . " - " . "============= payment_intent request ===========");
            // $this->log->add('error_' . $this->id, $order_id  . " - " . print_r( $request, true ) );
            
            $this->log->add('error_' . $this->id, $order_id  . " - " . "============= payment_intent response ===========");
            $this->log->add('error_' . $this->id, $order_id  . " - " . print_r( $response, true ) );

            $order->add_order_note( 'Se presento un error generando el intento de pago ' . print_r( $response, true ));

            if ( $this->debug_mode() ){
                $this->log->add( __FUNCTION__ . '_errors', $order_id  . " - " . 'response => ' . print_r( $response, true ) );
            }

            
            if( isset( $response->error->type ) ){

                if( isset( $response->error->messages ) ){

                    foreach( $response->error->messages as $field => $message ){

                        foreach( $message as $msg ){

                            if( 'reference' === $field && 'La referencia ya ha sido usada' == $msg ){
                                return ['error' => true, 'response' => $msg ];
                            }
                        }
                    }
                }
                
                return ['error' => true, 'response' => "Se presentó un error, por favor verifica la información ingresada e inténtalo nuevamente."];
            } else {
                return ['error' => true, 'response' => 'Se presentó un error, por favor verifica la información ingresada e inténtalo nuevamente'];
            }
        }

        if( isset( $response->data->id ) ){

            if ( $this->debug_mode() ){
                $this->log->add( $this->id, $order_id  . " - " . 'response => ' . print_r( $response, true ) );
            }

            update_post_meta( $order_id, 'wompi_transferId', $response->data->id );

            $order->add_order_note( 'Intento de pago generado exitosamente: ' . $response->data->id );

            return [
                'error' => false, 
                'response' => [
                    'transferId' => $response->data->id,
                ]
            ];
        }

        return ['error' => true, 'response' => 'Se presento un error, por favor intentalo nuevamente (402)'];

    }

    public function get_wompi_args( $order_id ){

        global $woocommerce;

        $presigned_acceptance_token = $this->get_presigned_acceptance_token();

        if( ! $presigned_acceptance_token ){
            return false;
        }			
        
        $order = new WC_Order( $order_id );
        
        $order_total = intval ( get_post_meta ( $order -> get_id(), '_order_total', true) ) * 100;

        $url_checkout = get_site_url();
        $url_checkout = add_query_arg( 'wc-api', get_parent_class( $this ), $url_checkout );
        $url_checkout = add_query_arg( 'order_id', $order_id, $url_checkout );

        // $url_checkout = $this->get_return_url( $order );

        $unique_reference = $order_id . '-' . time();
        
        $description = get_bloginfo( 'name' )." Pedido No. {$order_id}";

        $wompi_args = [
            'acceptance_token' 		=> (string)$presigned_acceptance_token,
            'amount_in_cents' 		=> (int)$order_total,
            'currency'				=> $order->get_currency(),
            'signature' 			=> $this->generate_signature($unique_reference),
            'customer_email' 		=> $order->get_billing_email(),
            'reference' 			=> (string)$unique_reference,
            'order_id' 			    => (string)$order_id,
            'redirect_url' 			=> $url_checkout,
            'payment_method' 			=> [
                'payment_description' => $description,
                // 'ecommerce_url' => $url_checkout,
            ],
            'customer_data'         => [
                'phone_number' => str_replace(["+", " ", "-", "(", ")"], "", $order->get_billing_phone() ),
                'full_name' => mb_convert_case( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(), MB_CASE_TITLE),
                'legal_id_type' => sanitize_text_field( get_post_meta($order_id, 'billing_tipo_documento', true) ),
                'legal_id' => sanitize_text_field( get_post_meta($order_id, 'billing_documento_ident', true) ),
            ],
        ];

        if( isset( $_COOKIE['wompi_sessionId'] ) && !empty( $_COOKIE['wompi_sessionId'] ) ){
            $wompi_args['session_id'] = $_COOKIE['wompi_sessionId'];
        }

        if( $this->isSandBox ) {
            $wompi_args['payment_method']['sandbox_status'] = "APPROVED";
        }

        return $wompi_args;
    }

    public function tokenize_card( $_args ){

        $url = $this->get_payment_url() . 'tokens/cards';

        if ( $this->debug_mode() ){
            $this->log->add( $this->id, "============= ".__FUNCTION__." ===========" );
            $this->log->add( $this->id, 'url => ' . print_r( $url, true ) );
        }

        $args = array(
            'method'      => 'POST',
            'timeout'     => 5,
            'sslverify'   => false,
            'headers'     => array(
                'authorization' => 'Bearer ' . $this->get_public_key(),
            ),
            'body'        => json_encode($_args, JSON_UNESCAPED_SLASHES ),
        );

        if ( $this->debug_mode() ){
            $this->log->add( $this->id, 'args => ' . print_r( $args, true ) );
        }

        $request = wp_remote_request( $url, $args );

        $response = json_decode(wp_remote_retrieve_body( $request ));

        if ( is_wp_error( $request ) || wp_remote_retrieve_response_code( $request ) != 201 ) {

            if( isset( $response->error ) && $response->error->type === 'INPUT_VALIDATION_ERROR' && isset( $response->error->messages )){

                foreach( $response->error->messages as $field => $message ){

                    foreach( $message as $msg ){

                        $msg = str_replace( 'Luhn check falló.', '', $msg );

                        if(isset( self::$card_field[$field] ) && !str_contains( $msg, 'patron' ) ){
                            wc_add_notice( '<strong>' . self::$card_field[$field] . '</strong>: ' . ucfirst( $msg ), 'error' );
                        } else {
                            wc_add_notice( '<strong>' . self::$card_field[$field] . '</strong>: Campo no válido', 'error' );
                        }
                    }
                }
            }

            if ( $this->debug_mode() ){
                $this->log->add( $this->id, "============= ".__FUNCTION__." ===========" );
                $this->log->add( $this->id, "============= payment_intent url ===========");
                $this->log->add( $this->id, print_r( $url, true ) );
                
                $this->log->add( $this->id, "============= payment_intent args ===========");
                $this->log->add( $this->id, print_r( $args, true ) );
                
                $this->log->add( $this->id, "============= payment_intent response ===========");
                $this->log->add( $this->id, print_r( $response, true ) );
            }

            $this->log->add('error_' . $this->id, "============================================");
            $this->log->add('error_' . $this->id, "============= payment_intent url ===========");
            $this->log->add('error_' . $this->id, print_r( $url, true ) );
            
            $this->log->add('error_' . $this->id, "============= payment_intent args ===========");
            $this->log->add('error_' . $this->id, print_r( $args, true ) );
            
            $this->log->add('error_' . $this->id, "============= payment_intent response ===========");
            $this->log->add('error_' . $this->id, print_r( $response, true ) );

        } else {

            if ( $this->debug_mode() ){
                $this->log->add( $this->id, " - " . 'response => ' . print_r( $response, true ) );
            }

            if( isset( $response->status ) && $response->status === 'CREATED' ){

                do_action('wc_wompi_on_token_created', $_args, $response->data->id );

                return $response->data->id;
            }
        }

        return false;
    }

    private function get_presigned_acceptance_token(){

        $url = $this->get_payment_url() . 'merchants/' . $this->get_public_key();

        if ( $this->debug_mode() ){
            $this->log->add( $this->id, "============= ".__FUNCTION__." ===========" );
            $this->log->add( $this->id, 'url => ' . print_r( $url, true ) );
        }

        $args = array(
            'method'      => 'GET',
            'timeout'     => 5,
            'sslverify'   => false
        );

        if ( $this->debug_mode() ){
            $this->log->add( $this->id, 'args => ' . print_r( $args, true ) );
        }

        $request = wp_remote_post( $url, $args );

        $response = json_decode(wp_remote_retrieve_body( $request ));

        if ( is_wp_error( $request ) || wp_remote_retrieve_response_code( $request ) != 200 ) {
            
            $this->log->add('error_' . $this->id, "======================".__FUNCTION__."======================");
            $this->log->add('error_' . $this->id, "============= payment_intent url ===========");
            $this->log->add('error_' . $this->id, print_r( $url, true ) );
            
            $this->log->add('error_' . $this->id, "============= payment_intent args ===========");
            $this->log->add('error_' . $this->id, print_r( $args, true ) );
            
            // $this->log->add('error_' . $this->id, "============= payment_intent request ===========");
            // $this->log->add('error_' . $this->id, print_r( $request, true ) );
            
            $this->log->add('error_' . $this->id, "============= payment_intent response ===========");
            $this->log->add('error_' . $this->id, print_r( $response, true ) );

            return false;

        } else {

            $this->acceptance_token = $response->data->presigned_acceptance->acceptance_token;

            return $this->acceptance_token;
        }

        return false;
        
    }

    public function get_payment_info( $transferId ){

        $url = $this->get_payment_url() . 'transactions/';

        $url = $url . $transferId;

        if ( $this->debug_mode() ){
            $this->log->add( $this->id, $transferId  . " - " . "============= ".__FUNCTION__." ===========" );
            $this->log->add( $this->id, $transferId  . " - " . 'url => ' . print_r( $url, true ) );
        }

        $args = array(
            'method'      => 'GET',
            'timeout'     => 5,
            'sslverify'   => false,
            'headers'     => array(
                'authorization' => 'Bearer ' . $this->get_public_key(),
            )
        );

        if ( $this->debug_mode() ){
            $this->log->add( $this->id, $transferId  . " - " . 'args => ' . print_r( $args, true ) );
        }

        $request = wp_remote_post( $url, $args );

        $response = json_decode(wp_remote_retrieve_body( $request ));

        if ( is_wp_error( $request ) || wp_remote_retrieve_response_code( $request ) != 200 ) {

            $this->log->add('error_' . $this->id, "============================================");
            $this->log->add('error_' . $this->id, "============= payment_intent url ===========");
            $this->log->add('error_' . $this->id, print_r( $url, true ) );
            
            $this->log->add('error_' . $this->id, "============= payment_intent args ===========");
            $this->log->add('error_' . $this->id, print_r( $args, true ) );
            
            // $this->log->add('error_' . $this->id, "============= payment_intent request ===========");
            // $this->log->add('error_' . $this->id, print_r( $request, true ) );
            
            $this->log->add('error_' . $this->id, "============= payment_intent response ===========");
            $this->log->add('error_' . $this->id, print_r( $response, true ) );

            return false;

        } else { 

            if( isset( $response->data ) && !empty( $response->data ) ){

                if ( $this->debug_mode() ){
                    $this->log->add( $this->id, $transferId  . " - " . 'response => ' . print_r( $response->data, true ) );
                }

                return $response->data;
            }

            return false;
        }
    }

    private function generate_signature( $unique_reference ){

        global $woocommerce;

        // $order = new WC_Order( $order_id );

        $order_id = explode('-', $unique_reference)[0];

        $order_total = intval(get_post_meta($order_id, '_order_total', true)) . '00';
        $order_currency = get_post_meta($order_id, '_order_currency', true);

        $signature = $unique_reference . $order_total . $order_currency . $this->integrity_key;

        $signature_sha256 = hash ("sha256", $signature);

        return $signature_sha256;

    }

    
    public function get_payment_url_for_transferId( $transferId ){

        sleep(1);

        $url = $this->get_payment_url(). 'transactions/';

        $url = $url . $transferId;

        if ( $this->debug_mode() ){
            $this->log->add( $this->id, $transferId  . " - " . "============= ".__FUNCTION__." ===========" );
            $this->log->add( $this->id, $transferId  . " - " . 'url => ' . print_r( $url, true ) );
        }

        $args = array(
            'method'      => 'GET',
            'timeout'     => 5,
            'sslverify'   => false,
            'headers'     => array(
                'authorization' => 'Bearer ' . $this->get_public_key(),
            )
        );

        if ( $this->debug_mode() ){
            $this->log->add( $this->id, $transferId  . " - " . 'args => ' . print_r( $args, true ) );
        }

        $request = wp_remote_post( $url, $args );

        $response = json_decode(wp_remote_retrieve_body( $request ));

        if ( is_wp_error( $request ) || wp_remote_retrieve_response_code( $request ) != 200 ) {

            $this->log->add('error_' . $this->id, "============================================");
            $this->log->add('error_' . $this->id, "============= payment_intent url ===========");
            $this->log->add('error_' . $this->id, print_r( $url, true ) );
            
            $this->log->add('error_' . $this->id, "============= payment_intent args ===========");
            $this->log->add('error_' . $this->id, print_r( $args, true ) );
            
            // $this->log->add('error_' . $this->id, "============= payment_intent request ===========");
            // $this->log->add('error_' . $this->id, print_r( $request, true ) );
            
            $this->log->add('error_' . $this->id, "============= payment_intent response ===========");
            $this->log->add('error_' . $this->id, print_r( $response, true ) );

            return false;

        } else {

            if ( $this->debug_mode() ){
                $this->log->add( $this->id, $transferId  . " - " . 'response => ' . print_r( $response, true ) );
            }

            if( isset( $response->data->payment_method->extra->async_payment_url ) && !empty( $response->data->payment_method->extra->async_payment_url ) ){

                return $response->data->payment_method->extra->async_payment_url;
            }

            return false;
        }
    }

    private function get_payment_url(){

        return $this->createUrl();

    }

}