<?php
/**
 * Plugin Name: WP Auto Featured Image
 * Plugin URI: https://github.com/Azanumer/wp-auto-featured-image
 * Description: Automatically sets a post's featured image from the first image in its content. Uses the image already in the Media Library when possible, and can sideload external images. Includes a bulk retrofill tool.
 * Version: 1.0.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: Azan Umer
 * Author URI: https://azanumer.cv
 * License: MIT
 * Text Domain: wp-auto-featured-image
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'WAFI_VERSION', '1.0.0' );

/**
 * Default settings.
 */
function wafi_default_options() {
    return array(
        'post_types'       => array( 'post' ),
        'sideload_external' => 0,
        'skip_svg'         => 1,
    );
}

function wafi_get_options() {
    return wp_parse_args( get_option( 'wafi_options', array() ), wafi_default_options() );
}

/**
 * Extract the first <img> src from post content.
 *
 * @param string $content Post content.
 * @return string Empty string when no image is found.
 */
function wafi_first_image_src( $content ) {
    if ( preg_match( '/<img[^>]+src=["\']([^"\']+)["\']/i', $content, $m ) ) {
        return trim( $m[1] );
    }
    return '';
}

/**
 * Resolve an image URL to an attachment ID.
 *
 * First tries the Media Library (exact URL match). When sideloading is
 * enabled, a remote/local image that is not in the library is downloaded
 * into it and the new attachment ID is returned.
 *
 * @param string $src     Image URL.
 * @param array  $options Plugin options.
 * @return int Attachment ID, or 0 when it cannot be resolved.
 */
function wafi_resolve_attachment_id( $src, $options ) {
    // Skip data URIs and SVGs (SVGs are not allowed as featured images by default).
    if ( strpos( $src, 'data:' ) === 0 ) {
        return 0;
    }
    if ( ! empty( $options['skip_svg'] ) && preg_match( '/\.svg(\?|#|$)/i', $src ) ) {
        return 0;
    }

    $id = attachment_url_to_postid( $src );
    if ( $id ) {
        return (int) $id;
    }

    if ( empty( $options['sideload_external'] ) ) {
        return 0;
    }

    // Only sideload http(s) URLs — never a relative path or another scheme.
    if ( ! preg_match( '#^https?://#i', $src ) ) {
        return 0;
    }

    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $id = media_sideload_image( $src, 0, null, 'id' );
    if ( is_wp_error( $id ) || ! $id ) {
        return 0;
    }
    return (int) $id;
}

/**
 * Set the featured image from the first image in the content, when the
 * post does not already have one.
 *
 * Runs on save_post with a late priority so other save handlers (e.g. the
 * block editor's content filters) have already run.
 */
function wafi_maybe_set_featured_image( $post_id, $post, $update ) {
    // Skip autosaves, revisions and auto-drafts.
    if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
        return;
    }
    if ( $post->post_status === 'auto-draft' ) {
        return;
    }

    $options = wafi_get_options();
    if ( ! in_array( $post->post_type, (array) $options['post_types'], true ) ) {
        return;
    }

    // Never overwrite a featured image the author set on purpose.
    if ( has_post_thumbnail( $post_id ) ) {
        return;
    }

    $src = wafi_first_image_src( $post->post_content );
    if ( ! $src ) {
        return;
    }

    $attachment_id = wafi_resolve_attachment_id( $src, $options );
    if ( ! $attachment_id ) {
        return;
    }

    set_post_thumbnail( $post_id, $attachment_id );
}
add_action( 'save_post', 'wafi_maybe_set_featured_image', 20, 3 );

/**
 * Bulk retrofill: set featured images on posts that are missing one.
 * Processes 20 posts per request and reports how many remain.
 */
function wafi_bulk_retrofill() {
    check_admin_referer( 'wafi_bulk_retrofill' );
    if ( ! current_user_can( 'edit_posts' ) ) {
        wp_die( esc_html__( 'You do not have permission to do this.', 'wp-auto-featured-image' ) );
    }

    $options = wafi_get_options();
    $done    = 0;
    $skipped = 0;

    $posts = get_posts( array(
        'post_type'      => $options['post_types'],
        'post_status'    => array( 'publish', 'draft', 'pending' ),
        'posts_per_page' => 20,
        'meta_query'     => array(
            array(
                'key'     => '_thumbnail_id',
                'compare' => 'NOT EXISTS',
            ),
        ),
        'orderby'        => 'date',
        'order'          => 'DESC',
    ) );

    foreach ( $posts as $post ) {
        $src = wafi_first_image_src( $post->post_content );
        if ( ! $src ) {
            $skipped++;
            continue;
        }
        $attachment_id = wafi_resolve_attachment_id( $src, $options );
        if ( ! $attachment_id ) {
            $skipped++;
            continue;
        }
        set_post_thumbnail( $post->ID, $attachment_id );
        $done++;
    }

    $remaining = wafi_count_missing_thumbnails();
    $redirect  = add_query_arg( array(
        'page'      => 'wafi-settings',
        'wafi_done' => $done,
        'wafi_skip' => $skipped,
        'wafi_left' => $remaining,
    ), admin_url( 'tools.php' ) );
    wp_safe_redirect( $redirect );
    exit;
}
add_action( 'admin_post_wafi_bulk_retrofill', 'wafi_bulk_retrofill' );

