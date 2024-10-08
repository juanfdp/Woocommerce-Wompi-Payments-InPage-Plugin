<?php

class Woocommerce_Wompi_Payments_Plugin
{
    /**
     * Filepath of main plugin file.
     *
     * @var string
     */
    public $file;
    /**
     * Plugin version.
     *
     * @var string
     */
    public $version;
    /**
     * @var string
     */
    public $name;
    /**
     * Absolute plugin path.
     *
     * @var string
     */
    public $plugin_path;
    /**
     * Absolute plugin URL.
     *
     * @var string
     */
    public $plugin_url;
    /**
     * Absolute path to plugin includes dir.
     *
     * @var string
     */
    public $includes_path;
    /**
     * @var string
     */
    public $lib_path;
    /**
     * @var string
     */
    public $payment_methods_path;
    /**
     * lib path
     *
     * @var WC_Logger
     */
    public $logger;
    /**
     * @var bool
     */
    private $_bootstrapped = false;

    public function __construct($file, $version, $name)
    {
        $this->file = $file;
        $this->version = $version;
        $this->name = $name;
        // Path.
        $this->plugin_path   = trailingslashit( plugin_dir_path( $this->file ) );
        $this->plugin_url    = trailingslashit( plugin_dir_url( $this->file ) );
        $this->includes_path = $this->plugin_path . trailingslashit( 'includes' );
        $this->lib_path = $this->plugin_path . trailingslashit( 'lib' );
        $this->payment_methods_path = $this->plugin_path . trailingslashit( 'payment-methods' );
        $this->logger = new WC_Logger();
    }

