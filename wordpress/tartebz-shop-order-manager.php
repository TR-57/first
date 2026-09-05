<?php
/**
 * Tartebz — Shop Order Manager Role
 * Version: 1.1
 * --------------------------------------------------------------
 * Creates a custom user role that ONLY has access to WooCommerce orders.
 * The user can:
 *   - View / search / filter all orders
 *   - Create new orders manually
 *   - Edit order data + change status (processing, completed, etc.)
 *   - Print invoices (via PDF Invoice plugins or browser print)
 *   - Add order notes
 *   - Use shipping / courier plugin panels on orders (UBEX, Gate-E, ...)
 *   - View own profile
 *
 * The user CANNOT:
 *   - Edit products, pages, posts, settings
 *   - Access WPML / TranslatePress / any translation plugin
 *   - Install plugins / themes
 *   - Manage users
 *   - See WooCommerce settings, reports, customers tab, etc.
 *     (menus are hidden AND the URLs are locked — see sections 3 & 4)
 *   - Delete orders (can only update status to cancelled/refunded)
 *
 * --------------------------------------------------------------
 * CHANGELOG
 * 1.1
 *   - FIX: grant `manage_woocommerce`. Shipping / courier plugins gate their
 *          order meta boxes and their AJAX "create shipment" handlers behind
 *          this capability. Without it the courier plugin could not read the
 *          selected service for this role and logged
 *          "No shipment method selected" / "Error 2: shipment not found".
 *          Settings/Reports stay hidden + URL-locked, so this does NOT expose
 *          WooCommerce configuration to the role.
 *   - NEW: Shipment-error banner on the order screen (section 10). When an
 *          order note contains a courier error, a red banner appears with
 *          "Back to Orders" and "Create New Order" buttons.
 *   - NEW: Filters to whitelist extra admin pages / menus for courier plugins:
 *            tz_order_manager_allowed_admin_pages
 *            tz_order_manager_keep_menus
 *            tz_shipment_error_patterns
 * 1.0
 *   - Initial release.
 *
 * --------------------------------------------------------------
 * INSTALL:
 *   1) WP Admin → Plugins → install "Code Snippets" if not already there
 *   2) Snippets → Add New → PHP Snippet → paste this code
 *   3) Run scope: "Run everywhere" → Activate
 *   4) WP Admin → Users → Add New → assign role "Shop Order Manager"
 *
 * UNINSTALL:
 *   Deactivate this snippet — the role is auto-removed
 *   (the line `register_deactivation_hook` won't fire in Code Snippets,
 *    so to fully remove the role, comment-out add_role + uncomment remove_role
 *    once, then activate)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'TZ_ORDER_ROLE_KEY',  'shop_order_manager' );
define( 'TZ_ORDER_ROLE_NAME', 'Shop Order Manager' );


/* ============================================================
 *  0. Shared helpers
 * ============================================================ */

/** True when WooCommerce HPOS (custom orders table) is the active backend. */
function tz_hpos_active() {
    return class_exists( '\\Automattic\\WooCommerce\\Utilities\\OrderUtil' )
        && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
}

/** URL of the orders list (HPOS or legacy). */
function tz_orders_landing_url() {
    return admin_url( tz_hpos_active() ? 'admin.php?page=wc-orders' : 'edit.php?post_type=shop_order' );
}

/** URL of the "create new order" screen (HPOS or legacy). */
function tz_new_order_url() {
    return admin_url( tz_hpos_active() ? 'admin.php?page=wc-orders&action=new' : 'post-new.php?post_type=shop_order' );
}

/** True when the current user holds the Shop Order Manager role. */
function tz_is_order_manager() {
    return current_user_can( TZ_ORDER_ROLE_KEY ) && ! current_user_can( 'manage_options' );
}

/**
 * Returns the order ID when the current admin screen is a single-order
 * edit screen (HPOS or legacy), otherwise 0.
 */
function tz_get_current_order_id() {
    global $pagenow;

    /* HPOS: admin.php?page=wc-orders&action=edit&id=123 */
    if ( $pagenow === 'admin.php'
        && isset( $_GET['page'], $_GET['id'] )
        && $_GET['page'] === 'wc-orders' ) {
        return absint( $_GET['id'] );
    }

    /* Legacy CPT: post.php?post=123&action=edit */
    if ( $pagenow === 'post.php' && isset( $_GET['post'] ) ) {
        $post_id = absint( $_GET['post'] );
        if ( $post_id && get_post_type( $post_id ) === 'shop_order' ) {
            return $post_id;
        }
    }

    return 0;
}