/**
 * Count published/draft posts of the enabled types that have no thumbnail.
 */
function wafi_count_missing_thumbnails() {
    $options = wafi_get_options();
    $query   = new WP_Query( array(
        'post_type'      => $options['post_types'],
        'post_status'    => array( 'publish', 'draft', 'pending' ),
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'meta_query'     => array(
            array(
                'key'     => '_thumbnail_id',
                'compare' => 'NOT EXISTS',
            ),
        ),
    ) );
    return (int) $query->found_posts;
}

/**
 * Settings page under Tools.
 */
function wafi_register_menu() {
    add_management_page(
        __( 'Auto Featured Image', 'wp-auto-featured-image' ),
        __( 'Auto Featured Image', 'wp-auto-featured-image' ),
        'manage_options',
        'wafi-settings',
        'wafi_render_settings_page'
    );
}
add_action( 'admin_menu', 'wafi_register_menu' );

function wafi_register_settings() {
    register_setting( 'wafi_options_group', 'wafi_options', array(
        'sanitize_callback' => 'wafi_sanitize_options',
    ) );
}
add_action( 'admin_init', 'wafi_register_settings' );

/**
 * Sanitize the settings form: post types must be real public types,
 * checkboxes become strict 0/1.
 */
function wafi_sanitize_options( $input ) {
    $defaults = wafi_default_options();
    $output   = $defaults;

    $post_types = isset( $input['post_types'] ) ? (array) $input['post_types'] : array();
    $valid      = array();
    foreach ( $post_types as $type ) {
        $type = sanitize_key( $type );
        if ( post_type_exists( $type ) ) {
            $valid[] = $type;
        }
    }
    $output['post_types']        = $valid ? $valid : array( 'post' );
    $output['sideload_external'] = ! empty( $input['sideload_external'] ) ? 1 : 0;
    $output['skip_svg']          = ! empty( $input['skip_svg'] ) ? 1 : 0;

    return $output;
}

function wafi_render_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    $options  = wafi_get_options();
    $missing  = wafi_count_missing_thumbnails();
    $done     = isset( $_GET['wafi_done'] ) ? (int) $_GET['wafi_done'] : null;
    $skipped  = isset( $_GET['wafi_skip'] ) ? (int) $_GET['wafi_skip'] : null;
    $left     = isset( $_GET['wafi_left'] ) ? (int) $_GET['wafi_left'] : null;
    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'Auto Featured Image', 'wp-auto-featured-image' ); ?></h1>

        <?php if ( $done !== null ) : ?>
            <div class="notice notice-success is-dismissible"><p>
                <?php
                /* translators: %1$d featured images set, %2$d posts skipped, %3$d posts still missing one. */
                printf( esc_html__( 'Done: %1$d featured images set, %2$d posts skipped (no usable image), %3$d posts still missing one.', 'wp-auto-featured-image' ), $done, $skipped, $left );
                ?>
            </p></div>
        <?php endif; ?>

        <p><?php esc_html_e( 'When a post is saved without a featured image, the first image in its content becomes the featured image automatically. Images already in the Media Library are reused; external images are only downloaded when sideloading is enabled below.', 'wp-auto-featured-image' ); ?></p>

        <form method="post" action="options.php">
            <?php settings_fields( 'wafi_options_group' ); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e( 'Post types', 'wp-auto-featured-image' ); ?></th>
                    <td>
                        <?php foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type ) : ?>
                            <label>
                                <input type="checkbox" name="wafi_options[post_types][]" value="<?php echo esc_attr( $type->name ); ?>" <?php checked( in_array( $type->name, (array) $options['post_types'], true ) ); ?>>
                                <?php echo esc_html( $type->label ); ?>
                            </label><br>
                        <?php endforeach; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Sideload external images', 'wp-auto-featured-image' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="wafi_options[sideload_external]" value="1" <?php checked( $options['sideload_external'], 1 ); ?>>
                            <?php esc_html_e( 'Download external images into the Media Library when the first image is not already there', 'wp-auto-featured-image' ); ?>
                        </label>
                        <p class="description"><?php esc_html_e( 'Off by default: hotlinked images are ignored, so nothing is downloaded without your say-so.', 'wp-auto-featured-image' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Skip SVG images', 'wp-auto-featured-image' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="wafi_options[skip_svg]" value="1" <?php checked( $options['skip_svg'], 1 ); ?>>
                            <?php esc_html_e( 'Never use an SVG as the featured image', 'wp-auto-featured-image' ); ?>
                        </label>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>

        <hr>
        <h2><?php esc_html_e( 'Bulk retrofill', 'wp-auto-featured-image' ); ?></h2>
        <p><?php printf( esc_html__( '%d posts are currently missing a featured image.', 'wp-auto-featured-image' ), $missing ); ?></p>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'wafi_bulk_retrofill' ); ?>
            <input type="hidden" name="action" value="wafi_bulk_retrofill">
            <?php submit_button( __( 'Set featured images for 20 posts', 'wp-auto-featured-image' ), 'secondary' ); ?>
        </form>
        <p class="description"><?php esc_html_e( 'Runs in batches of 20 — repeat until the count above reaches zero. Posts whose content has no usable image are skipped.', 'wp-auto-featured-image' ); ?></p>
    </div>
    <?php
}