    public function run_woocommerce_wompi_payments()
    {

        try{
            if ($this->_bootstrapped){
                throw new Exception( $this->name . ' solamente puede ser llamado una sola vez!');
            }
            $this->_run();
            $this->_bootstrapped = true;
        }catch (Exception $e){
            if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
                wompi_payments_admin_notices('Wompi Payments:: ' . $e->getMessage());
            }
        }
    }

    protected function _run()
    {
        require_once ($this->payment_methods_path . 'class-wompi-payment-pse.php');
        require_once ($this->includes_path . 'class-woocommerce-wompi-payments.php');
        require_once ($this->payment_methods_path . 'class-wompi-payment-credit-cards.php');
        require_once ($this->payment_methods_path . 'class-wompi-payment-bancolombia-button.php');
        require_once ($this->payment_methods_path . 'class-wompi-payment-nequi.php');
        require_once ($this->payment_methods_path . 'class-wompi-payment-cash-bancolombia.php');

        add_filter( 'plugin_action_links_' . plugin_basename( $this->file), array( $this, 'plugin_action_links' ) );
        add_filter( 'woocommerce_payment_gateways', array($this, 'woocommerce_wompi_payments_add_gateway'));
        add_filter( 'woocommerce_checkout_fields', array($this, 'custom_woocommerce_checkout_fields'));
        add_action( 'woocommerce_after_checkout_validation', array($this, 'validate_custom_checkout_fields'), 10 , 2);
        add_action( 'woocommerce_checkout_update_user_meta', array($this, 'update_user_meta'), 10, 2 );
        add_action( 'woocommerce_checkout_update_order_meta', array($this, 'save_custom_fields_in_order'), 10, 1);
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
        add_action( 'wp_head', array( $this, 'enqueue_head_scripts' ) );
        
    }

    public function plugin_action_links( $links ) {
        $plugin_links = array();
        $plugin_links[] = '<a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout&section=woocommerce_wompi_pse') . '">' . 'Ajustes' . '</a>';
        return array_merge( $plugin_links, $links );
    }

    public function custom_woocommerce_checkout_fields($fields) {

        if( !isset( $fields['billing']['billing_tipo_documento'] ) ){

            $fields['billing']['billing_tipo_documento'] = array(
                'type'          => 'select',
                'input_class' => array(
                    'wc-enhanced-select2',
                ),
                'required'      => true,
                'priority'      => 21,
                'class'      	=> array( 'form-row-first' ),
                'default'      	=> 'CC',
                'select2'      	=> true,
                'placeholder'	=> __( 'Selecciona una opción. ' ),
                'label'         => __( 'Tipo de documento' ),
                'options'       => array(
                                    'CC' 			=> 	'Cedula de ciudadanía',
                                    'CE' 		  	=> 	'Cedula extrajera',
                                    'PA' 		  	=> 	'Pasaporte',
                                    'NIT' 			=> 	'NIT',
                                    // 'Otro' 			=> 	'Otro',
                                )
            );

        }

        if( !isset( $fields['billing']['billing_documento_ident'] ) ){

            $fields['billing']['billing_documento_ident'] = array(
                'type'          => 'text',
                'required'      => true,
                'validate'      => ['billing_documento_ident'],
                'priority'      => 22,
                'class'      	=> array( 'form-row-last' ),
                'label'         => __( 'No. Documento' ),	
            );
        }

        return $fields;
    }

    public function validate_custom_checkout_fields( $data, $errors ){

        foreach ( WC()->checkout()->get_checkout_fields() as $fieldset_key => $fieldset ) {
            
            foreach ( $fieldset as $key => $field ) {

                if ( ( isset( $field['validate'] ) ) ) {

                    if( $key == 'billing_documento_ident' ) {

						if($data[ 'billing_tipo_documento' ] === 'CC') {

							if ( ! empty( $data[ $key ] ) && ! preg_match( '/^((\d{8})|(\d{7})|(\d{6})|(\d{9})|(\d{10})|(\d{11}))?$/', $data[ $key ] ) ) {
								$errors->add( 'validation', '<strong>Facturación '.$field['label'].'</strong> has ingresado un valor no valido' );
							}						
						}

						if($data[ 'billing_tipo_documento' ] === 'NIT') {

							if ( ! empty( $data[ $key ] ) && ! preg_match( '/^((\d{8})|(\d{10})|(\d{9})|(\d{11}))-\d{1}?$/', trim($data[ $key ] ) ) )
							{
								$errors->add( 'validation', '<strong>Facturación '.$field['label'].'</strong> has ingresado un valor no valido, el NIT debe contener el digito de verificación (Ej. 123456789-1)' );
							}
						}
					}
                }
            }
        }
    }

    
    public function update_user_meta( $customer_id, $posted ) {

        if (isset($posted['billing_tipo_documento'])) update_user_meta( $customer_id, 'billing_tipo_documento', esc_attr($posted['billing_tipo_documento']));
        if (isset($posted['billing_documento_ident'])) update_user_meta( $customer_id, 'billing_documento_ident', esc_attr(trim( str_replace(" ", "", $posted['billing_documento_ident'] ))));
    }

    public function save_custom_fields_in_order( $order_id ){
        
        if (isset($_POST['billing_tipo_documento'])) update_post_meta( $order_id, 'billing_tipo_documento', esc_attr($_POST['billing_tipo_documento']));	
        if (isset($_POST['billing_documento_ident'])) update_post_meta( $order_id, 'billing_documento_ident', esc_attr(   trim( str_replace(" ", "", $_POST['billing_documento_ident'] ))   ));
        
    }


    public function woocommerce_wompi_payments_add_gateway($methods)
    {
        $methods[] = 'WC_Wompi_PSE';
        $methods[] = 'WC_Wompi_Credit_Cards';
        $methods[] = 'WC_Wompi_Bancolombia_Button';
        $methods[] = 'WC_Wompi_Nequi';
        $methods[] = 'WC_Wompi_Cash_Bancolombia';
        return $methods;
    }

    public function nameClean($domain = false)
    {
        $name = ($domain) ? str_replace(' ', '-', $this->name)  : str_replace(' ', '', $this->name);
        return strtolower($name);
    }

    public function enqueue_head_scripts(){
        
        if( !is_admin() ){

            $wompi_payments = new Woocommerce_Wompi_Payments();

            if( ! $wompi_payments->isTest ){
                ?>
                <script src="https://cdn.wompi.co/libs/js/v1.js" data-public-key="<?php echo( $wompi_payments->get_public_key() );?>"></script>
                <script src="https://cdn.jsdelivr.net/npm/js-cookie/dist/js.cookie.min.js"></script>
                <?php       
            }
        }
    }

    public function enqueue_scripts() {

        if ( is_checkout() ){
            
            wp_enqueue_style('woocommerce-wompi-payments-styles', $this->plugin_url . 'assets/css/style.css', array(), $this->version );
        }

        $available_payment_gateways = array_keys( WC()->payment_gateways->get_available_payment_gateways() );

        if( in_array( 'woocommerce_wompi_credit_cards', $available_payment_gateways ) ) {

            wp_enqueue_script( 'woocommerce-wompi-payments-plugin-core', $this->plugin_url . 'assets/js/wompi-payments-plugin-core.js', array( 'jquery' ), $this->version, true );

            if( is_checkout() ){

                wp_enqueue_script( 'woocommerce-wompi-payments-cardjs', $this->plugin_url . 'assets/libs/card-js/card-js.min.js', array( 'jquery' ), $this->version, true );
                wp_enqueue_style('woocommerce-wompi-payments-cardcss', $this->plugin_url . 'assets/libs/card-js/card-js.min.css', array(), $this->version );
                wp_enqueue_script( 'woocommerce-wompi-payments-plugin-card-js', $this->plugin_url . 'assets/js/wompi-payments-plugin-card-js.js', array( 'jquery', 'woocommerce-wompi-payments-cardjs' ), $this->version, true );

                wp_localize_script( 'woocommerce-wompi-payments-cardjs', 'woocommerce_wompi_payments_cardjs', [
                    'installments_title' => __('Número de cuotas', 'woocommerce-wompi-payments-plugin')
                ]);
            }
        }

        if( in_array( 'woocommerce_wompi_nequi', $available_payment_gateways ) ) {

            if( is_checkout() ){
                wp_enqueue_script( 'woocommerce-wompi-payments-nequi-sa', "https://cdn.jsdelivr.net/npm/sweetalert2@11", array( 'jquery' ), $this->version, true );
                wp_enqueue_script( 'woocommerce-wompi-payments-plugin-nequi', $this->plugin_url . 'assets/js/wompi-payments-plugin-nequi.js', array( 'jquery', 'woocommerce-wompi-payments-nequi-sa' ), $this->version, true );

                wp_localize_script( 'woocommerce-wompi-payments-plugin-nequi', 'wompi_payments_plugin_nequi', [
                    'logo_url' => $this->plugin_url . 'assets/animations/new_notification.gif',
                    'check_your_phone_msg' => __('Se ha enviado una notificación a tu celular, ingresa a la App de Nequi para continuar la aprobación del pago</br></br><b>00:59</b>', 'woocommerce-wompi-payments-plugin'),
                    'seconds_to_wait' => 90,
                ]);
            }
        }
    }

    public function log($message = '') {
        if (is_array($message) || is_object($message))
            $message = print_r($message, true);

        $this->logger->add('woocommerce-wompi-payments-plugin', $message);
    }

    public function get_available_payment()
    {
        $activated_ones = array_keys( WC()->payment_gateways->get_available_payment_gateways() );

        return in_array( 'woocommerce_wompi_pse', $activated_ones );
    }

    public function createUrl( $sandbox )
    {
        if ( $sandbox ){

            $url = "https://sandbox.wompi.co/v1/";

        }else{

            $url = "https://production.wompi.co/v1/";
        }
        
        return $url;
    }

    public function getDefaultCountry()
    {
        $woo_countries = new WC_Countries();
        $default_country = $woo_countries->get_base_country();

        return $default_country;
    }

}