/* ============================================================
 *  1. Register the custom role (runs once on each request — safe)
 * ============================================================ */
add_action( 'init', function () {
    $role_key  = TZ_ORDER_ROLE_KEY;
    $role_name = TZ_ORDER_ROLE_NAME;

    $caps = array(
        /* Base WP capability — needed to log into admin */
        'read'                          => true,

        /* v1.1 FIX — required by shipping / courier plugins (UBEX, Gate-E, ...)
         * so their order panels render and their AJAX "create shipment"
         * handlers run for this role. Settings/Reports remain hidden and
         * URL-locked by sections 3 & 4. */
        'manage_woocommerce'            => true,

        /* WooCommerce order capabilities (legacy CPT shop_order) */
        'edit_shop_order'               => true,
        'read_shop_order'               => true,
        'edit_shop_orders'              => true,
        'edit_others_shop_orders'       => true,
        'publish_shop_orders'           => true,
        'read_private_shop_orders'      => true,
        'edit_published_shop_orders'    => true,
        'edit_private_shop_orders'      => true,
        /* Explicitly NO delete (safer) */
        'delete_shop_order'             => false,
        'delete_shop_orders'            => false,
        'delete_others_shop_orders'     => false,
        'delete_private_shop_orders'    => false,
        'delete_published_shop_orders'  => false,
    );

    /* If role already exists, just sync caps (don't recreate) */
    $existing = get_role( $role_key );
    if ( $existing ) {
        foreach ( $caps as $cap => $grant ) {
            if ( $grant ) $existing->add_cap( $cap );
            else          $existing->remove_cap( $cap );
        }
    } else {
        add_role( $role_key, $role_name, $caps );
    }
} );


/* ============================================================
 *  2. Add a direct ORDERS menu
 *     Handles both legacy (CPT) and HPOS (custom-orders-table) backends.
 * ============================================================ */
add_action( 'admin_menu', function () {
    if ( ! current_user_can( TZ_ORDER_ROLE_KEY ) ) return;

    $orders_slug = tz_hpos_active() ? 'admin.php?page=wc-orders' : 'edit.php?post_type=shop_order';

    add_menu_page(
        __( 'Orders', 'tartebz' ),
        __( 'Orders', 'tartebz' ),
        'edit_shop_orders',
        $orders_slug,
        '',
        'dashicons-cart',
        5
    );
}, 9 );


/* ============================================================
 *  3. Hide ALL other menus for this role
 *     (manage_woocommerce would otherwise surface WooCommerce / Marketing /
 *      Analytics menus — they are removed here and URL-locked in section 4)
 * ============================================================ */
add_action( 'admin_menu', function () {
    if ( ! tz_is_order_manager() ) return;

    global $menu;

    /* Slugs we keep visible. Add courier-plugin menu slugs via the filter. */
    $keep = apply_filters( 'tz_order_manager_keep_menus', array(
        'edit.php?post_type=shop_order',
        'admin.php?page=wc-orders',
        'profile.php',
    ) );

    if ( ! is_array( $menu ) ) return;
    foreach ( $menu as $idx => $item ) {
        if ( empty( $item[2] ) ) continue;
        $slug = $item[2];
        if ( ! in_array( $slug, $keep, true ) ) {
            remove_menu_page( $slug );
        }
    }
}, 9999 );


/* ============================================================
 *  4. Lock the URL — if they try to navigate to a forbidden page,
 *     bounce back to Orders
 * ============================================================ */
