<?php
/**
 * Reports Customers REST API Test
 *
 * @package WooCommerce\Tests\API
 * @since 3.5.0
 */

namespace WooCommerce\RestApi\UnitTests\Tests\Version4\Reports;

defined( 'ABSPATH' ) || exit;

use \WooCommerce\RestApi\UnitTests\AbstractReportsTest;

/**
 * Reports Customers REST API Test Class
 *
 * @package WooCommerce\Tests\API
 * @since 3.5.0
 */
class Customers extends AbstractReportsTest {
	/**
	 * Endpoint.
	 *
	 * @var string
	 */
	protected $endpoint = '/wc/v4/reports/customers';

	/**
	 * Test route registration.
	 *
	 * @since 3.5.0
	 */
	public function test_register_routes() {
		$routes = $this->server->get_routes();

		$this->assertArrayHasKey( $this->endpoint, $routes );
	}

	/**
	 * Asserts the report item schema is correct.
	 *
	 * @param array $schema Item to check schema.
	 */
	public function assert_report_item_schema( $schema ) {
		$this->assertArrayHasKey( 'id', $schema );
		$this->assertArrayHasKey( 'user_id', $schema );
		$this->assertArrayHasKey( 'name', $schema );
		$this->assertArrayHasKey( 'username', $schema );
		$this->assertArrayHasKey( 'country', $schema );
		$this->assertArrayHasKey( 'city', $schema );
		$this->assertArrayHasKey( 'postcode', $schema );
		$this->assertArrayHasKey( 'date_registered', $schema );
		$this->assertArrayHasKey( 'date_registered_gmt', $schema );
		$this->assertArrayHasKey( 'date_last_active', $schema );
		$this->assertArrayHasKey( 'date_last_active_gmt', $schema );
		$this->assertArrayHasKey( 'orders_count', $schema );
		$this->assertArrayHasKey( 'total_spend', $schema );
		$this->assertArrayHasKey( 'avg_order_value', $schema );
	}

	/**
	 * Test reports schema.
	 *
	 * @since 3.5.0
	 */
	public function test_reports_schema() {
		wp_set_current_user( $this->user );

		$request    = new WP_REST_Request( 'OPTIONS', $this->endpoint );
		$response   = $this->server->dispatch( $request );
		$data       = $response->get_data();
		$properties = $data['schema']['properties'];

		$this->assertCount( 14, $properties );
		$this->assert_report_item_schema( $properties );
	}

	/**
	 * Test getting reports without valid permissions.
	 *
	 * @since 3.5.0
	 */
	public function test_get_reports_without_permission() {
		wp_set_current_user( 0 );
		$response = $this->server->dispatch( new WP_REST_Request( 'GET', $this->endpoint ) );
		$this->assertEquals( 401, $response->get_status() );
	}

	/**
	 * Test calling update_registered_customer() with a bad user id.
	 *
	 * @since 3.5.0
	 */
	public function test_update_registered_customer_with_bad_user_id() {
		$result = \WC_Admin_Reports_Customers_Data_Store::update_registered_customer( 2 );
		$this->assertFalse( $result );
	}

