<?php
/**
 * Behavioral test for one-time product fee grouping.
 *
 * Runs the real Cart::add_product_fees() against a fake cart that mimics
 * WooCommerce's WC_Cart_Fees rule: the fee ID is sanitize_title( name ) and a
 * second fee with an existing ID is rejected.
 *
 * Run: php tests/test-product-fee-grouping.php
 */

namespace {
    define( 'ABSPATH', __DIR__ . '/../' );

    function add_filter() {}
    function add_action() {}
    function is_admin() { return false; }
    function absint( $v ) { return abs( (int) $v ); }
    function wp_json_encode( $v ) { return json_encode( $v ); }
    function __( $text ) { return $text; }
    function wc_tax_enabled() { return false; }
    function wp_strip_all_tags( $s ) { return strip_tags( $s ); }
    function sanitize_title( $title ) {
        return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( $title ) ), '-' );
    }

    class Fake_Product {
        private $name;
        public function __construct( $name ) { $this->name = $name; }
        public function get_name() { return $this->name; }
    }

    class Fake_Cart {
        public $items = array();
        public $fees  = array();
        public function get_cart() { return $this->items; }
        // Same rule as WC_Cart_Fees::add_fee().
        public function add_fee( $name, $amount, $taxable = false, $tax_class = '' ) {
            $id = sanitize_title( $name );
            if ( isset( $this->fees[ $id ] ) ) {
                return false;
            }
            $this->fees[ $id ] = array( 'name' => $name, 'amount' => $amount );
            return true;
        }
    }
}

namespace Bossier\Calculator {
    class Calculator {
        public function __construct( $id = 0 ) {}
        public function is_valid() { return false; }
        public function get_settings() { return array(); }
    }
}

namespace {
    require_once __DIR__ . '/../frontend/class-cart.php';

    $pass = 0;
    $fail = 0;

    function check( string $label, bool $condition, string $detail = '' ): void {
        global $pass, $fail;
        if ( $condition ) {
            echo "[PASS] $label\n";
            $pass++;
        } else {
            echo "[FAIL] $label" . ( $detail ? " - $detail" : '' ) . "\n";
            $fail++;
        }
    }

    /**
     * Build a calculator cart item.
     */
    function make_item( $product_id, $a, $b, $c, $qty, $color = 'Grijs', $fee = 150.0 ) {
        $selections = array( 'fa' => $a, 'fb' => $b, 'fc' => $c, 'fcolor' => $color, 'fqty' => $qty );
        $display    = array(
            'fa'     => array( 'label' => 'Maat A', 'value' => $a . ' mm', 'raw_value' => (float) $a, 'type' => 'dimension' ),
            'fb'     => array( 'label' => 'Maat B', 'value' => $b . ' mm', 'raw_value' => (float) $b, 'type' => 'dimension' ),
            'fc'     => array( 'label' => 'Maat C', 'value' => $c . ' mm', 'raw_value' => (float) $c, 'type' => 'dimension' ),
            'fcolor' => array( 'label' => 'Kleur', 'value' => $color, 'raw_value' => $color, 'type' => 'color' ),
            'fqty'   => array( 'label' => 'Aantal', 'value' => (string) $qty, 'raw_value' => $qty, 'type' => 'quantity' ),
        );
        return array(
            'data'               => new Fake_Product( 'Paalmuts' ),
            'product_id'         => $product_id,
            'quantity'           => $qty,
            'bossier_calculator' => array(
                'calculator_id'     => 7,
                'product_id'        => $product_id,
                'selections'        => $selections,
                'display_data'      => $display,
                'product_fee'       => $fee,
                'product_fee_label' => 'Eenmalige malkosten',
            ),
        );
    }

    function run_fees( array $items ) {
        $cart        = new Fake_Cart();
        $cart->items = $items;
        ( new \Bossier\Calculator\Frontend\Cart() )->add_product_fees( $cart );
        return $cart->fees;
    }

    // 1. Identical dimensions, different quantities: charged once.
    $fees = run_fees( array(
        'k1' => make_item( 10, 100, 100, 100, 1 ),
        'k2' => make_item( 10, 100, 100, 100, 5 ),
    ) );
    check( 'Same dimensions (different quantity) are charged once', 1 === count( $fees ), 'fees: ' . count( $fees ) );

    // 2. One dimension differs by 1 mm: charged twice.
    $fees = run_fees( array(
        'k1' => make_item( 10, 100, 100, 100, 1 ),
        'k2' => make_item( 10, 101, 100, 100, 1 ),
    ) );
    check( '100x100x100 and 101x100x100 are charged twice', 2 === count( $fees ), 'fees: ' . count( $fees ) );

    // 3. Same dimensions, different product: charged twice.
    $fees = run_fees( array(
        'k1' => make_item( 10, 100, 100, 100, 1 ),
        'k2' => make_item( 11, 100, 100, 100, 1 ),
    ) );
    check( 'Same dimensions on a different product are charged twice', 2 === count( $fees ), 'fees: ' . count( $fees ) );

    // 4. Same dimensions, different option (color): distinct configuration, still charged twice.
    $fees = run_fees( array(
        'k1' => make_item( 10, 100, 100, 100, 1, 'Grijs' ),
        'k2' => make_item( 10, 100, 100, 100, 1, 'Antraciet' ),
    ) );
    check( 'Same dimensions with a different option are charged twice', 2 === count( $fees ), 'fees: ' . count( $fees ) );

    // 5. Three items: two identical + one different = two charges.
    $fees = run_fees( array(
        'k1' => make_item( 10, 100, 100, 100, 2 ),
        'k2' => make_item( 10, 100, 100, 100, 3 ),
        'k3' => make_item( 10, 101, 100, 100, 1 ),
    ) );
    check( 'Two identical + one different item = two charges', 2 === count( $fees ), 'fees: ' . count( $fees ) );

    // 6. Total charged amount matches the number of unique configurations.
    $total = array_sum( array_column( $fees, 'amount' ) );
    check( 'Total fee amount is 2 x 150', 300.0 === (float) $total, 'total: ' . $total );

    // 7. Calculator without the fee enabled is never charged.
    $fees = run_fees( array(
        'k1' => make_item( 10, 100, 100, 100, 1, 'Grijs', 0.0 ),
        'k2' => make_item( 10, 101, 100, 100, 1, 'Grijs', 0.0 ),
    ) );
    check( 'Calculators with the fee disabled are not charged', 0 === count( $fees ), 'fees: ' . count( $fees ) );

    // 8. Mixed cart: only the items with the fee enabled are charged.
    $fees = run_fees( array(
        'k1' => make_item( 10, 100, 100, 100, 1, 'Grijs', 150.0 ),
        'k2' => make_item( 20, 100, 100, 100, 1, 'Grijs', 0.0 ),
    ) );
    check( 'Mixed cart only charges the calculator with the fee enabled', 1 === count( $fees ), 'fees: ' . count( $fees ) );

    // 9. Fee names shown to the customer distinguish the configurations.
    $fees  = run_fees( array(
        'k1' => make_item( 10, 100, 100, 100, 1 ),
        'k2' => make_item( 10, 101, 100, 100, 1 ),
    ) );
    $names = array_column( $fees, 'name' );
    check(
        'Fee names include the dimensions',
        false !== strpos( $names[0], '100' ) && false !== strpos( $names[1], '101' ),
        implode( ' | ', $names )
    );

    echo "\n$pass passed, $fail failed.\n";
    exit( $fail > 0 ? 1 : 0 );
}