add_action( 'admin_init', function () {
    if ( ! tz_is_order_manager() ) return;
    if ( wp_doing_ajax() ) return; // courier plugins' AJAX must pass

    global $pagenow;

    /* WP screens that are OK */
    $allowed_pagenow = array(
        'index.php',         // dashboard
        'edit.php',          // CPT orders list (only when post_type=shop_order)
        'post.php',          // edit single order
        'post-new.php',      // create new order
        'profile.php',       // own profile
        'admin-ajax.php',
        'admin-post.php',
    );

    /* admin.php?page=... screens that are OK.
     * If your courier plugin has its own screen (e.g. a shipments list or a
     * label/AWB printing page), add its `page=` slug through this filter:
     *
     *   add_filter( 'tz_order_manager_allowed_admin_pages', function ( $pages ) {
     *       $pages[] = 'ubex-shipments';   // <- example slug
     *       return $pages;
     *   } );
     */
    $allowed_admin_pages = apply_filters( 'tz_order_manager_allowed_admin_pages', array(
        'wc-orders',                    // HPOS list / edit / new
        'wc-orders--shop_subscription', // subs sub-page if Subscriptions installed
    ) );

    $page_param = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';

    /* Allowed if pagenow matches OR if pagenow=admin.php with allowed page */
    $is_allowed = in_array( $pagenow, $allowed_pagenow, true );
    if ( $pagenow === 'admin.php' && in_array( $page_param, $allowed_admin_pages, true ) ) {
        $is_allowed = true;
    }

    /* For edit.php, ensure post_type is shop_order */
    if ( $pagenow === 'edit.php' ) {
        $pt = isset( $_GET['post_type'] ) ? sanitize_text_field( wp_unslash( $_GET['post_type'] ) ) : 'post';
        if ( $pt !== 'shop_order' ) $is_allowed = false;
    }

    /* For post.php / post-new.php, ensure it relates to shop_order */
    if ( $pagenow === 'post.php' && isset( $_GET['post'] ) ) {
        $pt = get_post_type( (int) $_GET['post'] );
        if ( $pt && $pt !== 'shop_order' ) $is_allowed = false;
    }
    if ( $pagenow === 'post-new.php' ) {
        $pt = isset( $_GET['post_type'] ) ? sanitize_text_field( wp_unslash( $_GET['post_type'] ) ) : 'post';
        if ( $pt !== 'shop_order' ) $is_allowed = false;
    }

    if ( ! $is_allowed ) {
        wp_safe_redirect( tz_orders_landing_url() );
        exit;
    }
}, 1 );


/* ============================================================
 *  5. After login → land directly on Orders
 *     Hooks BOTH wp-login.php and WooCommerce's frontend login form
 *     (WC otherwise sends non-admin users to /my-account/)
 * ============================================================ */

/* wp-login.php redirect */
add_filter( 'login_redirect', function ( $redirect_to, $request, $user ) {
    if ( is_a( $user, 'WP_User' ) && in_array( TZ_ORDER_ROLE_KEY, (array) $user->roles, true ) ) {
        return tz_orders_landing_url();
    }
    return $redirect_to;
}, 99, 3 );

/* WooCommerce frontend login redirect (this is what was sending them to /my-account/) */
add_filter( 'woocommerce_login_redirect', function ( $redirect, $user ) {
    if ( is_a( $user, 'WP_User' ) && in_array( TZ_ORDER_ROLE_KEY, (array) $user->roles, true ) ) {
        return tz_orders_landing_url();
    }
    return $redirect;
}, 99, 2 );

/* Belt-and-braces: if a Shop Order Manager somehow lands on /my-account/, kick them to Orders */
add_action( 'template_redirect', function () {
    if ( ! is_user_logged_in() ) return;
    $user = wp_get_current_user();
    if ( ! in_array( TZ_ORDER_ROLE_KEY, (array) $user->roles, true ) ) return;
    if ( function_exists( 'is_account_page' ) && is_account_page() ) {
        wp_safe_redirect( tz_orders_landing_url() );
        exit;
    }
} );

/* They don't need a frontend at all — send any frontend visit to Orders */
add_action( 'template_redirect', function () {
    if ( ! is_user_logged_in() ) return;
    if ( is_admin() ) return;
    $user = wp_get_current_user();
    if ( ! in_array( TZ_ORDER_ROLE_KEY, (array) $user->roles, true ) ) return;
    wp_safe_redirect( tz_orders_landing_url() );
    exit;
}, 1 );


/* ============================================================
 *  6. Clean admin bar — remove things they shouldn't see
 * ============================================================ */
add_action( 'wp_before_admin_bar_render', function () {
    if ( ! tz_is_order_manager() ) return;

    global $wp_admin_bar;
    $wp_admin_bar->remove_menu( 'new-content' );
    $wp_admin_bar->remove_menu( 'comments' );
    $wp_admin_bar->remove_menu( 'updates' );
    $wp_admin_bar->remove_menu( 'customize' );
    $wp_admin_bar->remove_menu( 'wpseo-menu' );    // Yoast
    $wp_admin_bar->remove_menu( 'rank-math' );      // Rank Math
    $wp_admin_bar->remove_menu( 'WPML_ALS' );       // WPML language switcher
} );


/* ============================================================
 *  7. Hide dashboard widgets they don't need
 * ============================================================ */
add_action( 'wp_dashboard_setup', function () {
    if ( ! tz_is_order_manager() ) return;

    global $wp_meta_boxes;
    $wp_meta_boxes['dashboard'] = array();
}, 9999 );


/* ============================================================
 *  8. Hide "Screen Options" + "Help" tabs to keep the UI minimal
 * ============================================================ */
