<?php

namespace Woo_Pakettikauppa_Core;

// Prevent direct access to this script
if ( ! defined('ABSPATH') ) {
  exit;
}

if ( ! class_exists(__NAMESPACE__ . '\Core') ) {
  abstract class Core {
    public $version = null;

    public $root_file;
    public $basename;
    public $dir;
    public $dir_url;
    public $templates_dir;
    public $templates;
    public $prefix;
    public $params_prefix;

    public $shippingmethod; // Name of the shipping method. Not to be confused with $shipping_method_instance!
    public $vendor_name;
    public $vendor_fullname;
    public $vendor_url;
    public $vendor_logo;
    public $tracking_base_url;
    public $setup_background;
    public $setup_page;

    public $admin;
    public $frontend;
    public $text;
    public $shipment;
    public $shipping_method_instance; // Added as an afterthought to fix a bug, merge with $shippingmethod in the future.
    public $setup_wizard;
    public $product;
    public $shortcode;

    public $api_config; // Used by Pakettikauppa\Client
    public $api_comment; // Used by ^
    public $api_mode;

    public $order_pickup;
    public $order_pickup_url;

    public static $instance; // The class is a singleton.

    /**
     * Takes an options array with the following key-values.
     *
     * [
     *   'root' => __FILE__, // Reference to the file the plugin was loaded from. Why? Not all of the code is refactored.
     *   'textdomain' => 'woo-pakettikauppa', // Self explanatory
     *   'shipping_method_name' => 'pakettikauppa_shipping_method', // ID to use for the WooCommerce shipping method. Must be unique.
     *
     *   // Branding options
     *   'vendor_name' => 'Pakettikauppa',
     *   'vendor_url' => 'https://www.pakettikauppa.fi/',
     *   'vendor_logo' => 'assets/img/pakettikauppa-logo.png',
     *   'setup_background' => 'assets/img/pakettikauppa-background.jpg',
     *   'setup_page' => 'wcpk-setup',
     * ]
     */
    public function __construct( $config = array() ) {
      $this->version = $config['version'];
      $this->root_file = $config['root'];
      $this->basename = plugin_basename($config['root']);
      $this->dir = plugin_dir_path($config['root']);
      $this->dir_url = plugin_dir_url($config['root']);
      $this->templates_dir = $config['templates_dir'] ?? plugin_dir_path($config['root']) . 'templates/';
      $this->templates = (object) ($config['templates'] ?? array(
        'checkout_pickup' => 'pakettikauppa/checkout-pickup.php',
        'account_order' => 'pakettikauppa/myaccount-order.php',
        'tracking_email' => (object) array(
          'html' => 'pakettikauppa/tracking-email-html.php',
          'txt' => 'pakettikauppa/tracking-email-txt.php',
        ),
      ));

      $this->prefix = $config['prefix'] ?? 'wc_pakettikauppa';
      $this->params_prefix = ($config['params_prefix'] ?? 'pakettikauppa') . '_';
      $this->shippingmethod = $config['shipping_method_name'] ?? str_replace('wc_', '', $this->prefix) . '_shipping_method';

      $this->vendor_name = $config['vendor_name'] ?? 'Pakettikauppa';
      $this->vendor_fullname = $config['vendor_fullname'] ?? 'Woocommerce Pakettikauppa';
      $this->vendor_url = $config['vendor_url'] ?? 'https://www.pakettikauppa.fi';
      $this->vendor_logo = $this->dir_url . ($config['vendor_logo'] ?? 'assets/img/pakettikauppa-logo.png');

      $this->tracking_base_url = $config['tracking_base_url'] ?? 'https://www.pakettikauppa.fi/seuranta/?';

      $this->setup_background = $this->dir_url . ($config['setup_background'] ?? 'assets/img/pakettikauppa-background.jpg');
      $this->setup_page = $config['setup_page'] ?? 'wcpk-setup';

      $this->text = $this->load_text_class();

      $this->api_config = apply_filters('posti_api_configs', $config['pakettikauppa_api_config'] ?? array());
      $this->api_mode = apply_filters('posti_api_mode', 'production');
      $this->api_comment = $config['pakettikauppa_api_comment'] ?? 'From WooCommerce';

      $this->order_pickup = $config['order_pickup'] ?? false;
      $this->order_pickup_url = $config['order_pickup_callback_url'] ?? false;

      self::$instance = $this;

      $this->load_wc_hpos_class();
      $this->load_wc_blocks_class();

      add_action(
        'plugins_loaded', //'wp_loaded',
        function() {
          $this->load();
          $this->load_textdomain();
        }
      );
      $this->load_shipping_method();
      $this->add_shipping_method();
    }

    /**
     * Get class instance. Only used by Shipping_Method class, which can't be injected with
     * $this. After legacy shipping method is removed, rethink about the existence of this, as it's a terrible hack.
     *
     * See https://github.com/Seravo/woo-pakettikauppa/issues/96.
     */
    public static function get_instance() {
      return self::$instance;
    }

    /**
     * Sanity check. Is WooCommerce active?
     */
    public function woocommerce_exists() {
      if ( function_exists('WC') ) {
        return true;
      }

      return false;
    }

    /**
     * Override to stop the class from loading
     */
    public function can_load() {
      return true;
    }

    public function load() {
      if ( ! $this->can_load() ) {
        return;
      }

      if ( ! $this->woocommerce_exists() ) {
        add_action(
          'admin_notices',
          function() {
            echo '<div class="notice notice-error">';
            echo '<p>' . $this->text->no_woo_error() . '</p>';
            echo '</div>';
          }
        );

        return;
      }

      $shipment_exception = null;

      try {
        $this->shipment = $this->load_shipment_class();
      } catch ( \Exception $e ) {
        $shipment_exception = $e->getMessage();
      }

      /**
       * If the shipping method is added too early, errors will ensue.
       * If the shipping method is added too late, errors will ensue.
       */
      add_action(
        'wp_loaded', //woocommerce_shipping_init',
        function() {
          // Instance is only used for hacking classes together.
          // It's not used by WooCommerce. WooCommerce creates it's own instances, otherwise the legacy
          // shipping method breaks. If this class doesn't contain the shipping method class instance
          // things like setup wizard break.
          if ( ! $this->shipping_method_instance ) {
            $this->shipping_method_instance = $this->load_shipping_method_class();
          }
          $this->add_shipping_method();
        }
      );
      /*
      //always load shipping method if not already loaded
      if ( ! $this->shipping_method_instance ) {
        //$this->shipping_method_instance = $this->load_shipping_method_class(); // this seems to break stuff
      }
      */

      if ( is_admin() ) {
        $this->admin = $this->load_admin_class();
        $this->setup_wizard = $this->maybe_load_setup_wizard();

        if ( $shipment_exception ) {
          $this->admin->add_error($shipment_exception);
          $this->admin->add_error_notice($shipment_exception);
        }
        //load check tool class
        $this->load_check_tool_class();

        if ( $this->order_pickup ) {
          //load manifest class
          $this->load_manifest_class();
        }
      }

      // Always load classes
      $this->frontend = $this->load_frontend_class();
      $this->product = $this->load_product_class();
      $this->shortcode = $this->load_shortcode_class();

      if ( $shipment_exception ) {
        $this->frontend->add_error($shipment_exception);
        $this->frontend->display_error();
      }

      return $this;
    }

    public function load_textdomain() {
      load_plugin_textdomain(
        'woo-pakettikauppa',
        false,
        dirname($this->basename) . '/core/languages/'
      );
      load_plugin_textdomain(
        'woo-posti_shipping',
        false,
        dirname($this->basename) . '/core/languages/'
      );
    }

    public function load_shipping_method() {
      add_action(
        'woocommerce_shipping_init',
        function() {
          if ( ! $this->shipping_method_instance ) {
            $this->shipping_method_instance = $this->load_shipping_method_class();
          }
        }
      );
    }

    public function add_shipping_method() {
      add_filter(
        'woocommerce_shipping_methods',
        function( $methods ) {
          // Ideally we'd control the class init ourselves, but the legacy shipping method doesn't work
          // if WC doesn't control it.
          // $methods[$this->shippingmethod] = $this->shipping_method_instance;

          $methods[$this->shippingmethod] = __NAMESPACE__ . '\Shipping_Method';

          return $methods;
        }
      );
    }

    /**
     * Override this method to load a custom Text class
     */
    protected function load_text_class() {
      require_once 'class-text.php';

      return new Text($this);
    }

    /**
     * Override this method to load a custom Frontend class
     */
    protected function load_frontend_class() {
      require_once 'class-frontend.php';

      $frontend = new Frontend($this);
      $frontend->load();

      return $frontend;
    }

    /**
     * Override this method to load a custom Admin class
     */
    protected function load_admin_class() {
      require_once 'class-admin.php';

      $admin = new Admin($this);
      $admin->load();

      return $admin;
    }

    /**
     * Override this method to load a custom WC HPOS class
     */
    protected function load_wc_hpos_class() {
      require_once 'class-wc-hpos.php';

      $wc_hpos = new Wc_Hpos($this);
      $wc_hpos->load();

      return $wc_hpos;
    }

    /**
     * Override this method to load a custom WC blocks class
     */
    protected function load_wc_blocks_class() {
      require_once 'class-wc-blocks.php';

      $wc_blocks = new Wc_Blocks($this);
      $wc_blocks->load();

      return $wc_blocks;
    }

    /**
     * Override this method to load a custom Check Tool class
     */
    protected function load_check_tool_class() {
      require_once 'class-check-tool.php';

      $check_tool = new Check_Tool($this);

      return $check_tool;
    }

    /**
     * Override this method to load a custom Manifest class
     */
    protected function load_manifest_class() {
      require_once 'class-manifest.php';

      $check_tool = new Manifest($this);

      return $check_tool;
    }

    /**
     * Override to change the setup wizard location. Essential for whitelabel!
     */
    protected function maybe_load_setup_wizard() {
      $page = filter_input(INPUT_GET, 'page', FILTER_SANITIZE_SPECIAL_CHARS);

      if ( $page === $this->setup_page ) {
        return $this->load_setup_wizard_class();
      }

      return false;
    }

    /**
     * Override this method to load a custom Setup_Wizard class
     */
    protected function load_setup_wizard_class() {
      require_once 'class-setup-wizard.php';

      return new Setup_Wizard($this);
    }

    /**
     * Override this method to load a custom Shipment class
     */
    protected function load_shipment_class() {
      require_once 'class-shipment.php';

      $shipment = new Shipment($this);
      $shipment->load();

      return $shipment;
    }

    /**
     * Override this method to load a custom Shipping_Method class
     */
    protected function load_shipping_method_class() {
      require_once 'class-shipping-method.php';

      $method = new Shipping_Method();
      // We can't inject the core to the shipping method class if WooCommerce controls
      // the init of it. This class was turned into a singleton to go around that.
      // $method->injectCore($this)->load();
      // $method->load();

      return $method;
    }

    /**
     * Override this method to load a custom Product class
     */
    protected function load_product_class() {
      require_once 'class-product.php';

      $product = new Product($this);
      $product->load();

      return $product;
    }

    /**
     * Override this method to load a custom Product class
     */
    protected function load_shortcode_class() {
      require_once 'class-shortcode.php';

      $shortcode = new Shortcode($this);
      $shortcode->load();

      return $shortcode;
    }
  }
}
