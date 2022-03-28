<?php
/**
 * Block class.
 *
 * @package SiteCounts
 */

namespace XWP\SiteCounts;

use WP_Block;
use WP_Query;
use WP_Post;

/**
 * The Site Counts dynamic block.
 *
 * Registers and renders the dynamic block.
 */
class Block {

	/**
	 * The Plugin instance.
	 *
	 * @var Plugin
	 */
	protected $plugin;

	/**
	 * Holds the meta key for pre-rendered block.
	 *
	 * @var string
	 */
	const META_KEY = 'xwp-site-counts';

	/**
	 * Holds the type of post types are listed.
	 *
	 * @var array
	 */
	public $post_types = array();

	/**
	 * Instantiates the class.
	 *
	 * @param Plugin $plugin The plugin object.
	 */
	public function __construct( $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Adds the action to register the block.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'init', array( $this, 'register_block' ) );

		// Keep in memory to make accessing them easier.
		$this->post_types = get_post_types( array( 'public' => true ), 'objects' );

		// Add cache flush actions.
		add_action( 'transition_post_status', array( $this, 'flush_caches' ), 10, 3 );
		add_action( 'after_delete_post', array( $this, 'delete_post_flush' ), 10, 2 );
	}

	/**
	 * Flush caches when a new post is created or trashed.
	 *
	 * @param string  $new_status New status.
	 * @param string  $old_status Ignored: Old status.
	 * @param WP_Post $post       The post object.
	 *
	 * @return void
	 */
	public function flush_caches( $new_status, $old_status, $post ) {

		$states = array(
			'inherit',
			'publish',
			'trash',
		);
		if ( in_array( $post->post_type, array_keys( $this->post_types ), true ) && in_array( $new_status, $states, true ) ) {
			delete_post_meta_by_key( self::META_KEY );
		}
	}

	/** Flush cache on a forced delete (bypassing the trash).
	 *
	 * @param int     $post_id Ignored: Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public function delete_post_flush( $post_id, $post ) {
		if ( 'trash' !== $post->post_status ) {
			$this->flush_caches( 'trash', null, $post );
		}
	}

	/**
	 * Registers the block.
	 */
	public function register_block() {
		register_block_type_from_metadata(
			$this->plugin->dir(),
			array(
				'render_callback' => array( $this, 'render_callback' ),
			)
		);
	}

	/**
	 * Renders the block.
	 *
	 * @param array $attributes The attributes for the block.
	 *
	 * @return string The markup of the block.
	 */
	public function render_callback( $attributes ) {
		$current_post = get_the_ID();
		$has_html     = get_post_meta( $current_post, self::META_KEY, true );
		if ( ! empty( $has_html ) ) {
			return $has_html;
		}
		$class_name = 'site-counts-container';
		if ( isset( $attributes['className'] ) ) {
			$class_name .= ' ' . $attributes['className'];
		}
		$current_post = get_the_ID();
		$html         = array();

		// Open container.
		$html[] = '<div class="' . esc_attr( $class_name ) . '">';

		// Get the counts.
		$html[] = $this->get_count_posts_html();

		// translators: placeholder is the current post ID.
		$html[] = '<p>' . sprintf( __( 'The current post ID is %s', 'site-counts' ), $current_post ) . '</p>';

		// Get the 5 latest posts.
		$html[] = $this->get_latest_posts_html( $current_post );

		// Close container.
		$html[] = '</div>';

		$html_string = wp_kses_post( implode( $html ) );

		update_post_meta( $current_post, self::META_KEY, $html_string );

		return $html_string;
	}

	/**
	 * Get the Post type counts.
	 *
	 * @return string
	 */
	protected function get_count_posts_html() {

		$html   = array();
		$html[] = '<h2>' . esc_html__( 'Post Counts', 'site-counts' ) . '</h2>';
		$html[] = '<ul>';

		foreach ( $this->post_types as $post_type_slug => $post_type_object ) {
			$counts       = wp_count_posts( $post_type_slug );
			$post_count   = 'attachment' === $post_type_slug ? (int) $counts->inherit : (int) $counts->publish;
			$single_label = $post_type_object->labels->singular_name;
			$plural_label = $post_type_object->labels->name;
			$note         = sprintf(
				// translators: Post count difference. %1$d is number of posts, %2$s & %3$s is the post type singular and plural names.
				_n( 'There is %1$d %2$s', 'There are %1$d %3$s', $post_count, 'site-counts' ), // phpcs:ignore WordPress.WP.I18n.MismatchedPlaceholders
				$post_count,
				$single_label,
				$plural_label
			);

			// Add to the HTML.
			$html[] = '<li>' . $note . '</li>';
		}

		$html[] = '</ul>';

		return implode( $html );
	}

	/**
	 * Get 5 of the latest posts.
	 *
	 * @param int $exclude The ID to exclude.
	 *
	 * @return string
	 */
	protected function get_latest_posts_html( $exclude ) {

		$html  = array();
		$args  = array(
			'post_type'              => array( 'post', 'page' ),
			'posts_per_page'         => 10,
			'post_status'            => 'publish',
			'ignore_sticky_posts'    => true,
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'tag'                    => 'foo',
			'category_name'          => 'baz',
			'date_query'             => array(
				array(
					'hour'    => 9,
					'compare' => '>=',
				),
				array(
					'hour'    => 17,
					'compare' => '<=',
				),
			),
		);
		$query = new WP_Query( $args );
		if ( $query->have_posts() ) {
			$run    = 0;
			$html[] = '<h2>' . esc_html__( '5 posts with the tag of foo and the category of baz', 'site-counts' ) . '</h2>';
			$html[] = '<ul>';

			while ( $query->have_posts() && $run < 5 ) {
				$query->the_post();
				if ( get_the_ID() !== $exclude ) {
					$html[] = '<li>' . get_the_title() . '</li>';
					$run ++;
				}
			}
			wp_reset_postdata();
			$html[] = '</ul>';
		}

		return implode( $html );
	}
}