	/**
	 * Test creation of various user roles
	 *
	 * @since 3.5.0
	 */
	public function test_user_creation() {
		wp_set_current_user( $this->user );
		$admin_id = wp_insert_user(
			array(
				'user_login' => 'testadmin',
				'user_pass'  => null,
				'role'       => 'administrator',
			)
		);

		// Admin user without orders should not be shown.
		$request = new WP_REST_Request( 'GET', $this->endpoint );
		$request->set_query_params( array( 'per_page' => 10 ) );
		$response = $this->server->dispatch( $request );
		$reports  = $response->get_data();
		$headers  = $response->get_headers();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertCount( 0, $reports );

		// Creating an order with admin should return the admin.
		$product = new \WC_Product_Simple();
		$product->set_name( 'Test Product' );
		$product->set_regular_price( 25 );
		$product->save();

		$order = OrderHelper::create_order( $admin_id, $product );
		$order->set_status( 'processing' );
		$order->set_total( 100 );
		$order->save();

		QueueHelper::run_all_pending();

		$request = new WP_REST_Request( 'GET', $this->endpoint );
		$request->set_query_params( array( 'per_page' => 10 ) );
		$response = $this->server->dispatch( $request );
		$reports  = $response->get_data();
		$headers  = $response->get_headers();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertCount( 1, $reports );
		$this->assertEquals( $admin_id, $reports[0]['user_id'] );

		// Creating a customer should show up regardless of orders.
		$customer = CustomerHelper::create_customer( 'customer', 'password', 'customer@example.com' );

		$request = new WP_REST_Request( 'GET', $this->endpoint );
		$request->set_query_params(
			array(
				'per_page' => 10,
				'order'    => 'asc',
				'orderby'  => 'username',
			)
		);
		$response = $this->server->dispatch( $request );
		$reports  = $response->get_data();
		$headers  = $response->get_headers();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertCount( 2, $reports );
		$this->assertEquals( $customer->get_id(), $reports[0]['user_id'] );
		$this->assertEquals( $admin_id, $reports[1]['user_id'] );
	}

