<?php

require_once( plugin_dir_path( __FILE__ ) . '../vendor/Pakettikauppa/Client.php');
require_once( plugin_dir_path( __FILE__ ) . '../vendor/Pakettikauppa/Shipment.php');
require_once( plugin_dir_path( __FILE__ ) . '../vendor/Pakettikauppa/Shipment/Sender.php');
require_once( plugin_dir_path( __FILE__ ) . '../vendor/Pakettikauppa/Shipment/Receiver.php');
require_once( plugin_dir_path( __FILE__ ) . '../vendor/Pakettikauppa/Shipment/Info.php');
require_once( plugin_dir_path( __FILE__ ) . '../vendor/Pakettikauppa/Shipment/AdditionalService.php');

use Pakettikauppa\Shipment;
use Pakettikauppa\Shipment\Sender;
use Pakettikauppa\Shipment\Receiver;
use Pakettikauppa\Shipment\Info;
use Pakettikauppa\Shipment\AdditionalService;
use Pakettikauppa\Client;


/**
 * Pakettikauppa Class
 *
 * @class Pakettikauppa
 * @version  1.0.0
 * @since 1.0.0
 * @package  woocommerce-pakettikauppa
 * @author Seravo
 */
class WC_Pakettikauppa {
  private $pakettikauppa = null;
  private $errors = array();

  function __construct() {
    $this->id = 'wc_pakettikauppa';
  }

  public function load() {
    add_filter( 'plugin_row_meta', array( $this, 'plugin_row_meta' ), 10, 2 );
    add_action( 'add_meta_boxes', array( $this, 'register_meta_boxes' ) );
    add_action( 'save_post', array( $this, 'save_metabox' ), 10, 2 );
    add_action( 'admin_post_show_pakettikauppa', array( $this, 'show' ), 10 );
    add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );
    add_action( 'plugins_loaded', array ( $this, 'wc_pakettikauppa_load_textdomain' ) );

    add_action( 'woocommerce_email_order_meta', array( $this, 'attach_tracking_to_email' ), 10, 4 );
    add_action( 'woocommerce_review_order_after_shipping', array( $this, 'wc_pakettikauppa_pickup_point_field_html') );

    $this->wc_pakettikauppa_client = null;

