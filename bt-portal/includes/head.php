<?php
/**
 * BT Portal — portal page head CSS (hide admin bar, modal fixes).
 * Ported verbatim from BT-Sched-4-AdminBar.
 */
if (!defined('ABSPATH')) exit;

function btp_is_portal_page() {
    if ( ! is_page() ) return false;
    global $post;
    return $post && has_shortcode( $post->post_content, 'bt_schedule' );
}

/** The title for this request, tab and all. */
function btp_head_title() {
    $tab = function_exists('btp_requested_tab') ? btp_requested_tab() : '';
    return function_exists('btp_document_title') ? btp_document_title($tab) : "Boomer T's Portal";
}

/**
 * The browser tab read 'boomerts.com/employees/' because nothing on this page
 * ever set a title, and a browser falls back to the address when there is none.
 *
 * Two ways in, because which one works is the theme's call. A theme declaring
 * title-tag support has WordPress render the tag and only wants the text, which
 * is the filter. A theme that does not declare it renders no tag at all, and
 * nothing we pass the filter will ever be printed, so we print it ourselves.
 * Guarded on that support so the page can never end up with two.
 *
 * btSetTitle() takes over once the app runs, to name the tab you switch to.
 */
add_filter( 'pre_get_document_title', function( $title ) {
    return btp_is_portal_page() ? btp_head_title() : $title;
} );

add_action( 'wp_head', function() {
    if ( ! btp_is_portal_page() ) return;
    if ( current_theme_supports( 'title-tag' ) ) return;
    echo '<title>' . esc_html( btp_head_title() ) . '</title>' . "
";
}, 0 );

add_action( 'wp_head', function() {
    if ( ! btp_is_portal_page() ) return;
    ?>
    <style>
      /* Hide WP admin bar on the schedule page */
      #wpadminbar { display:none !important; }
      html { margin-top:0 !important; }

      /* Full-bleed: strip theme containers on this page */
      body { margin:0 !important; padding:0 !important; background:#f5f5f5 !important; }
      .site-header, .site-footer, header.wp-block-template-part, footer.wp-block-template-part { display:none !important; }
      .wp-site-blocks, .entry-content, .wp-block-post-content { padding:0 !important; margin:0 !important; max-width:none !important; }
      .wp-block-group { padding:0 !important; margin:0 !important; max-width:none !important; }

      /* Ensure modals overlay everything */
      .bt-modal-overlay { z-index:999999 !important; }
      #btContextMenu { z-index:999999 !important; }
    </style>
    <?php
});
