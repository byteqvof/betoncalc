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
        /** @var array<int,array> Registry of fields per calculator id for tests. */
        public static $registry = array();
        private $id;
        public function __construct( $id = 0 ) { $this->id = $id; }
        public function is_valid() { return isset( self::$registry[ $this->id ] ); }
        public function get_settings() { return array(); }
        public function get_enabled_fields() { return self::$registry[ $this->id ] ?? array(); }
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

    // ---------------------------------------------------------------------
    // Hardening: products without dimensions / unusual data.
    // ---------------------------------------------------------------------

    \Bossier\Calculator\Calculator::$registry[7] = array(
        'fcolor' => array( 'type' => 'color' ),
        'fqty'   => array( 'type' => 'quantity' ),
    );

    /**
     * Build a calculator item without any dimension fields.
     */
    function make_item_no_dims( $product_id, $color, $qty, $name = 'Paalmuts' ) {
        return array(
            'data'               => new Fake_Product( $name ),
            'product_id'         => $product_id,
            'quantity'           => $qty,
            'bossier_calculator' => array(
                'calculator_id'     => 7,
                'product_id'        => $product_id,
                'selections'        => array( 'fcolor' => $color, 'fqty' => $qty ),
                'display_data'      => array(
                    'fcolor' => array( 'label' => 'Kleur', 'value' => $color, 'raw_value' => $color, 'type' => 'color' ),
                    'fqty'   => array( 'label' => 'Aantal', 'value' => (string) $qty, 'raw_value' => $qty, 'type' => 'quantity' ),
                ),
                'product_fee'       => 150.0,
                'product_fee_label' => 'Eenmalige malkosten',
            ),
        );
    }

    // 10. No dimensions, same option: charged once.
    $fees = run_fees( array(
        'k1' => make_item_no_dims( 10, 'Grijs', 1 ),
        'k2' => make_item_no_dims( 10, 'Grijs', 4 ),
    ) );
    check( 'No dimensions, same option, different quantity: charged once', 1 === count( $fees ), 'fees: ' . count( $fees ) );

    // 11. No dimensions, different option: charged twice with distinct names.
    $fees = run_fees( array(
        'k1' => make_item_no_dims( 10, 'Grijs', 1 ),
        'k2' => make_item_no_dims( 10, 'Antraciet', 1 ),
    ) );
    check( 'No dimensions, different option: charged twice', 2 === count( $fees ), 'fees: ' . count( $fees ) );
    $names = array_column( $fees, 'name' );
    check(
        'No-dimension fee names show the option instead of an empty suffix',
        false === strpos( implode( '|', $names ), '()' ) && false !== strpos( implode( '|', $names ), 'Antraciet' ),
        implode( ' | ', $names )
    );

    // 12. No dimensions and no options at all: same product charged once.
    $bare = function ( $product_id, $qty, $name = 'Paalmuts' ) {
        return array(
            'data'               => new Fake_Product( $name ),
            'product_id'         => $product_id,
            'quantity'           => $qty,
            'bossier_calculator' => array(
                'calculator_id'     => 7,
                'product_id'        => $product_id,
                'selections'        => array(),
                'display_data'      => array(),
                'product_fee'       => 150.0,
                'product_fee_label' => 'Eenmalige malkosten',
            ),
        );
    };
    $fees = run_fees( array( 'k1' => $bare( 10, 1 ), 'k2' => $bare( 10, 3 ) ) );
    check( 'No dimensions and no options: same product charged once', 1 === count( $fees ), 'fees: ' . count( $fees ) );

    // 13. Two different products that share a name must both be charged (no ID collision).
    $fees = run_fees( array( 'k1' => $bare( 10, 1, 'Paalmuts' ), 'k2' => $bare( 11, 1, 'Paalmuts' ) ) );
    check( 'Different products with identical names are both charged', 2 === count( $fees ), 'fees: ' . count( $fees ) );

    // 14. Empty product name does not break or produce a dangling separator.
    $fees  = run_fees( array( 'k1' => $bare( 10, 1, '' ) ) );
    $names = array_column( $fees, 'name' );
    check(
        'Empty product name gives a clean fee name',
        1 === count( $fees ) && 'Eenmalige malkosten' === $names[0],
        implode( ' | ', $names )
    );

    // 15. Missing display_data and selections keys (old cart items): no warnings, one fee.
    $legacy = function ( $qty ) {
        return array(
            'data'               => new Fake_Product( 'Paalmuts' ),
            'product_id'         => 10,
            'quantity'           => $qty,
            'bossier_calculator' => array(
                'calculator_id'     => 7,
                'product_fee'       => 150.0,
                'product_fee_label' => 'Eenmalige malkosten',
            ),
        );
    };
    $fees = run_fees( array( 'k1' => $legacy( 1 ), 'k2' => $legacy( 2 ) ) );
    check( 'Legacy cart items without display data are charged once', 1 === count( $fees ), 'fees: ' . count( $fees ) );

    // 16. display_data missing, but selections include a quantity: quantity must not split the fee.
    $no_display = function ( $qty ) {
        return array(
            'data'               => new Fake_Product( 'Paalmuts' ),
            'product_id'         => 10,
            'quantity'           => $qty,
            'bossier_calculator' => array(
                'calculator_id'     => 7,
                'product_id'        => 10,
                'selections'        => array( 'fcolor' => 'Grijs', 'fqty' => $qty ),
                'product_fee'       => 150.0,
                'product_fee_label' => 'Eenmalige malkosten',
            ),
        );
    };
    $fees = run_fees( array( 'k1' => $no_display( 1 ), 'k2' => $no_display( 6 ) ) );
    check( 'Quantity in selections does not split the fee when display data is missing', 1 === count( $fees ), 'fees: ' . count( $fees ) );

    // 17. Stale value of a hidden (show_when) field must not split identical visible configurations.
    $with_hidden = function ( $hidden_value ) {
        $item = make_item_no_dims( 10, 'Grijs', 1 );
        $item['bossier_calculator']['selections']['fhidden'] = $hidden_value; // Not in display_data.
        return $item;
    };
    $fees = run_fees( array( 'k1' => $with_hidden( 'a' ), 'k2' => $with_hidden( 'b' ) ) );
    check( 'Hidden-field leftovers do not create an extra fee', 1 === count( $fees ), 'fees: ' . count( $fees ) );

    // 18. Non-scalar / malformed display values must not crash.
    $odd = make_item_no_dims( 10, 'Grijs', 1 );
    $odd['bossier_calculator']['display_data']['fodd']  = array( 'label' => 'Odd', 'value' => array( 'x' ), 'raw_value' => array( 'x' ), 'type' => 'custom' );
    $odd['bossier_calculator']['display_data']['fbad']  = 'not-an-array';
    $odd['bossier_calculator']['display_data']['fhtml'] = array( 'label' => 'Html', 'value' => '<b>Vet</b>', 'raw_value' => 'Vet', 'type' => 'text' );
    $fees  = run_fees( array( 'k1' => $odd ) );
    $names = array_column( $fees, 'name' );
    check( 'Malformed display data does not crash and strips markup from the name', 1 === count( $fees ) && false === strpos( $names[0], '<' ), implode( ' | ', $names ) );

    // 19. Cart item with a missing product object does not crash.
    $nodata         = make_item_no_dims( 10, 'Grijs', 1 );
    $nodata['data'] = null;
    $fees           = run_fees( array( 'k1' => $nodata ) );
    check( 'Missing product object does not crash', 1 === count( $fees ), 'fees: ' . count( $fees ) );

    // 20. Non-calculator items in the cart are ignored.
    $fees = run_fees( array( 'k1' => array( 'data' => new Fake_Product( 'Plain' ), 'quantity' => 1 ) ) );
    check( 'Non-calculator cart items are ignored', 0 === count( $fees ), 'fees: ' . count( $fees ) );

    echo "\n$pass passed, $fail failed.\n";
    exit( $fail > 0 ? 1 : 0 );
}
