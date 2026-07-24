<?php
/**
 * BT Portal — portal page head CSS (hide admin bar, modal fixes).
 * Ported verbatim from BT-Sched-4-AdminBar.
 */
if (!defined('ABSPATH')) exit;

add_action( 'wp_head', function() {
    if ( ! is_page() ) return;
    global $post;
    if ( ! $post || ! has_shortcode( $post->post_content, 'bt_schedule' ) ) return;
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