	/**
	 * Test getting reports.
	 *
	 * @since 3.5.0
	 */
	public function test_get_reports() {
		global $wpdb;

		$test_customers = array();

		$customer_names = array( 'Alice', 'Betty', 'Catherine', 'Dan', 'Eric', 'Fred', 'Greg', 'Henry', 'Ivan', 'Justin' );

		// Create 10 test customers.
		for ( $i = 1; $i <= 10; $i++ ) {
			$name     = $customer_names[ $i - 1 ];
			$email    = 'customer+' . strtolower( $name ) . '@example.com';
			$customer = CustomerHelper::create_customer( "customer{$i}", 'password', $email );
			$customer->set_first_name( $name );
			$customer->save();
			$test_customers[] = $customer;
		}

		// Create a test product for use in an order.
		$product = new \WC_Product_Simple();
		$product->set_name( 'Test Product' );
		$product->set_regular_price( 25 );
		$product->save();

		// Place an order for the first test customer.
		$order = OrderHelper::create_order( $test_customers[0]->get_id(), $product );
		$order->set_status( 'processing' );
		$order->set_total( 100 );
		$order->save();

		QueueHelper::run_all_pending();

		$request = new WP_REST_Request( 'GET', $this->endpoint );
		$request->set_query_params(
			array(
				'per_page' => 5,
				'order'    => 'asc',
				'orderby'  => 'username',
			)
		);

		$response = $this->server->dispatch( $request );
		$reports  = $response->get_data();
		$headers  = $response->get_headers();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertCount( 5, $reports );
		$this->assertArrayHasKey( 'X-WP-Total', $headers );
		$this->assertEquals( 10, $headers['X-WP-Total'] );
		$this->assertArrayHasKey( 'X-WP-TotalPages', $headers );
		$this->assertEquals( 2, $headers['X-WP-TotalPages'] );
		$this->assertEquals( $test_customers[0]->get_id(), $reports[0]['user_id'] );
		$this->assertEquals( 1, $reports[0]['orders_count'] );
		$this->assertEquals( 100, $reports[0]['total_spend'] );
		$this->assert_report_item_schema( $reports[0] );

		// Test name parameter (case with no matches).
		$request->set_query_params(
			array(
				'search' => 'Nota Customername',
			)
		);
		$response = $this->server->dispatch( $request );
		$reports  = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertCount( 0, $reports );

		// Test name parameter (partial match).
		$request->set_query_params(
			array(
				'search' => 're',
			)
		);
		$response = $this->server->dispatch( $request );
		$reports  = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertCount( 2, $reports );

		// Test email search.
		$request->set_query_params(
			array(
				'search'   => 'customer+justin',
				'searchby' => 'email',
			)
		);
		$response = $this->server->dispatch( $request );
		$reports  = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertCount( 1, $reports );

		// Test username search.
		$request->set_query_params(
			array(
				'search'   => 'customer1',
				'searchby' => 'username',
			)
		);
		$response = $this->server->dispatch( $request );
		$reports  = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		// customer1 and customer10.
		$this->assertCount( 2, $reports );

		// Test name and last_order parameters.
		$request->set_query_params(
			array(
				'search'           => 'Alice',
				'last_order_after' => date( 'Y-m-d' ) . 'T00:00:00Z',
			)
		);
		$response = $this->server->dispatch( $request );
		$reports  = $response->get_data();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertCount( 1, $reports );

		$this->assertEquals( $test_customers[0]->get_id(), $reports[0]['user_id'] );
		$this->assertEquals( 1, $reports[0]['orders_count'] );
		$this->assertEquals( 100, $reports[0]['total_spend'] );

		$customer_id = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT customer_id FROM {$wpdb->prefix}wc_customer_lookup WHERE user_id = %d",
				$reports[0]['user_id']
			)
		);

		// Test customers param.
		$request->set_query_params(
			array(
				'customers' => $customer_id,
			)
		);

		$response = $this->server->dispatch( $request );
		$reports  = $response->get_data();
		$this->assertEquals( 200, $response->get_status() );
		$this->assertCount( 1, $reports );
		$this->assertEquals( $test_customers[0]->get_id(), $reports[0]['user_id'] );
	}

	/**
	 * Test customer first and last name.
	 */
	public function test_customer_name() {
		$customer = wp_insert_user(
			array(
				'user_login' => 'daenerys',
				'user_pass'  => null,
				'role'       => 'customer',
			)
		);

		// Test shipping name and empty billing name.
		$order = OrderHelper::create_order( $customer );
		$order->set_billing_first_name( '' );
		$order->set_billing_last_name( '' );
		$order->set_shipping_first_name( 'Daenerys' );
		$order->set_shipping_last_name( 'Targaryen' );
		$order->set_status( 'completed' );
		$order->set_total( 100 );
		$order->save();

		QueueHelper::run_all_pending();

		$request  = new WP_REST_Request( 'GET', $this->endpoint );
		$response = $this->server->dispatch( $request );
		$reports  = $response->get_data();
		$headers  = $response->get_headers();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertCount( 1, $reports );
		$this->assertEquals( 'Daenerys Targaryen', $reports[0]['name'] );

		// Test billing name.
		$order->set_billing_first_name( 'Jon' );
		$order->set_billing_last_name( 'Snow' );
		$order->save();
		do_action( 'woocommerce_update_customer', $customer );

		QueueHelper::run_all_pending();

		$request = new WP_REST_Request( 'GET', $this->endpoint );
		$request->set_query_params( array( 'orderby' => 'username' ) ); // Cache busting.
		$response = $this->server->dispatch( $request );
		$reports  = $response->get_data();
		$headers  = $response->get_headers();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertCount( 1, $reports );
		$this->assertEquals( 'Jon Snow', $reports[0]['name'] );

		// Test user profile name.
		wp_update_user(
			array(
				'ID'         => $customer,
				'first_name' => 'Tyrion',
				'last_name'  => 'Lanister',
			)
		);
		do_action( 'woocommerce_update_customer', $customer );

		QueueHelper::run_all_pending();

		$request = new WP_REST_Request( 'GET', $this->endpoint );
		$request->set_query_params( array( 'orderby' => 'name' ) ); // Cache busting.
		$response = $this->server->dispatch( $request );
		$reports  = $response->get_data();
		$headers  = $response->get_headers();

		$this->assertEquals( 200, $response->get_status() );
		$this->assertCount( 1, $reports );
		$this->assertEquals( 'Tyrion Lanister', $reports[0]['name'] );
	}
}
