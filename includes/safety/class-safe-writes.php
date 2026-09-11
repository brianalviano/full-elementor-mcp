<?php
/**
 * Safe Writes Layer for Full Elementor MCP.
 *
 * Provides targeted, plugin-owned wrappers around WordPress core and Elementor
 * persistence sinks. Strictly asserts active mutation context, write permission,
 * and fencing token ownership immediately before every physical write.
 *
 * @package Full_Elementor_MCP
 * @since   1.8.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Authoritative plugin-owned write-sink wrapper.
 */
class Full_Elementor_MCP_Safe_Writes {

	/**
	 * Safely inserts a WordPress post/page/template under an active CREATE mutation context.
	 *
	 * Lifecycle:
	 * 1. Verifies active CREATE write context.
	 * 2. Asserts fencing immediately before wp_insert_post.
	 * 3. Executes wp_insert_post.
	 * 4. Immediately and durably journals created_object_id in WAL.
	 * 5. Binds created_object_id to active Mutation Context.
	 * 6. Marks write started and increments write count.
	 *
	 * @param array<string, mixed> $postarr          Post array to insert.
	 * @param bool                 $fire_after_hooks Whether to fire post-insert hooks.
	 * @return int|\WP_Error Inserted post ID or WP_Error on failure.
	 */
	public static function insert_post( array $postarr, bool $fire_after_hooks = true ): int|\WP_Error {
		if ( ! class_exists( 'Full_Elementor_MCP_Mutation_Context' ) ) {
			return new \WP_Error( 'mutation_context_missing', __( 'Mutation context unavailable for safe insert.', 'full-elementor-mcp' ) );
		}

		// 1. Assert active CREATE context:
		$create_guard = Full_Elementor_MCP_Mutation_Context::assert_create_write_context();
		if ( is_wp_error( $create_guard ) ) {
			return $create_guard;
		}

		$ctx = Full_Elementor_MCP_Mutation_Context::current();
		if ( ! $ctx ) {
			return new \WP_Error( 'mutation_context_missing', __( 'Active mutation context missing.', 'full-elementor-mcp' ) );
		}

		// 2. Fencing assertion immediately before physical write:
		if ( ! class_exists( 'Full_Elementor_MCP_Lock_Manager' ) ) {
			return new \WP_Error( 'write_fencing_unavailable', __( 'Lock Manager unavailable for write fencing.', 'full-elementor-mcp' ) );
		}

		$fence_check = Full_Elementor_MCP_Lock_Manager::assert_fencing_token_ownership(
			(string) $ctx['resource_key'],
			(string) $ctx['owner_id'],
			(int) $ctx['fencing_token']
		);
		if ( is_wp_error( $fence_check ) ) {
			return $fence_check;
		}

		// 3. Execute core insert:
		$post_id = wp_insert_post( $postarr, true, $fire_after_hooks );
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return new \WP_Error( 'post_insert_failed', __( 'wp_insert_post returned invalid post ID.', 'full-elementor-mcp' ) );
		}

		// 4. Mark write started:
		Full_Elementor_MCP_Mutation_Context::mark_write_started();
		Full_Elementor_MCP_Mutation_Context::increment_write_count();

		// 5. Durably journal created_object_id immediately:
		$journal_id    = (int) ( $ctx['journal_id'] ?? 0 );
		$fencing_token = (int) ( $ctx['fencing_token'] ?? 0 );

		if ( $journal_id > 0 && class_exists( 'Full_Elementor_MCP_Journal' ) ) {
			$journal_bind = Full_Elementor_MCP_Journal::record_created_object_id( $journal_id, $post_id, $fencing_token );
			if ( is_wp_error( $journal_bind ) ) {
				return $journal_bind;
			}
		}

		// 6. Bind created object ID onto context for subsequent writes:
		Full_Elementor_MCP_Mutation_Context::bind_created_object_id( $post_id );

