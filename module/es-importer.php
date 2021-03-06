<?php
/**
 * Import Data to Elasticsearch Class
 *
 * @package Elasticommerce-relateditem
 * @author hideokamoto
 * @since 0.1.0
 **/
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}
use Elastica\Client;
use Elastica\Type\Mapping;
use Elastica\Bulk;

/**
 * Data Import Class that using Elasticsearch API
 *
 * @class ESCR_Importer
 * @since 0.1.0
 */
class ESCR_Importer extends ESCR_Base {
	/**
	 * Instance Class
	 * @access private
	 */
	private static $instance;

	/**
	 * text domain
	 * @access private
	 */
	private static $text_domain;

	/**
	 * Constructer
	 * Set text domain on class
	 *
	 * @since 0.1.0
	 */
	private function __construct() {
		self::$text_domain = ESCR_Base::text_domain();
	}

	/**
	 * Get Instance Class
	 *
	 * @return ESCR_Importer
	 * @since 0.1.0
	 */
	public static function get_instance() {
		if ( ! isset( self::$instance ) ) {
			$c = __CLASS__;
			self::$instance = new $c();
		}
		return self::$instance;
	}

	/**
	 * Import All Product Data
	 *
	 * @since 0.1.0
	 * @return bool / WP_Error
	 */
	public function import_all_product() {
		$type = $this->get_index_type();
		$query = apply_filters( 'escr-default-query', array(
			'post_type' => $type,
			'posts_per_page' => -1,
		) );
		$the_query = new WP_Query( $query );
		while ( $the_query->have_posts() ) : $the_query->the_post();
			$ID = get_the_ID();
			$data[ $ID ] = $this->get_product_data( $ID );
		endwhile;
		return $this->import_products( $data );
	}

	/**
	 * get Elasticsearch Mapping
	 *
	 * @return array
	 * @since 0.1.0
	 */
	private function _get_mapping() {
		$mapping = array(
			'product_title' => array(
				'type' => 'string',
				'analyzer' => 'kuromoji',
			),
			'product_content' => array(
				'type' => 'string',
				'analyzer' => 'kuromoji',
			),
			'product_excerpt' => array(
				'type' => 'string',
				'analyzer' => 'kuromoji',
			),
			'product_tag' => array(
				'type' => 'string',
				'analyzer' => 'kuromoji',
			),
			'product_cat' => array(
				'type' => 'string',
				'analyzer' => 'kuromoji',
			),
			'product_display_price' => array(
				'type' => 'string',
			),
		);
		return apply_filters( 'escr_mapping', $mapping );
	}

	/**
	 * send Product Data to Elasticsearch Endpoint
	 *
	 * @param $dataList array
	 * @return bool / WP_Error
	 * @since 0.1.0
	 * @throws WP_Error
	 */
	private function import_products( $dataList ) {
		try {
			$options = $this->get_elasticsearch_endpoint();
			$client = $this->create_client( $options );
			if ( ! $client ) {
				throw new Exception( 'Couldn\'t make Elasticsearch Client. Parameter is not enough.' );
			}

			$url = parse_url(home_url());
			if ( ! $url ) {
				throw new Exception( 'home_url() is disabled.' );
			}
			$index = $client->getIndex( $url['host'] );
			$index->create( array(), true );
			$type = $index->getType( $this->get_index_type() );
			$type->setMapping( $this->_get_mapping() );

			foreach ( $dataList as $ID => $data ) {
				$docs[] = $type->createDocument( (int) $ID , $data );
			}
			$bulk = new Bulk( $client );
			$bulk->setType( $type );
			$bulk->addDocuments( $docs );
			$res = $bulk->send();
			if ( ! $res->isOk()) {
				throw new Exception( $res->getError() );
			}
			return true;
		} catch ( Exception $e ) {
			$err = new WP_Error( 'Elasticsearch Import Error', $e->getMessage() );
			return $err;
		}
	}

	/**
	 * Check Product that can add search target
	 *
	 * @param $Product WC_Product
	 * @return bool
	 * @since 0.1.0
	 */
	private function is_search_target( $Product ) {
		if ( $Product->is_visible() ) {
			return true;
		}
		return false;
	}

	/**
	 * Get term name list
	 *
	 * @param $terms array
	 * @return array
	 * @since 0.1.0
	 */
	private function get_term_name_list( $terms ) {
		if ( ! $terms || is_wp_error( $terms ) ) {
			return;
		}
		foreach ( $terms as $key => $value ) {
			$term_name_list[] = $value->name;
		}
		return $term_name_list;
	}

	/**
	 * get product data from WC_Product
	 *
	 * @param $ID int
	 * @return array
	 * @since 0.1.0
	 */
	private function get_product_data( $ID ) {
		$Product = wc_get_product( $ID );
		$data = '';
		if ( $this->is_search_target( $Product ) ) {
			$data['product_title'] = $Product->post->post_title;
			$data['product_content'] = wp_strip_all_tags( $Product->post->post_content, true );
			$data['product_excerpt'] = wp_strip_all_tags( $Product->post->post_excerpt, true );
			$data['product_display_price'] = $Product->get_display_price();
			$data['product_rate'] = $Product->get_average_rating();
			$data['product_tag'] = $this->get_term_name_list( get_the_terms($ID, 'product_tag') );
			$data['product_cat'] = $this->get_term_name_list( get_the_terms($ID, 'product_cat') );
			//@TODO support Variation Item
		}
		return apply_filters( 'escr_create_data', $data );
	}

	/**
	 * Conveert Array to JSON
	 *
	 * @param $data array
	 * @return json
	 * @since 0.1.0
	 */
	private function convert_json( $data ) {
		$json = json_encode( $data , JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
		return $json;
	}
}
