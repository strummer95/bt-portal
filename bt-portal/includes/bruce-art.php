<?php
/**
 * BT Portal — Bruce Art (Other > Bruce Art).
 *
 * Hosts the Bruce site embed inside the employee portal so staff can use it
 * without it sitting on a public page. It lives behind the portal login like
 * every other tab: the [bt_schedule] shortcode returns the login form before
 * any of this renders, so a visitor who is not signed in never gets the embed
 * code at all.
 *
 * The SDK is loaded the first time the tab is opened, not on every portal
 * load. Two reasons: nobody on the Schedule board pays for a third-party
 * script they are not using, and the iframe is built while its pane is
 * visible, so resizeToFit measures a real width instead of a hidden 0px box.
 *
 * Sizing: with resizeToFit the SDK first sizes the iframe to fill the window
 * minus everything below it, then switches to the Bruce page's own height once
 * it connects. So nothing in this pane gets a min-height; empty space under the
 * iframe would be subtracted from it.
 *
 * Note: this keeps the embed off the public site. It does not lock the Bruce
 * site itself; anyone who has its direct address can still reach it. That is
 * a setting on Bruce's side, if they offer one.
 */
if (!defined('ABSPATH')) exit;

if (!defined('BTP_BRUCE_SITE_URL')) define('BTP_BRUCE_SITE_URL', 'https://boomerts.sites.askbruce.ai');
if (!defined('BTP_BRUCE_SDK_URL'))  define('BTP_BRUCE_SDK_URL',  'https://cdn.askbruce.ai/sdk/bruce-sdk.umd.js');

add_shortcode('bt_bruce_art', 'btp_bruce_art_shortcode');

function btp_bruce_art_shortcode() {
    // Belt and braces: the portal already gates this, but the shortcode could
    // be dropped on another page by mistake.
    if ( function_exists('btp_user_can_access') && ! btp_user_can_access() ) return '';

    $cfg = wp_json_encode( array(
        'siteUrl' => apply_filters( 'btp_bruce_site_url', BTP_BRUCE_SITE_URL ),
        'sdkUrl'  => apply_filters( 'btp_bruce_sdk_url',  BTP_BRUCE_SDK_URL ),
    ) );

    ob_start();
    ?>
<div id="btp-bruce-art">
  <div id="btp-bruce-embed" style="display:block;width:100%;"></div>
  <div id="btp-bruce-msg" style="display:none;padding:40px;text-align:center;color:#5a6380;font-family:Barlow,sans-serif;font-size:16px;"></div>
</div>
<script>
(function () {
  var CFG = <?php echo $cfg; ?>;
  var state = 'idle'; // idle -> loading -> ready | failed

  function msg(text) {
    var m = document.getElementById('btp-bruce-msg');
    if (!m) return;
    m.textContent = text;
    m.style.display = text ? 'block' : 'none';
  }

  function build() {
    try {
      // The SDK reads embedContainer, not container. Without it, it looks for
      // an element named "bruce-embed", finds none, and appends a new box to
      // the bottom of <body>, which is where 0.54.0 put the iframe.
      new window.BruceSdk({
        siteUrl: CFG.siteUrl,
        embedContainer: 'btp-bruce-embed',
        container: 'btp-bruce-embed',
        resizeToFit: true
      }).createSiteIframeEmbed();
      state = 'ready';
      msg('');
    } catch (e) {
      state = 'failed';
      msg('Bruce could not start: ' + (e && e.message ? e.message : e) + '. Refresh to try again.');
    }
  }

  // Called by btSwitchTab() each time the tab opens. Builds once.
  window.btpBruceArtLoad = function () {
    if (state === 'ready' || state === 'loading') return;
    if (window.BruceSdk) { build(); return; }
    state = 'loading';
    msg('Loading Bruce…');
    var s = document.createElement('script');
    s.src = CFG.sdkUrl;
    s.async = true;
    s.onload = function () {
      if (window.BruceSdk) build();
      else { state = 'failed'; msg('Bruce loaded but did not start. Refresh to try again.'); }
    };
    s.onerror = function () {
      state = 'idle'; // allow a retry on the next open
      msg('Could not reach Bruce. Check the connection and open the tab again.');
    };
    document.head.appendChild(s);
  };
})();
</script>
    <?php
    return ob_get_clean();
}