		return $post_id;
	}

	/**
	 * Safely updates a WordPress post/page/template.
	 *
	 * @param array<string, mixed>|object $postarr  Post array or object with ID.
	 * @param bool                        $wp_error Whether to return WP_Error on failure.
	 * @return int|\WP_Error Updated post ID or WP_Error.
	 */
	public static function update_post( array|object $postarr, bool $wp_error = true ): int|\WP_Error {
		$arr     = (array) $postarr;
		$post_id = absint( $arr['ID'] ?? 0 );
		if ( $post_id <= 0 ) {
			return new \WP_Error( 'invalid_post_id', __( 'Invalid post ID for safe update.', 'full-elementor-mcp' ) );
		}

		$guard = self::assert_write_boundary( "post:{$post_id}" );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$res = wp_update_post( $postarr, $wp_error );
		if ( ! is_wp_error( $res ) && $res > 0 ) {
			Full_Elementor_MCP_Mutation_Context::mark_write_started();
			Full_Elementor_MCP_Mutation_Context::increment_write_count();
		}

		return $res;
	}

	/**
	 * Safely deletes a WordPress post.
	 *
	 * @param int  $post_id Post ID.
	 * @param bool $force   Whether to bypass trash (strict boolean).
	 * @return \WP_Post|false|null|\WP_Error Deleted post or false/WP_Error.
	 */
	public static function delete_post( int $post_id, bool $force = false ): \WP_Post|false|null|\WP_Error {
		if ( $post_id <= 0 ) {
			return new \WP_Error( 'invalid_post_id', __( 'Invalid post ID for safe delete.', 'full-elementor-mcp' ) );
		}

		$guard = self::assert_write_boundary( "post:{$post_id}" );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$res = wp_delete_post( $post_id, $force );
		if ( $res ) {
			Full_Elementor_MCP_Mutation_Context::mark_write_started();
			Full_Elementor_MCP_Mutation_Context::increment_write_count();
		}

		return $res;
	}

	/**
	 * Safely moves a WordPress post to trash.
	 *
	 * @param int $post_id Post ID.
	 * @return \WP_Post|false|null|\WP_Error Trashed post or false/WP_Error.
	 */
	public static function trash_post( int $post_id ): \WP_Post|false|null|\WP_Error {
		if ( $post_id <= 0 ) {
			return new \WP_Error( 'invalid_post_id', __( 'Invalid post ID for safe trash.', 'full-elementor-mcp' ) );
		}

		$guard = self::assert_write_boundary( "post:{$post_id}" );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$res = wp_trash_post( $post_id );
		if ( $res ) {
			Full_Elementor_MCP_Mutation_Context::mark_write_started();
			Full_Elementor_MCP_Mutation_Context::increment_write_count();
		}

		return $res;
	}

	/**
	 * Safely restores a WordPress post from trash.
	 *
	 * @param int $post_id Post ID.
	 * @return \WP_Post|false|null|\WP_Error Untrashed post or false/WP_Error.
	 */
	public static function untrash_post( int $post_id ): \WP_Post|false|null|\WP_Error {
		if ( $post_id <= 0 ) {
			return new \WP_Error( 'invalid_post_id', __( 'Invalid post ID for safe untrash.', 'full-elementor-mcp' ) );
		}

		$guard = self::assert_write_boundary( "post:{$post_id}" );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$res = wp_untrash_post( $post_id );
		if ( $res ) {
			Full_Elementor_MCP_Mutation_Context::mark_write_started();
			Full_Elementor_MCP_Mutation_Context::increment_write_count();
		}

		return $res;
	}

	/**
	 * Safely updates post meta for a post.
	 *
	 * @param int    $post_id    Post ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value Meta value.
	 * @param mixed  $prev_value Previous value.
	 * @return bool|int|\WP_Error Meta ID, true on update, false on no change, or WP_Error.
	 */
	public static function update_post_meta( int $post_id, string $meta_key, mixed $meta_value, mixed $prev_value = '' ): bool|int|\WP_Error {
		if ( $post_id <= 0 ) {
			return new \WP_Error( 'invalid_post_id', __( 'Invalid post ID for safe meta update.', 'full-elementor-mcp' ) );
		}

		$guard = self::assert_write_boundary( "post:{$post_id}" );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$res = update_post_meta( $post_id, $meta_key, $meta_value, $prev_value );
		Full_Elementor_MCP_Mutation_Context::mark_write_started();
		Full_Elementor_MCP_Mutation_Context::increment_write_count();

		return $res;
	}

	/**
	 * Safely deletes post meta for a post.
	 *
	 * @param int    $post_id    Post ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value Meta value.
	 * @return bool|\WP_Error True on success, false on failure, or WP_Error.
	 */
	public static function delete_post_meta( int $post_id, string $meta_key, mixed $meta_value = '' ): bool|\WP_Error {
		if ( $post_id <= 0 ) {
			return new \WP_Error( 'invalid_post_id', __( 'Invalid post ID for safe meta delete.', 'full-elementor-mcp' ) );
		}

		$guard = self::assert_write_boundary( "post:{$post_id}" );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$res = delete_post_meta( $post_id, $meta_key, $meta_value );
		Full_Elementor_MCP_Mutation_Context::mark_write_started();
		Full_Elementor_MCP_Mutation_Context::increment_write_count();

		return $res;
	}

	/**
	 * Safely sets the featured image for a post.
	 *
	 * @param int $post_id      Post ID.
	 * @param int $thumbnail_id Attachment ID.
	 * @return bool|\WP_Error True on success or WP_Error.
	 */
	public static function set_post_thumbnail( int $post_id, int $thumbnail_id ): bool|\WP_Error {
		if ( $post_id <= 0 ) {
			return new \WP_Error( 'invalid_post_id', __( 'Invalid post ID for safe thumbnail set.', 'full-elementor-mcp' ) );
		}

		$guard = self::assert_write_boundary( "post:{$post_id}" );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$res = set_post_thumbnail( $post_id, $thumbnail_id );
		Full_Elementor_MCP_Mutation_Context::mark_write_started();
		Full_Elementor_MCP_Mutation_Context::increment_write_count();

		return $res;
	}

	/**
	 * Safely deletes the featured image for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return bool|\WP_Error True on success or WP_Error.
	 */
	public static function delete_post_thumbnail( int $post_id ): bool|\WP_Error {
		if ( $post_id <= 0 ) {
			return new \WP_Error( 'invalid_post_id', __( 'Invalid post ID for safe thumbnail delete.', 'full-elementor-mcp' ) );
		}

		$guard = self::assert_write_boundary( "post:{$post_id}" );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$res = delete_post_thumbnail( $post_id );
		Full_Elementor_MCP_Mutation_Context::mark_write_started();
		Full_Elementor_MCP_Mutation_Context::increment_write_count();

		return $res;
	}

	/**
	 * Safely sets taxonomy terms for an object.
	 *
	 * @param int    $object_id Object/Post ID.
	 * @param mixed  $terms     Terms to assign.
	 * @param string $taxonomy Taxonomy name.
	 * @param bool   $append   Whether to append.
	 * @return array|\WP_Error Array of term taxonomy IDs or WP_Error.
	 */
	public static function set_object_terms( int $object_id, mixed $terms, string $taxonomy, bool $append = false ): array|\WP_Error {
		if ( $object_id <= 0 ) {
			return new \WP_Error( 'invalid_object_id', __( 'Invalid object ID for safe term assignment.', 'full-elementor-mcp' ) );
		}

		$guard = self::assert_write_boundary( "post:{$object_id}" );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$res = wp_set_object_terms( $object_id, $terms, $taxonomy, $append );
		if ( ! is_wp_error( $res ) ) {
			Full_Elementor_MCP_Mutation_Context::mark_write_started();
			Full_Elementor_MCP_Mutation_Context::increment_write_count();
		}

		return $res;
	}

	/**
	 * Safely updates a WordPress option (e.g. active kit).
	 *
	 * @param string $option   Option name.
	 * @param mixed  $value    Option value.
	 * @param mixed  $autoload Autoload setting.
	 * @return bool|\WP_Error True if updated or unchanged, false on failure, or WP_Error.
	 */
	public static function update_option( string $option, mixed $value, mixed $autoload = null ): bool|\WP_Error {
		$resource_key = 'elementor_active_kit' === $option
			? 'global:elementor-kit-state'
			: "option:{$option}";

		$guard = self::assert_write_boundary( $resource_key );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$res = update_option( $option, $value, $autoload );
		Full_Elementor_MCP_Mutation_Context::mark_write_started();
		Full_Elementor_MCP_Mutation_Context::increment_write_count();

		return $res;
	}

	/**
	 * Safely deletes a WordPress option.
	 *
	 * @param string $option Option name.
	 * @return bool|\WP_Error True on success or WP_Error.
	 */
	public static function delete_option( string $option ): bool|\WP_Error {
		$resource_key = 'elementor_active_kit' === $option
			? 'global:elementor-kit-state'
			: "option:{$option}";

		$guard = self::assert_write_boundary( $resource_key );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		$res = delete_option( $option );
		Full_Elementor_MCP_Mutation_Context::mark_write_started();
		Full_Elementor_MCP_Mutation_Context::increment_write_count();

		return $res;
	}

	/**
	 * Safely sideloads media into the WordPress media library.
	 *
	 * @param array<string, mixed> $file_array File array (tmp_name, name).
	 * @param int                  $post_id    Associated post ID (0 for unattached).
	 * @param string|null          $desc       Optional attachment description.
	 * @param array<string, mixed> $post_data  Optional post data override.
	 * @return int|\WP_Error Attachment ID or WP_Error on failure.
	 */
	public static function media_handle_sideload( array $file_array, int $post_id = 0, ?string $desc = null, array $post_data = array() ): int|\WP_Error {
		if ( ! class_exists( 'Full_Elementor_MCP_Mutation_Context' ) ) {
			return new \WP_Error( 'mutation_context_missing', __( 'Mutation context unavailable for safe sideload.', 'full-elementor-mcp' ) );
		}

		$ctx = Full_Elementor_MCP_Mutation_Context::current();
		if ( ! $ctx ) {
			return new \WP_Error( 'mutation_context_missing', __( 'Active mutation context missing for media sideload.', 'full-elementor-mcp' ) );
		}

		// Check write boundary:
		$resource_key = (string) ( $ctx['resource_key'] ?? '' );
		$guard        = self::assert_write_boundary( $resource_key );
		if ( is_wp_error( $guard ) ) {
			return $guard;
		}

		if ( ! function_exists( 'media_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$att_id = media_handle_sideload( $file_array, $post_id, $desc, $post_data );
		if ( is_wp_error( $att_id ) ) {
			return $att_id;
		}

		$att_id = (int) $att_id;
		Full_Elementor_MCP_Mutation_Context::mark_write_started();
		Full_Elementor_MCP_Mutation_Context::increment_write_count();

		// If this was a CREATE context for media attachment, journal created_object_id immediately:
		$is_create = 0 === strpos( $resource_key, 'create:' );
		if ( $is_create ) {
			$journal_id    = (int) ( $ctx['journal_id'] ?? 0 );
			$fencing_token = (int) ( $ctx['fencing_token'] ?? 0 );
			if ( $journal_id > 0 && class_exists( 'Full_Elementor_MCP_Journal' ) ) {
				Full_Elementor_MCP_Journal::record_created_object_id( $journal_id, $att_id, $fencing_token );
			}
			Full_Elementor_MCP_Mutation_Context::bind_created_object_id( $att_id );
		}

		return $att_id;
	}

	/**
	 * Helper asserting active write boundary immediately before any persistent write.
	 *
	 * @param string $expected_resource_key Expected resource key.
	 * @return true|\WP_Error True if authorized, WP_Error if rejected.
	 */
	public static function assert_write_boundary( string $expected_resource_key ): bool|\WP_Error {
		if ( ! class_exists( 'Full_Elementor_MCP_Mutation_Context' ) ) {
			return new \WP_Error( 'mutation_context_missing', __( 'Mutation context unavailable for write boundary assertion.', 'full-elementor-mcp' ) );
		}

		if ( ! Full_Elementor_MCP_Mutation_Context::has_active_context() ) {
			return new \WP_Error( 'mutation_context_missing', __( 'Persistent write rejected: no active Full Elementor MCP mutation context exists.', 'full-elementor-mcp' ) );
		}

		// Enforce write context:
		return Full_Elementor_MCP_Mutation_Context::assert_active_write_context( $expected_resource_key );
	}
}