    try {
      // Use option from database directly as WC_Pakettikauppa_Shipping_Method object is not accessible here
      $settings = get_option( 'woocommerce_WC_Pakettikauppa_Shipping_Method_settings', null );
      $account_number = $settings['mode'];
      $secret_key = $settings['secret_key'];
      $mode = $settings['mode'];
      $is_test_mode = ($mode == 'production' ? false : true);
      $this->wc_pakettikauppa_client = new Pakettikauppa\Client( array( 'api_key' => $account_number, 'secret' => $secret_key, 'test_mode' => $is_test_mode ) );
    } catch ( Exception $e ) {
      // @TODO handle errors
      die('pakettikauppa fail');
    }
  }

  /**
   * Load plugin textdomain
   *
   * @return void
   */
  public function wc_pakettikauppa_load_textdomain() {
    load_plugin_textdomain( 'wc-pakettikauppa', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
  }

  public function get_errors() {
      return $this->errors;
  }

  public function clear_errors() {
    unset( $this->errors );
    $this->errors = array();
  }

  public function add_error( $message ) {
    if ( ! empty( $message ) ) {
      array_push( $this->errors, $message );
    }
  }

  /**
   * Show row meta on the plugin screen.
   *
   * @param  mixed $links Plugin Row Meta
   * @param  mixed $file  Plugin Base file
   * @return  array
   */
  public static function plugin_row_meta( $links, $file ) {
    if ( plugin_basename( __FILE__ ) == $file ) {
      $row_meta = array(
        'service'    => '<a href="' . esc_url( 'https://pakettikauppa.fi' ) . '" aria-label="' . esc_attr__( 'Visit Pakettikauppa.fi', 'pakettikauppa' ) . '">' . esc_html__( 'Show site Pakettikauppa.fi', 'pakettikauppa' ) . '</a>',
      );
      return array_merge( $links, $row_meta );
    }
    return (array) $links;
  }

  public function register_meta_boxes() {
    foreach ( wc_get_order_types( 'order-meta-boxes' ) as $type ) {
      $order_type_object = get_post_type_object( $type );
      add_meta_box( 'wc-pakettikauppa', __( 'Pakettikauppa.fi', 'wc-pakettikauppa' ), array( $this, 'meta_box' ), $type, 'side', 'default' );
    }
  }

  public function admin_enqueue_scripts() {
    wp_enqueue_style( 'wc_pakettikauppa', plugin_dir_url( __FILE__ ) . '../assets/css/wc-pakettikauppa.css' );
    wp_enqueue_script( 'wc_pakettikauppa_js', plugin_dir_url( __FILE__ ) . '../assets/js/wc-pakettikauppa.js', array( 'jquery' ) );
  }

  public function get_default_service( $post, $order ) {
    $service = '2103';

    $order = new WC_Order( $post->ID );
    $shipping_methods = $order->get_shipping_methods();

    if ( ! empty( $shipping_methods ) ) {
      $shipping_method = reset( $shipping_methods );
      $ids = explode( ':', $shipping_method['method_id'] );
      $instance_id = $ids[1];

      $service = get_option( 'wc_pakettikauppa_shipping_method_' . $instance_id, '2103' );
    }

    return $service;
  }

  public function get_pickup_points( $postcode, $street_address = null, $country = null, $service_provider = null ) {
    try {
      $pickup_point_data = $this->wc_pakettikauppa_client->searchPickupPoints( $postcode, $street_address, $country, $service_provider);
      if ( $pickup_point_data == 'Authentication error' ) {
        // @TODO: Add proper error handling
      }
      return $pickup_point_data;
    } catch ( Exception $e ) {
      $this->add_error( 'Unable to connect to Pakettikauppa service.' );
      return [];
    }
  }

  /*
   * Customize the layout of the checkout screen so that there is a section
   * where the pickup point can be defined. Don't use the woocommerce_checkout_fields
   * filter, it only lists fields without values, and we need to know the postcode.
   * Also the woocommerce_checkout_fields has separate billing and shipping address
   * listings, when we want to have only one single pickup point per order.
   */
  public function wc_pakettikauppa_pickup_point_field_html( ) {

    $shipping_method_name = explode(':', WC()->session->get( 'chosen_shipping_methods' )[0])[0];
    $shipping_method_id = explode(':', WC()->session->get( 'chosen_shipping_methods' )[0])[1];

    // Bail out if the shipping method is not one of the pickup point services
    if ( ! in_array(
            $shipping_method_id,
            array(
              '2103',
              '80010',
              '90010',
              '90080'
            )
          )
        ) {
      return;
    }

    $pickup_point_data = '';
    $shipping_postcode = WC()->customer->get_shipping_postcode();

    $pickup_point_data = $this->get_pickup_points();
    $pickup_points = json_decode( $pickup_point_data );
    $options_array = array( '' => '- '. __('Select a pickup point', 'wc-pakettikauppa') .' -' );

    foreach ( $pickup_points as $key => $value ) {
      $pickup_point_key = $value->provider . ': ' . $value->name . ' (#' . $value->pickup_point_id . ')';
      $pickup_point_value = $value->provider . ': ' . $value->name . ' (' . $value->street_address . ')';
      $options_array[ $pickup_point_key ] = $pickup_point_value;
    }

    echo '
    <tr class="shipping-pickup-point">
      <th>' . __('Pickup point', 'wc-pakettikauppa') . '</th>
      <td data-title="' . __('Pickup point', 'wc-pakettikauppa') . '">';

    echo '<p>';
    printf(
        esc_html__( 'Choose one of the pickup points close to your postcode %s below:', 'wc-pakettikauppa' ),
        '<span class="shipping_postcode_for_pickup">'. $shipping_postcode .'</span>'
    );
    echo '</p>';

    woocommerce_form_field( 'pakettikauppa_pickup_point', array(
        'clear'       => true,
        'type'        => 'select',
        'custom_attributes' => array('style' => 'max-width:18em;'),
        'options'     => $options_array,
    ),  null );
    // WC()->cart['pakettikauppa_pickup_point_id']

    echo '</div>';

  }


  /**
   * Meta box for managing shipments.
   */
  public function meta_box( $post ) {
    $order = wc_get_order( $post->ID );

    $tracking_code = get_post_meta( $post->ID, 'wc_pakettikauppa_tracking_code', true);
    // var_dump($tracking_code);
    $cod = get_post_meta( $post->ID, 'wc_pakettikauppa_cod', true);
    $cod_amount = get_post_meta( $post->ID, 'wc_pakettikauppa_cod_amount', true);
    $cod_reference = get_post_meta( $post->ID, 'wc_pakettikauppa_cod_reference', true);
    $service_id = get_post_meta( $post->ID, 'wc_pakettikauppa_service_id', true);
    // var_dump($service_id);
    $pickup_point = $order->get_meta('Pickup point (from order)');
    // var_dump($pickup_point);
    $pickup_point_id = $order->get_meta('pakettikauppa_pickup_point_id');
    // var_dump($pickup_point_id);
    $status = get_post_meta( $post->ID, 'wc_pakettikauppa_shipment_status', true);

    // Set defaults
    if ( empty( $cod_amount) ) { $cod_amount = $order->get_total(); }
    if ( empty( $cod_reference) ) { $cod_reference = wc_pakettikauppa_calculate_reference( $post->ID ); }
    if ( empty( $service_id ) ) { $service_id = $this->get_default_service($post, $order); }

    $document_url = admin_url( 'admin-post.php?post=' . $post->ID . '&action=show_pakettikauppa&sid=' . $tracking_code );
    $tracking_url = wc_pakettikauppa_tracking_url( $service_id, $tracking_code );

    ?>
      <div>
        <?php if ( ! empty( $tracking_code ) ) { ?>
          <p class="pakettikauppa-shipment">
            <strong>
              <?php printf( '%1$s<br>%2$s<br>%3$s', $this->service_title($service_id), $tracking_code, wc_pakettikauppa_get_status_text($status) ); ?>
            </strong><br>

            <a href="<?php echo $document_url; ?>" target="_blank" class="download"><?php _e( 'Print document', 'wc-pakettikauppa' ) ?></a>&nbsp;-&nbsp;

            <?php if ( ! empty( $tracking_url ) ) : ?>
              <a href="<?php echo $tracking_url; ?>" target="_blank" class="tracking"><?php _e( 'Track', 'wc-pakettikauppa' ) ?></a>
            <?php endif; ?>
          </p>
        <?php } ?>

        <?php if ( empty( $tracking_code ) ) : ?>
          <div class="pakettikauppa-services">
            <fieldset>
              <h4><?php _e( 'Service', 'wc-pakettikauppa' ); ?></h4>
              <?php foreach ( self::services() as $key => $title ) { ?>
                <label for="service-<?php echo $key; ?>">
                  <input type="radio"
                    name="wc_pakettikauppa_service_id"
                    value="<?php echo $key; ?>"
                    id="service-<?php echo $key; ?>"
                    <?php
                    // Show as selected the pickup point by id
                    if ( $pickup_point_id == $key ) {
                      echo 'checked="checked"';
                    }
                    ?>
                  >
                  <span><?php print $title; ?></span>
                </label>
                <br>
              <?php } ?>
            </fieldset>
            <fieldset>
              <h4><?php _e( 'Additional services', 'wc-pakettikauppa' ); ?></h4>
              <input type="checkbox" name="wc_pakettikauppa_cod" value="1" id="wc-pakettikauppa-cod" <?php if ( $cod ) { ?>checked="checked"<?php } ?> />
              <label for="wc-pakettikauppa-cod"><?php _e( 'Cash on Delivery', 'wc-pakettikauppa' ); ?></label>

              <p class="form-field" id="wc-pakettikauppa-cod-amount-wrapper">
                <label for="wc_pakettikauppa_cod_amount"><?php _e( 'Amount (€):', 'wc-pakettikauppa' ) ?></label>
                <input type="text" name="wc_pakettikauppa_cod_amount" value="<?php echo $cod_amount; ?>" id="wc_pakettikauppa_cod_amount" />
              </p>

              <p class="form-field" id="wc-pakettikauppa-cod-reference-wrapper">
                <label for="wc_pakettikauppa_cod_reference"><?php _e( 'Reference:', 'wc-pakettikauppa' ) ?></label>
                <input type="text" name="wc_pakettikauppa_cod_reference" value="<?php echo $cod_reference; ?>" id="wc_pakettikauppa_cod_reference" />
              </p>

              <br><input type="checkbox" name="wc_pakettikauppa_pickup_points" value="1" id="wc-pakettikauppa-pickup-points" <?php if ( $pickup_point ) { ?>checked="checked"<?php } ?> />
              <label for="wc-pakettikauppa-pickup-points"><?php _e( 'Pickup Point', 'wc-pakettikauppa' ); ?></label>

             <?php
               // Use option from database directly as WC_Pakettikauppa_Shipping_Method object is not accessible here
               $settings = get_option( 'woocommerce_WC_Pakettikauppa_Shipping_Method_settings', null );
               $account_number = $settings['mode'];
               $secret_key = $settings['secret_key'];
               $mode = $settings['mode'];
               $is_test_mode = ($mode == 'production' ? false : true);
               $wc_pakettikauppa_client = new Pakettikauppa\Client( array( 'api_key' => $account_number, 'secret' => $secret_key, 'test_mode' => $is_test_mode ) );
               $pickup_point_data = $wc_pakettikauppa_client->searchPickupPoints( $order->get_shipping_postcode() );

               if ( $pickup_point_data == 'Authentication error' ) {
                 // @TODO: Add proper error handling
               }

               $pickup_points = json_decode( $pickup_point_data );
              ?>

              <p class="form-field" id="wc-pakettikauppa-pickup-points-wrapper">
                <select name="wc_pakettikauppa_pickup_point_id" id="wc_pakettikauppa_pickup_point_id">
                  <?php foreach ( $pickup_points as $key => $value ) : ?>
                    <option value="<?php echo( $value->pickup_point_id ); ?>" <?php if ( $pickup_point_id == $value->pickup_point_id ) { echo 'selected'; } ?> ><?php echo( $value->provider . ': ' . $value->name . ' (' . $value->street_address . ')' ); ?></option>
                  <?php endforeach; ?>
                </select>
              </p>

            </fieldset>
            <fieldset>

            </fieldset>
          </div>
          <p>
            <input type="submit" value="<?php _e( 'Create', 'wc-pakettikauppa' ); ?>" name="wc_pakettikauppa_create" class="button" />
          </p>
          <?php else : ?>
            <p>
              <input type="submit" value="<?php _e( 'Update Status', 'wc-pakettikauppa' ); ?>" name="wc_pakettikauppa_get_status" class="button" />
            </p>
          <?php endif; ?>
        </div>

    <?php
  }

  /**
   * Save metabox values
   */
  public function save_metabox( $post_id, $post ) {
    if ( ! current_user_can( 'edit_post', $post_id ) ) {
      return;
    }

    if ( wp_is_post_autosave( $post_id ) ) {
      return;
    }

    if ( wp_is_post_revision( $post_id ) ) {
      return;
    }

    if ( isset( $_POST['wc_pakettikauppa_create'] ) ) {
      $shipment = new Shipment();
      $service_id = $_REQUEST['wc_pakettikauppa_service_id'];
      $shipment->setShippingMethod( $service_id );
      $settings = WC()->shipping->shipping_methods['WC_Pakettikauppa_Shipping_Method']->settings;

      $sender = new Sender();
      $sender->setName1( $settings['sender_name'] );
      $sender->setAddr1( $settings['sender_address'] );
      $sender->setPostcode( $settings['sender_postal_code'] );
      $sender->setCity( $settings['sender_city'] );
      $sender->setCountry( 'FI' );

      $shipment->setSender($sender);

      $order = new WC_Order( $post_id );

      $receiver = new Receiver();
      $receiver->setName1( $order->get_formatted_shipping_full_name() );
      $receiver->setAddr1( $order->shipping_address_1 );
      $receiver->setPostcode( $order->shipping_postcode );
      $receiver->setCity( $order->shipping_city );
      $receiver->setCountry('FI');
      $receiver->setEmail( $order->billing_email );
      $receiver->setPhone( $order->billing_phone );

      $shipment->setReceiver( $receiver );

      $info = new Info();
      $info->setReference( $order->get_order_number() );

      $shipment->setShipmentInfo( $info );

      $cod = false;

      if ( $_REQUEST['wc_pakettikauppa_cod'] ) {
        $cod = true;
        $cod_amount = floatval( str_replace( ',', '.', $_REQUEST['wc_pakettikauppa_cod_amount'] ) );
        $cod_reference = trim( $_REQUEST['wc_pakettikauppa_cod_reference'] );
        $cod_iban = $settings['cod_iban'];
        $cod_bic = $settings['cod_bic'];

        $additional_service = new AdditionalService();
        $additional_service->addSpecifier( 'amount', $cod_amount );
        $additional_service->addSpecifier( 'account', $cod_iban );
        $additional_service->addSpecifier( 'codbic', $cod_bic );
        $additional_service->setServiceCode( 3101 );
        $shipment->addAdditionalService($additional_service);
      }

      $pickup_point = false;
      if ( $_REQUEST['wc_pakettikauppa_pickup_points'] ) {
        $pickup_point = true;
        $pickup_point_id = intval( $_REQUEST['wc_pakettikauppa_pickup_point_id'] );
        $shipment->setPickupPoint( $this->pickup_point_id );
      }

      try {
        if ( $this->wc_pakettikauppa_client->createTrackingCode($shipment) ) {
          $tracking_code = $shipment->getTrackingCode()->__toString();
          dir('code: ' . $tracking_code);
        } else {
          // @TODO error message
        }

        if ( ! empty( $tracking_code ) ) {
          $this->wc_pakettikauppa_client->fetchShippingLabel( $shipment );
          $upload_dir = wp_upload_dir();
          $filepath = trailingslashit( $upload_dir['basedir'] ) . 'wc-pakettikauppa/' . $tracking_code . '.pdf';
          file_put_contents( $filepath , base64_decode( $shipment->getPdf() ) );
        }

        // Update post meta
        update_post_meta( $post_id, 'wc_pakettikauppa_tracking_code', $tracking_code);
        update_post_meta( $post_id, 'wc_pakettikauppa_service_id', $service_id);
        update_post_meta( $post_id, 'wc_pakettikauppa_cod', $cod);
        update_post_meta( $post_id, 'wc_pakettikauppa_cod_amount', $cod_amount);
        update_post_meta( $post_id, 'wc_pakettikauppa_cod_reference', $cod_reference);
        update_post_meta( $post_id, 'wc_pakettikauppa_pickup_point', $pickup_point);
        update_post_meta( $post_id, 'wc_pakettikauppa_pickup_point_id', $pickup_point_id);

        $this->clear_errors();

        $document_url = admin_url( 'admin-post.php?post=' . $post_id . '&action=show_pakettikauppa&sid=' . $tracking_code );
        $tracking_url = wc_pakettikauppa_tracking_url( $service_id );

        // Add order note
        $dl_link = '<a href="' . $document_url . '">' . __( 'Print document', 'wc-pakettikauppa' ) . '</a>';
        $tracking_link = '<a href="' . $tracking_url . '">' . __( 'Track', 'wc-pakettikauppa' ) . '</a>';

        $order->add_order_note( sprintf( __('Created Pakettikauppa.fi %1$s shipment.<br>%2$s<br>%1$s - %3$s', 'wc-pakettikauppa'), $this->service_title($service_id), $tracking_code, $dl_link, $tracking_link ) );

        // @TODO check corrects shipment stuff
      } catch ( Exception $e ) {
        $order->add_order_note( sprintf( __('Failed to create Pakettikauppa.fi  shipment. Errors: %s', 'wc-pakettikauppa'), join( ', ', $shipment->get_errors() ) ) );
        add_action( 'admin_notices', 'add_error_notice' );

        // @TODO errors

        return;
      }

    } elseif ( isset( $_POST['wc_pakettikauppa_get_status'] ) ) {
      try {
         $tracking_code = get_post_meta( $post_id, 'wc_pakettikauppa_tracking_code', true);
         // error_log($tracking_code);

         if ( ! empty( $tracking_code ) ) {
           $result = $this->wc_pakettikauppa_client->getShipmentStatus($tracking_code);
           // error_log($result);
           // var_dump($result);
           $data = json_decode( $result );
           $status_code = $data[0]->{'status_code'};
           update_post_meta( $post_id, 'wc_pakettikauppa_shipment_status', $status_code );
         }

         $this->clear_errors();
      } catch ( Exception $e ) {
        $this->add_error( $e->getMessage() );
        return;
      }

    } else {
      return;
    }
  }


  /**
   * Shipment services
   */
  public static function services() {
    $services = array();

    // @TODO: Save shipping method list as transient for 24 hours or so to avoid doing unnecessary lookups
    // @TODO: File bug upstream about result being string instead of object by default
    // We cannot access the WC_Pakettikauppa_Shipping_Method here as it has not yet been initialized,
    // so access the settings directly from database using option name.
    $settings = get_option( 'woocommerce_WC_Pakettikauppa_Shipping_Method_settings', null );
    $account_number = $settings['mode'];
    $secret_key = $settings['secret_key'];
    $mode = $settings['mode'];
    $is_test_mode = ($mode == 'production' ? false : true);
    $wc_pakettikauppa_client = new Pakettikauppa\Client( array( 'api_key' => $account_number, 'secret' => $secret_key, 'test_mode' => $is_test_mode ) );
    $all_shipping_methods = json_decode($wc_pakettikauppa_client->listShippingMethods());

    // List all available methods as shipping options on checkout page
    if ( ! empty( $all_shipping_methods ) ) {
        foreach ( $all_shipping_methods as $shipping_method ) {
          $services[$shipping_method->shipping_method_code] = sprintf( '%1$s %2$s', $shipping_method->service_provider, $shipping_method->name );
        }
    }
    return $services;
  }

  /**
   * Title for service
   */
  public static function service_title( $service_code ) {
    $services = self::services();
    if ( isset( $services[$service_code] ) ) {
      return $services[$service_code];
    }

    return false;
  }

  /**
   * Output shipment label as PDF.
   */
  public function show() {
    $shipment_id = false;

    // Find shipment ID either from GET parameters or from the order
    // data.
    if ( isset( $_REQUEST['sid'] ) ) {
      $shipment_id = $_REQUEST['sid'];
    } else {
      $post = get_post( $_REQUEST['post'] );
      $shipment = new WC_Pakettikauppa_Shipment( $post );
      $shipment->set_existing();

      if ( ! empty( $shipment->id ) ) {
        $shipment_id = $shipment->id;
      }
    }

    if ( false != $shipment_id ) {
      $upload_dir = wp_upload_dir();

      // Read file
      $filepath = trailingslashit( $upload_dir['basedir'] ) . 'wc-pakettikauppa/' . $shipment_id . '.pdf';
      $contents = file_get_contents( $filepath );

      // Output
      header('Content-type:application/pdf');
      header("Content-Disposition:inline;filename='{$shipment_id}.pdf'");
      print $contents;
      exit;
    }

    _e( 'Cannot find shipment with given shipment number.', 'wc-pakettikauppa' );
    exit;
  }

  /**
   * Attach tracking URL to email.
   */
  public function attach_tracking_to_email( $order, $sent_to_admin = false, $plain_text = false, $email = null ) {

    $add_to_email = WC()->shipping->shipping_methods['WC_Pakettikauppa_Shipping_Method']->settings['add_tracking_to_email'];

    if ( 'yes' === $add_to_email && isset( $email->id ) && 'customer_completed_order' === $email->id ) {
      // @TODO: WC_Pakettikauppa_Shipment or WC_Shipment is not loaded in this namespace,
      // it should be loaded before used. Fix this to get confirmation emails to work.
      $shipment = new WC_Pakettikauppa_Shipment( $order->post );
      $shipment->set_existing();

      if ( isset( $shipment->id ) && ! empty( $shipment->id ) && ! empty( $shipment->tracking_url() ) ) {
        if ( $plain_text ) {
          echo sprintf( __( "You can track your order at %1$s.\n\n", 'wc-pakettikauppa' ), $shipment->tracking_url() );
        } else {
          echo '<h2>' . __( 'Tracking', 'wc-pakettikauppa' ) . '</h2>';
          echo '<p>' . sprintf( __( 'You can <a href="%1$s">track your order</a> with tracking code %1$s.', 'wc-pakettikauppa' ), $shipment->tracking_url(), $shipment->id ) . '</p>';
        }
      }
    }
  }

  function add_error_notice() {
    $class = 'notice notice-error';
    $message = _e( 'Operation failed! Please try again.', 'wc-pakettikauppa' );
    printf( '<div class="%1$s"><p>%2$s</p></div>', esc_attr( $class ), esc_html( $message ) );
  }

}