add_filter( 'screen_options_show_screen', function ( $show ) {
    if ( tz_is_order_manager() ) return false;
    return $show;
} );


/* ============================================================
 *  9. Minimal UI — hide generic notices / footer for this role.
 *     NOTE: our own shipment-error banner (section 10) carries the class
 *     `tz-shipment-alert` and is deliberately excluded from this rule.
 * ============================================================ */
add_action( 'admin_head', function () {
    if ( ! tz_is_order_manager() ) return;
    ?>
    <style>
    /* Hide generic notices that aren't relevant — but keep our banner */
    .notice:not(.tz-shipment-alert),
    .updated:not(.tz-shipment-alert),
    .error:not(.tz-shipment-alert),
    .update-nag,
    #wpfooter,
    #screen-meta-links { display: none !important; }
    /* Hide "Howdy" extras */
    #wp-admin-bar-new-content,
    #wp-admin-bar-comments { display: none !important; }
    </style>
    <?php
} );


/* ============================================================
 *  10. Shipment-error banner (v1.1)
 *      When a courier / shipping plugin logs an error on an order
 *      (e.g. "No shipment method selected", "shipment not found"),
 *      show a red banner at the top of that order screen with:
 *        [← Back to Orders]  [+ Create New Order]
 *      Visible to anyone who can edit orders (managers + admins).
 * ============================================================ */

/** Phrases (case-insensitive) that identify a courier error note. */
function tz_shipment_error_patterns() {
    return apply_filters( 'tz_shipment_error_patterns', array(
        'no shipment method selected',
        'no shipping method selected',
        'shipment not found',
        'shipment failed',
        'shipment error',
    ) );
}

/**
 * Returns the most recent order note that matches an error pattern,
 * or null when the order has no courier error.
 */
function tz_get_shipment_error_note( $order_id ) {
    if ( ! function_exists( 'wc_get_order_notes' ) ) return null;

    $notes = wc_get_order_notes( array(
        'order_id' => $order_id,
        'limit'    => 20,
        'orderby'  => 'date_created',
        'order'    => 'DESC',
    ) );
    if ( empty( $notes ) ) return null;

    $patterns = array_map( 'strtolower', tz_shipment_error_patterns() );

    foreach ( $notes as $note ) {
        $content = strtolower( wp_strip_all_tags( (string) $note->content ) );
        foreach ( $patterns as $pattern ) {
            if ( $pattern !== '' && strpos( $content, $pattern ) !== false ) {
                return $note;
            }
        }
    }
    return null;
}

add_action( 'admin_notices', function () {
    if ( ! current_user_can( 'edit_shop_orders' ) ) return;

    $order_id = tz_get_current_order_id();
    if ( ! $order_id ) return;

    $note = tz_get_shipment_error_note( $order_id );
    if ( ! $note ) return;

    $message = wp_strip_all_tags( (string) $note->content );
    $when    = '';
    if ( isset( $note->date_created ) && is_object( $note->date_created ) && method_exists( $note->date_created, 'date_i18n' ) ) {
        $when = $note->date_created->date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
    }
    ?>
    <div class="notice notice-error tz-shipment-alert"
         style="border-left:5px solid #d63638;background:#fff5f5;padding:14px 18px;margin:16px 0 8px;">
        <p style="margin:0 0 6px;font-size:15px;">
            <strong>&#9888; <?php esc_html_e( 'Shipment error on this order', 'tartebz' ); ?> #<?php echo esc_html( $order_id ); ?></strong>
        </p>
        <p style="margin:0 0 6px;">
            <code style="background:#fde8e8;padding:2px 6px;"><?php echo esc_html( $message ); ?></code>
            <?php if ( $when ) : ?>
                <span style="color:#666;">&mdash; <?php echo esc_html( $when ); ?></span>
            <?php endif; ?>
        </p>
        <p style="margin:0 0 10px;color:#444;">
            <?php esc_html_e( 'The courier could not create the shipment. Check the Order Notes panel for details, then go back to the orders list or start a new order.', 'tartebz' ); ?>
        </p>
        <p style="margin:0;">
            <a class="button button-primary" href="<?php echo esc_url( tz_orders_landing_url() ); ?>">
                &larr; <?php esc_html_e( 'Back to Orders', 'tartebz' ); ?>
            </a>
            &nbsp;
            <a class="button" href="<?php echo esc_url( tz_new_order_url() ); ?>">
                + <?php esc_html_e( 'Create New Order', 'tartebz' ); ?>
            </a>
        </p>
    </div>
    <?php
} );
