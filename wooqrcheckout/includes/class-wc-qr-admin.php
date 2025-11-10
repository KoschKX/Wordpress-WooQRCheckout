<?php
/**
 * WooCommerce QR Checkout - Admin functionality
 * 
 * @package WooCommerce_QR_Checkout
 * @author Your Name
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit; // don't allow direct access
}

class WC_QR_Admin {
    
    public function __construct() {
        // hook into WordPress admin
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('add_meta_boxes', array($this, 'add_qr_code_metabox'));
        add_action('save_post_product', array($this, 'save_qr_download_settings'));
        add_action('admin_enqueue_scripts', array($this, 'admin_scripts'));
        
        // ajax stuff
        add_action('wp_ajax_wc_qr_regenerate', array($this, 'ajax_regenerate_qr'));
        add_action('wp_ajax_wc_qr_generate_single', array($this, 'ajax_generate_single_qr'));
        add_action('wp_ajax_wc_qr_save_custom_url', array($this, 'ajax_save_custom_url'));
        add_action('wp_ajax_wc_qr_generate_coupons', array($this, 'ajax_generate_coupons'));
        add_action('wp_ajax_wc_qr_delete_coupon', array($this, 'ajax_delete_coupon'));
        add_action('wp_ajax_wc_qr_verify_download_code', array($this, 'ajax_verify_download_code'));
        add_action('wp_ajax_nopriv_wc_qr_verify_download_code', array($this, 'ajax_verify_download_code'));
        
        // add products to cart when accessing checkout via QR
        add_action('wp_loaded', array($this, 'add_product_to_cart_from_sku'), 5);
    }
    
    // auto-add product to cart when sku param is present
    public function add_product_to_cart_from_sku() {
        if (!isset($_GET['sku']) || empty($_GET['sku'])) {
            return;
        }
        
        // only on checkout page
        $request_uri = $_SERVER['REQUEST_URI'] ?? '';
        if (strpos($request_uri, '/kasse') === false && strpos($request_uri, '/checkout') === false) {
            return;
        }
        
        $sku = sanitize_text_field($_GET['sku']);
        $product_id = wc_get_product_id_by_sku($sku);
        
        if (!$product_id) {
            return;
        }
        
        if (!function_exists('WC') || !WC()->cart) {
            return;
        }
        
        // clear existing cart and add this product
        WC()->cart->empty_cart();
        WC()->cart->add_to_cart($product_id);
    }
    
    // register admin pages
    public function add_admin_menu() {
        add_menu_page(
            'QR Code Manager',
            'QR Checkout',
            'manage_options',
            'wc-qr-manager',
            array($this, 'qr_manager_page'),
            'dashicons-smartphone',
            56
        );
        
        // submenu for individual product qr management (hidden from menu)
        add_submenu_page(
            null,
            'QR Code Details',
            'QR Code Details',
            'manage_options',
            'wc-qr-code-details',
            array($this, 'qr_code_details_page')
        );
    }
    
    /**
     * Enqueue admin scripts
     */
    public function admin_scripts($hook) {
        // Enqueue media library on product edit pages
        global $post_type;
        if ('product' === $post_type) {
            wp_enqueue_media();
        }
    }
    
    /**
     * QR Manager Page (List all products with QR codes)
     */
    public function qr_manager_page() {
        // Handle bulk QR generation
        if (isset($_POST['generate_all_qr_codes']) && check_admin_referer('wc_qr_generate_all', 'wc_qr_nonce')) {
            $this->generate_all_qr_codes();
        }
        
        // Get pagination
        $paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = 20;
        
        // sorting
        $orderby = isset($_GET['orderby']) ? sanitize_text_field($_GET['orderby']) : 'title';
        $order = isset($_GET['order']) && $_GET['order'] === 'desc' ? 'DESC' : 'ASC';
        
        $orderby_map = array(
            'title' => 'title',
            'sku' => 'meta_value',
            'status' => 'meta_value'
        );
        
        $orderby_query = isset($orderby_map[$orderby]) ? $orderby_map[$orderby] : 'title';
        
        $args = array(
            'post_type' => 'product',
            'posts_per_page' => $per_page,
            'paged' => $paged,
            'orderby' => $orderby_query,
            'order' => $order
        );
        
        if ($orderby === 'sku') {
            $args['meta_key'] = '_sku';
        }
        
        $query = new WP_Query($args);
        $total_products = $query->found_posts;
        $total_pages = $query->max_num_pages;
        
        $products_with_qr = 0;
        $products_without_qr = 0;
        
        ?>
        <div class="wrap">
            <h1>WooCommerce QR Code Manager</h1>
            <p>Manage QR codes for WooCommerce products. QR codes link directly to checkout with the product automatically added to cart.</p>
            
            <?php if (isset($_GET['generated'])): ?>
                <div class="notice notice-success is-dismissible">
                    <p><strong>QR codes generated successfully!</strong> <?php echo intval($_GET['generated']); ?> QR code(s) were created or updated.</p>
                </div>
            <?php endif; ?>
            
            <div style="background: #fff; padding: 15px 20px; border: 1px solid #ccd0d4; margin-bottom: 20px;">
                <form method="post" style="display: inline-block;">
                    <?php wp_nonce_field('wc_qr_generate_all', 'wc_qr_nonce'); ?>
                    <button type="submit" name="generate_all_qr_codes" value="1" class="button button-primary button-large" style="margin: 0 0 20px 0;" onclick="return confirm('Generate QR codes for all products? This may take a moment.');">
                        <span class="dashicons dashicons-update" style="vertical-align: text-top;"></span> Generate QR Codes for All Products
                    </button>
                    <p class="description" style="margin: 10px 0 0 0;">This will generate QR codes for all WooCommerce products (including those without QR codes).</p>
                </form>
            </div>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th width="60">Image</th>
                        <th class="sortable <?php echo $orderby === 'title' ? 'sorted' : ''; ?> <?php echo $orderby === 'title' ? strtolower($order) : 'asc'; ?>">
                            <a href="<?php echo add_query_arg(array('orderby' => 'title', 'order' => ($orderby === 'title' && $order === 'ASC') ? 'desc' : 'asc')); ?>">
                                <span>Product</span>
                                <span class="sorting-indicator"></span>
                            </a>
                        </th>
                        <th class="sortable <?php echo $orderby === 'sku' ? 'sorted' : ''; ?> <?php echo $orderby === 'sku' ? strtolower($order) : 'asc'; ?>">
                            <a href="<?php echo add_query_arg(array('orderby' => 'sku', 'order' => ($orderby === 'sku' && $order === 'ASC') ? 'desc' : 'asc')); ?>">
                                <span>SKU</span>
                                <span class="sorting-indicator"></span>
                            </a>
                        </th>
                        <th width="60">QR Code</th>
                        <th width="100">Status</th>
                        <th width="150">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    if (!$query->have_posts()):
                    ?>
                        <tr>
                            <td colspan="6" style="text-align: center; padding: 40px;">
                                <p>No products found.</p>
                                <p><a href="<?php echo admin_url('edit.php?post_type=product'); ?>" class="button">Go to Products</a></p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php while ($query->have_posts()): $query->the_post(); ?>
                            <?php 
                            $product_id = get_the_ID();
                            $product = wc_get_product($product_id);
                            $qr_url = get_post_meta($product_id, '_qr_code_url', true);
                            $manager_url = admin_url('admin.php?page=wc-qr-code-details&product_id=' . $product_id);
                            $thumbnail_id = $product->get_image_id();
                            
                            if ($qr_url) {
                                $products_with_qr++;
                            } else {
                                $products_without_qr++;
                            }
                            ?>
                            <tr>
                                <td>
                                    <?php if ($thumbnail_id): ?>
                                        <?php echo wp_get_attachment_image($thumbnail_id, array(50, 50), false, array('style' => 'border-radius: 4px;')); ?>
                                    <?php else: ?>
                                        <div style="width: 50px; height: 50px; border: 1px solid #ddd; border-radius: 4px; background: #f5f5f5; display: flex; align-items: center; justify-content: center;">
                                            <span class="dashicons dashicons-format-image" style="color: #ccc; font-size: 20px;"></span>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong><?php echo esc_html($product->get_name()); ?></strong>
                                </td>
                                <td>
                                    <code><?php echo esc_html($product->get_sku()); ?></code>
                                </td>
                                <td>
                                    <?php if ($qr_url): ?>
                                        <a href="<?php echo esc_url($manager_url); ?>">
                                            <img src="<?php echo esc_url($qr_url); ?>" style="width: 50px; height: 50px; border: 2px solid #0073aa; border-radius: 4px;">
                                        </a>
                                    <?php else: ?>
                                        <div style="width: 50px; height: 50px; border: 2px dashed #ccc; border-radius: 4px; background: #f9f9f9; display: flex; align-items: center; justify-content: center;">
                                            <span class="dashicons dashicons-minus" style="color: #ccc; font-size: 20px;"></span>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($qr_url): ?>
                                        <span style="color: #46b450;">✓ Has QR</span>
                                    <?php else: ?>
                                        <span style="color: #dc3232;">○ No QR</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($qr_url): ?>
                                        <a href="<?php echo esc_url($manager_url); ?>" class="wc-qr-action-btn wc-qr-manage-btn" title="Manage QR Code & Generate Coupons">
                                            <span class="dashicons dashicons-admin-settings"></span>
                                        </a>
                                        <button type="button" class="wc-qr-action-btn wc-qr-regenerate-btn" data-product-id="<?php echo esc_attr($product_id); ?>" data-product-name="<?php echo esc_attr($product->get_name()); ?>" title="Regenerate QR Code">
                                            <span class="dashicons dashicons-update"></span>
                                        </button>
                                    <?php else: ?>
                                        <button type="button" class="wc-qr-action-btn wc-qr-generate-btn" data-product-id="<?php echo esc_attr($product_id); ?>" data-product-name="<?php echo esc_attr($product->get_name()); ?>" title="Generate QR Code">
                                            <span class="dashicons dashicons-plus-alt"></span>
                                        </button>
                                    <?php endif; ?>
                                    <a href="<?php echo admin_url('post.php?post=' . $product_id . '&action=edit'); ?>" class="wc-qr-action-btn wc-qr-edit-btn" title="Edit Product">
                                        <span class="dashicons dashicons-edit"></span>
                                    </a>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            
            <?php if ($total_pages > 1): ?>
                <div class="tablenav bottom">
                    <div class="tablenav-pages">
                        <?php
                        echo paginate_links(array(
                            'base' => add_query_arg('paged', '%#%'),
                            'format' => '',
                            'prev_text' => '&laquo; Previous',
                            'next_text' => 'Next &raquo;',
                            'total' => $total_pages,
                            'current' => $paged
                        ));
                        ?>
                    </div>
                </div>
            <?php endif; ?>
            
            <div style="margin-top: 20px; padding: 15px; background: #f0f0f1; border-radius: 4px;">
                <p style="margin: 0;"><strong>Total Products:</strong> <?php echo $total_products; ?> | <strong>With QR Codes:</strong> <span style="color: #46b450;"><?php echo $products_with_qr; ?></span> | <strong>Without QR Codes:</strong> <span style="color: #dc3232;"><?php echo $products_without_qr; ?></span></p>
            </div>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            // Handle Generate QR button
            $('.wc-qr-generate-btn').on('click', function() {
                var btn = $(this);
                var productId = btn.data('product-id');
                var productName = btn.data('product-name');
                var row = btn.closest('tr');
                
                if (!confirm('Generate QR code for "' + productName + '"?')) {
                    return;
                }
                
                btn.prop('disabled', true).html('<span class="dashicons dashicons-update" style="animation: rotation 1s linear infinite;"></span>');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'wc_qr_generate_single',
                        product_id: productId,
                        nonce: '<?php echo wp_create_nonce('wc_qr_generate_single'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            // Reload the page to show the new QR code
                            location.reload();
                        } else {
                            alert('Error: ' + response.data.message);
                            btn.prop('disabled', false).html('<span class="dashicons dashicons-plus-alt"></span>');
                        }
                    },
                    error: function() {
                        alert('Error generating QR code');
                        btn.prop('disabled', false).html('<span class="dashicons dashicons-plus-alt"></span>');
                    }
                });
            });
            
            // Handle Regenerate QR button
            $('.wc-qr-regenerate-btn').on('click', function() {
                var btn = $(this);
                var productId = btn.data('product-id');
                var productName = btn.data('product-name');
                
                if (!confirm('Regenerate QR code for "' + productName + '"? The old QR code will be deleted.')) {
                    return;
                }
                
                btn.prop('disabled', true).html('<span class="dashicons dashicons-update" style="animation: rotation 1s linear infinite;"></span>');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'wc_qr_regenerate',
                        product_id: productId,
                        nonce: '<?php echo wp_create_nonce('wc_qr_regenerate_single'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            // Reload the page to show the new QR code
                            location.reload();
                        } else {
                            alert('Error: ' + response.data.message);
                            btn.prop('disabled', false).html('<span class="dashicons dashicons-update"></span>');
                        }
                    },
                    error: function() {
                        alert('Error regenerating QR code');
                        btn.prop('disabled', false).html('<span class="dashicons dashicons-update"></span>');
                    }
                });
            });
        });
        </script>
        
        <style>
        /* QR Manager Table */
        .wp-list-table td {
            vertical-align: middle;
        }
        
        /* QR Manager Action Buttons */
        .wc-qr-action-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 32px;
            height: 32px;
            padding: 0;
            margin: 0 2px;
            border: 1px solid #c3c4c7;
            border-radius: 4px;
            background: #fff;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
        }
        
        .wc-qr-action-btn:hover {
            border-color: #0073aa;
            background: #f6f7f7;
            transform: translateY(-1px);
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }
        
        .wc-qr-action-btn:active {
            transform: translateY(0);
            box-shadow: none;
        }
        
        .wc-qr-action-btn .dashicons {
            width: 18px;
            height: 18px;
            font-size: 18px;
            color: #50575e;
            margin: 0;
        }
        
        .wc-qr-action-btn:hover .dashicons {
            color: #0073aa;
        }
        
        .wc-qr-generate-btn {
            border-color: #2271b1;
            background: #2271b1;
        }
        
        .wc-qr-generate-btn .dashicons {
            color: #fff;
        }
        
        .wc-qr-generate-btn:hover {
            border-color: #135e96;
            background: #135e96;
        }
        
        .wc-qr-generate-btn:hover .dashicons {
            color: #fff;
        }
        
        .wc-qr-action-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        
        @keyframes rotation {
            from {
                transform: rotate(0deg);
            }
            to {
                transform: rotate(359deg);
            }
        }
        </style>
        <?php
        wp_reset_postdata();
    }
    
    /**
     * QR Code Details Page (Individual product)
     */
    public function qr_code_details_page() {
        // Get product ID from URL
        $product_id = isset($_GET['product_id']) ? intval($_GET['product_id']) : 0;
        
        if (!$product_id) {
            echo '<div class="wrap"><h1>QR Code Manager</h1>';
            echo '<div class="notice notice-error"><p>Invalid product ID.</p></div>';
            echo '</div>';
            return;
        }
        
        $product = wc_get_product($product_id);
        if (!$product) {
            echo '<div class="wrap"><h1>QR Code Manager</h1>';
            echo '<div class="notice notice-error"><p>Product not found.</p></div>';
            echo '</div>';
            return;
        }
        
        $qr_url = get_post_meta($product_id, '_qr_code_url', true);
        $download_code = get_post_meta($product_id, '_qr_download_code', true);
        $download_link = get_post_meta($product_id, '_qr_download_link', true);
        $product_sku = $product->get_sku();
        $checkout_url = wc_get_checkout_url();
        
        // Check if custom URL exists, otherwise use SKU-based URL
        $custom_url = get_post_meta($product_id, '_qr_custom_url', true);
        if (!empty($custom_url)) {
            $scan_url = $custom_url;
        } else {
            $scan_url = !empty($product_sku) ? add_query_arg('sku', urlencode($product_sku), $checkout_url) : '';
        }
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html($product->get_name()); ?> - QR Code Manager</h1>
            
            <div style="max-width: 1200px;">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px; margin-top: 20px;">
                    
                    <!-- QR Code Section -->
                    <div style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                        <h2 style="margin-top: 0;">QR Code</h2>
                        
                        <?php if ($qr_url): ?>
                            <div style="text-align: center; margin: 20px 0;">
                                <img src="<?php echo esc_url($qr_url); ?>?t=<?php echo time(); ?>" alt="QR Code" style="max-width: 100%; height: auto; border: 1px solid #ddd; padding: 20px; background: #fff;">
                            </div>
                            
                            <div id="qr-regenerate-status" style="margin: 15px 0;"></div>
                            
                            <p style="text-align: center;">
                                <a href="<?php echo esc_url($qr_url); ?>" class="button button-primary button-large" download="qr-<?php echo esc_attr($product_sku); ?>.svg" style="margin: 5px;">
                                    <span class="dashicons dashicons-download" style="vertical-align: text-top;"></span> Download SVG
                                </a>
                                <button type="button" id="regenerate-qr-btn" class="button button-secondary button-large" style="margin: 5px;" data-product-id="<?php echo esc_attr($product_id); ?>">
                                    <span class="dashicons dashicons-update" style="vertical-align: text-top;"></span> Regenerate QR Code
                                </button>
                            </p>
                            
                            <?php if ($scan_url): ?>
                            <div style="background: #f0f0f1; padding: 15px; border-radius: 4px; margin-top: 20px;">
                                <label for="qr-scan-url" style="display: block; margin-bottom: 8px; font-size: 12px; font-weight: 600; color: #1d2327;">Scans to:</label>
                                <div style="display: flex; gap: 8px; margin-bottom: 10px;">
                                    <input type="text" id="qr-scan-url" value="<?php echo esc_attr($scan_url); ?>" readonly style="flex: 1; width: 0; padding: 8px 12px; font-family: monospace; font-size: 12px; border: 1px solid #8c8f94; border-radius: 4px; background: #e9ecef; color: #495057; cursor: not-allowed; box-sizing: border-box;" data-default-url="<?php echo esc_attr(!empty($product_sku) ? add_query_arg('sku', urlencode($product_sku), $checkout_url) : ''); ?>" data-checkout-url="<?php echo esc_attr($checkout_url); ?>">
                                    
                                    <select id="sku-select" style="flex: 1; width: 0; min-width: 0; padding: 8px 12px; font-size: 12px; border: 1px solid #8c8f94; border-radius: 4px; display: none; box-sizing: border-box;">
                                        <option value="">-- Select a product --</option>
                                        <?php
                                        $args = array(
                                            'post_type' => 'product',
                                            'posts_per_page' => -1,
                                            'orderby' => 'title',
                                            'order' => 'ASC',
                                            'post_status' => 'publish'
                                        );
                                        $products = get_posts($args);
                                        foreach ($products as $prod) {
                                            $prod_obj = wc_get_product($prod->ID);
                                            $prod_sku = $prod_obj->get_sku();
                                            if (!empty($prod_sku)) {
                                                echo '<option value="' . esc_attr($prod_sku) . '">' . esc_html($prod->post_title) . ' (SKU: ' . esc_html($prod_sku) . ')</option>';
                                            }
                                        }
                                        ?>
                                    </select>
                                    
                                    <button type="button" id="open-url-btn" class="button" style="padding: 0 12px;" title="Open URL in new tab">
                                        <span class="dashicons dashicons-external" style="margin-top: 3px; vertical-align: text-bottom;"></span>
                                    </button>
                                    <button type="button" id="select-sku-btn" class="button" style="padding: 0 12px; display: none;" title="Select Product SKU">
                                        <span class="dashicons dashicons-list-view" style="margin-top: 3px; vertical-align: text-bottom;"></span>
                                    </button>
                                </div>
                                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 8px;">
                                    <button type="button" id="toggle-lock-btn" class="button" style="display: flex; align-items: center; justify-content: center; gap: 5px;">
                                        <span class="dashicons dashicons-lock" style="margin-top: 3px;"></span> <span>Unlock to Edit</span>
                                    </button>
                                    <button type="button" id="reset-url-btn" class="button" style="display: flex; align-items: center; justify-content: center; gap: 5px;" disabled>
                                        <span class="dashicons dashicons-image-rotate" style="margin-top: 3px;"></span> <span>Reset to Default</span>
                                    </button>
                                </div>
                                <p id="url-help-text" style="margin: 10px 0 0 0; font-size: 11px; color: #646970;"><em>Click "Unlock to Edit" to customize the QR code destination</em></p>
                            </div>
                            <?php endif; ?>
                        <?php else: ?>
                            <div class="notice notice-warning inline">
                                <p>No QR code generated yet. Please add a QR code URL in the product settings.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Coupon Code Generator Section -->
                    <div style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                        <h2 style="margin-top: 0;">Coupon Code Generator</h2>
                        
                        <p>Generate unique coupon codes that customers will enter at checkout to complete their purchase and receive the download.</p>
                        
                        <?php
                        $existing_coupons = $this->get_product_coupons($product_id);
                        if (!empty($existing_coupons)):
                        ?>
                        <div style="margin: 0 0 20px 0;">
                            <h3 style="margin: 0 0 10px 0; font-size: 14px;">Existing Coupons:</h3>
                            <div style="background: #fff; border: 1px solid #ddd; border-radius: 4px; max-height: 300px; overflow-y: auto;">
                                <table style="width: 100%; border-collapse: collapse;">
                                    <thead style="background: #f9f9f9; position: sticky; top: 0;">
                                        <tr>
                                            <th style="padding: 8px; text-align: left; border-bottom: 2px solid #ddd; font-size: 12px;">Code</th>
                                            <th style="padding: 8px; text-align: center; border-bottom: 2px solid #ddd; font-size: 12px;">Status</th>
                                            <th style="padding: 8px; text-align: center; border-bottom: 2px solid #ddd; font-size: 12px; width: 60px;">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($existing_coupons as $coupon): ?>
                                        <tr class="coupon-row" data-coupon-id="<?php echo esc_attr($coupon['id']); ?>">
                                            <td style="padding: 8px; border-bottom: 1px solid #eee; font-family: monospace; font-size: 13px;"><?php echo esc_html($coupon['code']); ?></td>
                                            <td style="padding: 8px; border-bottom: 1px solid #eee; text-align: center;">
                                                <?php if ($coupon['status'] === 'used'): ?>
                                                    <span style="color: #dc3232; font-size: 11px;">✗ Used</span>
                                                <?php elseif ($coupon['status'] === 'expired'): ?>
                                                    <span style="color: #999; font-size: 11px;">⊘ Expired</span>
                                                <?php else: ?>
                                                    <span style="color: #46b450; font-size: 11px;">✓ Active</span>
                                                <?php endif; ?>
                                            </td>
                                            <td style="padding: 8px; border-bottom: 1px solid #eee; text-align: center;">
                                                <button type="button" class="delete-coupon-btn" data-coupon-id="<?php echo esc_attr($coupon['id']); ?>" data-code="<?php echo esc_attr($coupon['code']); ?>" style="background: none; border: none; color: #dc3232; cursor: pointer; padding: 4px; font-size: 16px;" title="Delete coupon">
                                                    <span class="dashicons dashicons-trash"></span>
                                                </button>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <p style="margin: 8px 0 0 0; font-size: 11px; color: #646970;"><em><?php echo count($existing_coupons); ?> total coupon(s)</em></p>
                        </div>
                        <?php endif; ?>
                        
                        <div style="margin: 20px 0;">
                            <label style="display: block; margin-bottom: 5px; font-weight: 600;">Generate New Codes:</label>
                            <div id="coupon-codes-list" style="background: #f9f9f9; border: 1px solid #ddd; padding: 15px; min-height: 100px; max-height: 200px; overflow-y: auto; border-radius: 4px; font-family: monospace; font-size: 14px; line-height: 2;">
                                <em style="color: #646970; font-family: system-ui;">Click "Generate Codes" to create new unique coupon codes...</em>
                            </div>
                        </div>
                        
                        <div style="margin: 20px 0;">
                            <label for="code-count" style="display: block; margin-bottom: 5px; font-weight: 600;">Number of codes to generate:</label>
                            <input type="number" id="code-count" value="10" min="1" max="100" style="width: 100px; padding: 6px 10px;">
                        </div>
                        
                        <p>
                            <button type="button" id="generate-codes-btn" class="button button-primary button-large" style="margin-right: 10px;">
                                <span class="dashicons dashicons-tickets-alt" style="vertical-align: text-top;"></span> Generate Codes
                            </button>
                            <button type="button" id="copy-codes-btn" class="button button-secondary button-large" disabled>
                                <span class="dashicons dashicons-clipboard" style="vertical-align: text-top;"></span> Copy All
                            </button>
                        </p>
                        
                        <div style="background: #f0f6fc; border-left: 4px solid #0073aa; padding: 12px; margin-top: 20px;">
                            <p style="margin: 0; font-size: 13px;"><strong>Note:</strong> Generated coupons are automatically created in WooCommerce and can only be used once per code.</p>
                        </div>
                    </div>
                    
                </div>
                
                <p style="margin-top: 20px;">
                    <a href="<?php echo admin_url('post.php?post=' . $product_id . '&action=edit'); ?>" class="button">
                        ← Back to Product
                    </a>
                    <a href="<?php echo admin_url('admin.php?page=wc-qr-manager'); ?>" class="button">
                        View All QR Codes
                    </a>
                </p>
            </div>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            let generatedCodes = [];
            
            $('#generate-codes-btn').on('click', function() {
                const count = parseInt($('#code-count').val()) || 10;
                const btn = $(this);
                const productId = <?php echo $product_id; ?>;
                
                btn.prop('disabled', true).html('<span class="dashicons dashicons-update" style="animation: rotation 1s linear infinite; vertical-align: text-top;"></span> Generating...');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'wc_qr_generate_coupons',
                        product_id: productId,
                        count: count,
                        nonce: '<?php echo wp_create_nonce('wc_qr_generate_coupons_' . $product_id); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            generatedCodes = response.data.codes;
                            let html = '';
                            generatedCodes.forEach(function(code, index) {
                                html += '<div style="padding: 4px 0;">' + (index + 1) + '. ' + code + '</div>';
                            });
                            $('#coupon-codes-list').html(html);
                            $('#copy-codes-btn').prop('disabled', false);
                            setTimeout(function() {
                                location.reload();
                            }, 2000);
                        } else {
                            alert('Error: ' + response.data.message);
                        }
                        btn.prop('disabled', false).html('<span class="dashicons dashicons-tickets-alt" style="vertical-align: text-top;"></span> Generate Codes');
                    },
                    error: function() {
                        alert('Error generating coupons');
                        btn.prop('disabled', false).html('<span class="dashicons dashicons-tickets-alt" style="vertical-align: text-top;"></span> Generate Codes');
                    }
                });
            });
            
            $('#copy-codes-btn').on('click', function() {
                const text = generatedCodes.join('\n');
                
                // Create temporary textarea
                const $temp = $('<textarea>');
                $('body').append($temp);
                $temp.val(text).select();
                document.execCommand('copy');
                $temp.remove();
                
                // Show feedback
                const originalText = $(this).html();
                $(this).html('<span class="dashicons dashicons-yes" style="vertical-align: text-top;"></span> Copied!');
                setTimeout(() => {
                    $(this).html(originalText);
                }, 2000);
            });
            
            $('.delete-coupon-btn').on('click', function() {
                var btn = $(this);
                var couponId = btn.data('coupon-id');
                var code = btn.data('code');
                var row = btn.closest('tr');
                
                if (!confirm('Delete coupon "' + code + '"? This cannot be undone.')) {
                    return;
                }
                
                btn.prop('disabled', true).css('opacity', '0.5');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'wc_qr_delete_coupon',
                        coupon_id: couponId,
                        nonce: '<?php echo wp_create_nonce('wc_qr_delete_coupon'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            row.fadeOut(300, function() {
                                $(this).remove();
                            });
                        } else {
                            alert('Error: ' + response.data.message);
                            btn.prop('disabled', false).css('opacity', '1');
                        }
                    },
                    error: function() {
                        alert('Error deleting coupon');
                        btn.prop('disabled', false).css('opacity', '1');
                    }
                });
            });
            
            $('#regenerate-qr-btn').on('click', function() {
                var btn = $(this);
                var productId = btn.data('product-id');
                var statusDiv = $('#qr-regenerate-status');
                
                if (!confirm('Regenerate QR code for this product? The old QR code will be deleted.')) {
                    return;
                }
                
                btn.prop('disabled', true).html('<span class="dashicons dashicons-update" style="vertical-align: text-top; animation: rotation 1s linear infinite;"></span> Regenerating...');
                statusDiv.html('<div style="background: #fff3cd; border: 1px solid #ffeaa7; color: #856404; padding: 12px; border-radius: 4px; text-align: center;">⏳ Regenerating QR code...</div>');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'wc_qr_regenerate',
                        product_id: productId,
                        nonce: '<?php echo wp_create_nonce('wc_qr_regenerate_' . $product_id); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            statusDiv.html('<div style="background: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: 12px; border-radius: 4px; text-align: center;">✓ ' + response.data.message + '</div>');
                            // Reload the page after 1 second to show new QR code
                            setTimeout(function() {
                                location.reload();
                            }, 1000);
                        } else {
                            statusDiv.html('<div style="background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; padding: 12px; border-radius: 4px; text-align: center;">✗ ' + response.data.message + '</div>');
                            btn.prop('disabled', false).html('<span class="dashicons dashicons-update" style="vertical-align: text-top;"></span> Regenerate QR Code');
                        }
                    },
                    error: function() {
                        statusDiv.html('<div style="background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; padding: 12px; border-radius: 4px; text-align: center;">✗ Error regenerating QR code</div>');
                        btn.prop('disabled', false).html('<span class="dashicons dashicons-update" style="vertical-align: text-top;"></span> Regenerate QR Code');
                    }
                });
            });
            
            $('#open-url-btn').on('click', function() {
                var url = $('#qr-scan-url').val().trim();
                if (url) {
                    window.open(url, '_blank');
                }
            });
            
            var originalUrl = $('#qr-scan-url').val();
            
            $('#toggle-lock-btn').on('click', function() {
                var btn = $(this);
                var input = $('#qr-scan-url');
                var resetBtn = $('#reset-url-btn');
                var helpText = $('#url-help-text');
                var icon = btn.find('.dashicons');
                var text = btn.find('span:last');
                var openUrlBtn = $('#open-url-btn');
                var selectSkuBtn = $('#select-sku-btn');
                var skuSelect = $('#sku-select');
                var statusDiv = $('#qr-regenerate-status');
                var productId = <?php echo $product_id; ?>;
                
                if (input.prop('readonly')) {
                    originalUrl = input.val();
                    input.prop('readonly', false).css({'background': '#fff', 'cursor': 'text'});
                    icon.removeClass('dashicons-lock').addClass('dashicons-unlock');
                    text.text('Lock');
                    resetBtn.prop('disabled', false);
                    openUrlBtn.hide();
                    selectSkuBtn.show();
                    helpText.html('<em>Edit the URL above or select a product, then click "Lock" to save</em>');
                } else {
                    var currentUrl = input.val().trim();
                    
                    if (currentUrl !== originalUrl) {
                        btn.prop('disabled', true);
                        icon.removeClass('dashicons-unlock').addClass('dashicons-update');
                        text.text('Saving...');
                        statusDiv.html('<div style="background: #fff3cd; border: 1px solid #ffeaa7; color: #856404; padding: 12px; border-radius: 4px; text-align: center;">⏳ Saving and regenerating QR code...</div>');
                        
                        $.ajax({
                            url: ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'wc_qr_save_custom_url',
                                product_id: productId,
                                custom_url: currentUrl,
                                nonce: '<?php echo wp_create_nonce('wc_qr_save_custom_url_' . $product_id); ?>'
                            },
                            success: function(response) {
                                if (response.success) {
                                    statusDiv.html('<div style="background: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: 12px; border-radius: 4px; text-align: center;">✓ ' + response.data.message + '</div>');
                                    setTimeout(function() {
                                        location.reload();
                                    }, 1000);
                                } else {
                                    statusDiv.html('<div style="background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; padding: 12px; border-radius: 4px; text-align: center;">✗ ' + response.data.message + '</div>');
                                    btn.prop('disabled', false);
                                    icon.removeClass('dashicons-update').addClass('dashicons-unlock');
                                    text.text('Lock');
                                }
                            },
                            error: function() {
                                statusDiv.html('<div style="background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; padding: 12px; border-radius: 4px; text-align: center;">✗ Error saving custom URL</div>');
                                btn.prop('disabled', false);
                                icon.removeClass('dashicons-update').addClass('dashicons-unlock');
                                text.text('Lock');
                            }
                        });
                    } else {
                        input.val(originalUrl);
                        input.prop('readonly', true).css({'background': '#e9ecef', 'cursor': 'not-allowed'});
                        icon.removeClass('dashicons-unlock').addClass('dashicons-lock');
                        text.text('Unlock to Edit');
                        resetBtn.prop('disabled', true);
                        openUrlBtn.show();
                        selectSkuBtn.hide();
                        if (skuSelect.is(':visible')) {
                            skuSelect.css('display', 'none');
                            input.css('display', 'block');
                        }
                        helpText.html('<em>Click "Unlock to Edit" to customize the QR code destination</em>');
                    }
                }
            });
            
            $('#reset-url-btn').on('click', function() {
                var input = $('#qr-scan-url');
                var defaultUrl = input.data('default-url');
                if (confirm('Reset to default URL? This will use the SKU-based checkout URL.')) {
                    input.val(defaultUrl);
                }
            });
            
            $('#select-sku-btn').on('click', function() {
                var input = $('#qr-scan-url');
                var skuSelect = $('#sku-select');
                
                if (skuSelect.is(':visible')) {
                    skuSelect.css('display', 'none');
                    input.css('display', 'block');
                    skuSelect.val('');
                } else {
                    input.css('display', 'none');
                    skuSelect.css('display', 'block');
                    skuSelect.focus();
                }
            });
            
            $('#sku-select').on('change', function() {
                var selectedSku = $(this).val();
                var input = $('#qr-scan-url');
                var skuSelect = $(this);
                var checkoutUrl = input.data('checkout-url');
                
                if (selectedSku) {
                    var newUrl = checkoutUrl + '?sku=' + encodeURIComponent(selectedSku);
                    input.val(newUrl);
                    skuSelect.css('display', 'none');
                    input.css('display', 'block');
                    skuSelect.val('');
                }
            });
        });
        </script>
        
        <style>
        .dashicons {
            font-size: 16px;
            width: 16px;
            height: 16px;
        }
        @keyframes rotation {
            from {
                transform: rotate(0deg);
            }
            to {
                transform: rotate(359deg);
            }
        }
        </style>
        <?php
    }
    
    /**
     * Add QR code metabox to product edit pages
     */
    public function add_qr_code_metabox() {
        add_meta_box(
            'wc_qr_code_metabox',
            'QR Code for Checkout',
            array($this, 'render_qr_code_metabox'),
            'product',
            'side',
            'default'
        );
    }
    
    /**
     * Render QR code metabox
     */
    public function render_qr_code_metabox($post) {
        $product = wc_get_product($post->ID);
        $qr_url = get_post_meta($post->ID, '_qr_code_url', true);
        $product_sku = $product ? $product->get_sku() : '';
        
        if ($qr_url) {
            $manager_url = admin_url('admin.php?page=wc-qr-code-details&product_id=' . $post->ID);
            echo '<div style="text-align: center;">';
            echo '<a href="' . esc_url($manager_url) . '" style="display: inline-block; border: 2px solid #0073aa; border-radius: 4px; padding: 10px; background: #fff; transition: all 0.2s;" onmouseover="this.style.borderColor=\'#005a87\'; this.style.boxShadow=\'0 2px 8px rgba(0,0,0,0.15)\';" onmouseout="this.style.borderColor=\'#0073aa\'; this.style.boxShadow=\'none\';">';
            $qr_url_nocache = add_query_arg('t', time(), $qr_url);
            echo '<img src="' . esc_url($qr_url_nocache) . '" alt="QR Code" style="max-width: 100%; height: auto; display: block;">';
            echo '</a>';
            echo '<p style="display: none; margin-top: 10px;">';
            echo '<a href="' . esc_url($manager_url) . '" class="button button-primary">Manage QR & Generate Coupons</a>';
            echo '</p>';
            echo '</div>';
        } else {
            echo '<p>No QR code set for this product.</p>';
            echo '<p class="description">QR codes can be managed through the <a href="' . admin_url('admin.php?page=wc-qr-manager') . '">QR Code Manager</a>.</p>';
        }
    }
    
    // save qr settings from metabox
    public function save_qr_download_settings($post_id) {
        if (!isset($_POST['qr_download_nonce']) || !wp_verify_nonce($_POST['qr_download_nonce'], 'save_qr_download_settings')) {
            return;
        }
        
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        
        // Save download code
        if (isset($_POST['qr_download_code'])) {
            update_post_meta($post_id, '_qr_download_code', sanitize_text_field($_POST['qr_download_code']));
        }
        
        // Save download link
        if (isset($_POST['qr_download_link'])) {
            update_post_meta($post_id, '_qr_download_link', esc_url_raw($_POST['qr_download_link']));
        }
    }
    
    /**
     * AJAX handler to regenerate QR code
     */
    public function ajax_regenerate_qr() {
        // Verify nonce - support both detail page and manager page nonces
        $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
        
        $nonce_valid = false;
        if (check_ajax_referer('wc_qr_regenerate_' . $product_id, 'nonce', false)) {
            $nonce_valid = true;
        } elseif (check_ajax_referer('wc_qr_regenerate_single', 'nonce', false)) {
            $nonce_valid = true;
        }
        
        if (!$product_id || !$nonce_valid) {
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }
        
        // Check permissions
        if (!current_user_can('manage_woocommerce') && !current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
            return;
        }
        
        // Delete old QR code file if exists
        $old_qr_url = get_post_meta($product_id, '_qr_code_url', true);
        if ($old_qr_url) {
            $upload_dir = wp_upload_dir();
            $old_file_path = str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $old_qr_url);
            if (file_exists($old_file_path)) {
                @unlink($old_file_path);
            }
        }
        
        // Clean up old meta
        delete_post_meta($product_id, '_qr_code_url');
        
        // Generate new QR code
        $result = $this->generate_product_qr_code($product_id);
        
        if ($result) {
            wp_send_json_success(array('message' => 'QR code regenerated successfully!'));
        } else {
            wp_send_json_error(array('message' => 'Failed to generate QR code'));
        }
    }
    
    /**
     * AJAX handler for generating single QR code
     */
    public function ajax_generate_single_qr() {
        // Verify nonce
        check_ajax_referer('wc_qr_generate_single', 'nonce');
        
        $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
        
        if (!$product_id) {
            wp_send_json_error(array('message' => 'Invalid product ID'));
            return;
        }
        
        // Check permissions
        if (!current_user_can('manage_woocommerce') && !current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
            return;
        }
        
        // Generate QR code
        $result = $this->generate_product_qr_code($product_id);
        
        if ($result) {
            wp_send_json_success(array('message' => 'QR code generated successfully!'));
        } else {
            wp_send_json_error(array('message' => 'Failed to generate QR code'));
        }
    }
    
    /**
     * AJAX handler to verify download code
     */
    public function ajax_verify_download_code() {
        $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
        $entered_code = isset($_POST['code']) ? sanitize_text_field($_POST['code']) : '';
        
        if (!$product_id || !$entered_code) {
            wp_send_json_error(array('message' => 'Invalid request'));
            return;
        }
        
        // Get product to retrieve SKU
        $product = wc_get_product($product_id);
        
        if (!$product) {
            wp_send_json_error(array('message' => 'Product not found'));
            return;
        }
        
        $correct_code = get_post_meta($product_id, '_qr_download_code', true);
        $product_sku = $product->get_sku();
        
        if (empty($correct_code)) {
            wp_send_json_error(array('message' => 'Download code not configured'));
            return;
        }
        
        if (empty($product_sku)) {
            wp_send_json_error(array('message' => 'Product SKU not found'));
            return;
        }
        
        if ($entered_code === $correct_code) {
            // Build checkout URL with SKU in query string
            $checkout_url = wc_get_checkout_url();
            $checkout_url = add_query_arg('sku', urlencode($product_sku), $checkout_url);
            
            wp_send_json_success(array(
                'message' => 'Code verified!',
                'checkout_url' => $checkout_url,
                'sku' => $product_sku
            ));
        } else {
            wp_send_json_error(array('message' => 'Invalid code. Please try again.'));
        }
    }
    
    /**
     * AJAX handler to save custom URL and regenerate QR
     */
    public function ajax_save_custom_url() {
        $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
        $custom_url = isset($_POST['custom_url']) ? esc_url_raw($_POST['custom_url']) : '';
        
        // Verify nonce
        if (!check_ajax_referer('wc_qr_save_custom_url_' . $product_id, 'nonce', false)) {
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }
        
        if (!$product_id || !$custom_url) {
            wp_send_json_error(array('message' => 'Invalid request'));
            return;
        }
        
        // Check permissions
        if (!current_user_can('manage_woocommerce') && !current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
            return;
        }
        
        // Save custom URL
        update_post_meta($product_id, '_qr_custom_url', $custom_url);
        
        // Delete old QR code file
        $old_qr_url = get_post_meta($product_id, '_qr_code_url', true);
        if ($old_qr_url) {
            $upload_dir = wp_upload_dir();
            $old_file_path = str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $old_qr_url);
            if (file_exists($old_file_path)) {
                @unlink($old_file_path);
            }
        }
        
        delete_post_meta($product_id, '_qr_code_url');
        
        // Regenerate QR code with custom URL
        $result = $this->generate_product_qr_code($product_id, $custom_url);
        
        if ($result) {
            wp_send_json_success(array('message' => 'Custom URL saved and QR code regenerated!'));
        } else {
            wp_send_json_error(array('message' => 'Failed to regenerate QR code'));
        }
    }
    
    public function ajax_generate_coupons() {
        $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
        $count = isset($_POST['count']) ? intval($_POST['count']) : 10;
        
        if (!check_ajax_referer('wc_qr_generate_coupons_' . $product_id, 'nonce', false)) {
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }
        
        if (!$product_id || $count < 1 || $count > 100) {
            wp_send_json_error(array('message' => 'Invalid request'));
            return;
        }
        
        if (!current_user_can('manage_woocommerce') && !current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
            return;
        }
        
        $product = wc_get_product($product_id);
        if (!$product) {
            wp_send_json_error(array('message' => 'Product not found'));
            return;
        }
        
        $codes = array();
        for ($i = 0; $i < $count; $i++) {
            $code = $this->generate_coupon_code();
            $coupon_id = $this->create_woo_coupon($code, $product_id);
            if ($coupon_id) {
                $codes[] = $code;
            }
        }
        
        if (count($codes) > 0) {
            wp_send_json_success(array('codes' => $codes, 'message' => count($codes) . ' coupons created'));
        } else {
            wp_send_json_error(array('message' => 'Failed to create coupons'));
        }
    }
    
    private function generate_coupon_code() {
        $letters = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $numbers = '0123456789';
        
        $code = '';
        for ($i = 0; $i < 3; $i++) {
            $code .= $letters[rand(0, strlen($letters) - 1)];
        }
        $code .= '-';
        for ($i = 0; $i < 4; $i++) {
            $code .= $numbers[rand(0, strlen($numbers) - 1)];
        }
        $code .= '-';
        for ($i = 0; $i < 3; $i++) {
            $code .= $letters[rand(0, strlen($letters) - 1)];
        }
        
        return $code;
    }
    
    private function create_woo_coupon($code, $product_id) {
        $coupon = array(
            'post_title' => $code,
            'post_content' => '',
            'post_status' => 'publish',
            'post_author' => get_current_user_id(),
            'post_type' => 'shop_coupon'
        );
        
        $coupon_id = wp_insert_post($coupon);
        
        if ($coupon_id) {
            update_post_meta($coupon_id, 'discount_type', 'percent');
            update_post_meta($coupon_id, 'coupon_amount', 100);
            update_post_meta($coupon_id, 'individual_use', 'yes');
            update_post_meta($coupon_id, 'product_ids', array($product_id));
            update_post_meta($coupon_id, 'usage_limit', 1);
            update_post_meta($coupon_id, 'usage_limit_per_user', 1);
            update_post_meta($coupon_id, 'limit_usage_to_x_items', 1);
            update_post_meta($coupon_id, 'free_shipping', 'no');
        }
        
        return $coupon_id;
    }
    
    public function ajax_delete_coupon() {
        $coupon_id = isset($_POST['coupon_id']) ? intval($_POST['coupon_id']) : 0;
        
        if (!check_ajax_referer('wc_qr_delete_coupon', 'nonce', false)) {
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }
        
        if (!$coupon_id) {
            wp_send_json_error(array('message' => 'Invalid coupon ID'));
            return;
        }
        
        if (!current_user_can('manage_woocommerce') && !current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
            return;
        }
        
        $result = wp_delete_post($coupon_id, true);
        
        if ($result) {
            wp_send_json_success(array('message' => 'Coupon deleted'));
        } else {
            wp_send_json_error(array('message' => 'Failed to delete coupon'));
        }
    }
    
    private function get_product_coupons($product_id) {
        global $wpdb;
        
        $coupons = array();
        
        $query = "SELECT p.ID, p.post_title 
                  FROM {$wpdb->posts} p
                  INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
                  WHERE p.post_type = 'shop_coupon'
                  AND p.post_status = 'publish'
                  AND pm.meta_key = 'product_ids'
                  AND pm.meta_value LIKE %s
                  ORDER BY p.post_date DESC";
        
        $results = $wpdb->get_results($wpdb->prepare($query, '%' . $wpdb->esc_like($product_id) . '%'));
        
        foreach ($results as $result) {
            $usage_count = get_post_meta($result->ID, 'usage_count', true);
            $usage_limit = get_post_meta($result->ID, 'usage_limit', true);
            $expiry_date = get_post_meta($result->ID, 'date_expires', true);
            
            $status = 'active';
            if ($usage_count && $usage_limit && $usage_count >= $usage_limit) {
                $status = 'used';
            } elseif ($expiry_date && $expiry_date < time()) {
                $status = 'expired';
            }
            
            $coupons[] = array(
                'id' => $result->ID,
                'code' => $result->post_title,
                'status' => $status
            );
        }
        
        return $coupons;
    }
    
    private function generate_product_qr_code($product_id, $custom_url = '') {
        $product = wc_get_product($product_id);
        if (!$product) {
            return false;
        }
        
        // Use custom URL if provided, otherwise check for saved custom URL, or use default SKU-based URL
        if (!empty($custom_url)) {
            $product_url = $custom_url;
        } else {
            $saved_custom_url = get_post_meta($product_id, '_qr_custom_url', true);
            if (!empty($saved_custom_url)) {
                $product_url = $saved_custom_url;
            } else {
                // Get product SKU
                $product_sku = $product->get_sku();
                if (empty($product_sku)) {
                    return false;
                }
                
                // Build checkout URL with SKU parameter
                $checkout_url = wc_get_checkout_url();
                $product_url = add_query_arg('sku', urlencode($product_sku), $checkout_url);
            }
        }
        
        // Delete old QR code file if exists
        $old_qr_url = get_post_meta($product_id, '_qr_code_url', true);
        if ($old_qr_url) {
            $upload_dir = wp_upload_dir();
            $old_file_path = str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $old_qr_url);
            if (file_exists($old_file_path)) {
                @unlink($old_file_path);
            }
        }
        
        // Clean up old attachment ID meta if it exists
        delete_post_meta($product_id, '_qr_code_attachment_id');
        
        // QR Server API - generate SVG format for scalability
        $qr_url = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&format=svg&data=' . urlencode($product_url);
        
        // Download the QR code image
        $response = wp_remote_get($qr_url, array('timeout' => 30));
        if (is_wp_error($response)) {
            return false;
        }
        
        $image_data = wp_remote_retrieve_body($response);
        
        if (empty($image_data)) {
            return false;
        }
        
        // Create upload directory if needed
        $upload_dir = wp_upload_dir();
        $qr_dir = $upload_dir['basedir'] . '/wooqrcheckout';
        if (!file_exists($qr_dir)) {
            wp_mkdir_p($qr_dir);
        }
        
        // Save the file as SVG
        $filename = 'qr-' . $product->get_sku() . '-' . $product_id . '.svg';
        $file_path = $qr_dir . '/' . $filename;
        
        $saved = file_put_contents($file_path, $image_data);
        if ($saved === false) {
            return false;
        }
        
        // Store just the URL, don't create a media library attachment
        $file_url = $upload_dir['baseurl'] . '/wooqrcheckout/' . $filename;
        
        // Save QR code URL in product meta (no attachment needed)
        update_post_meta($product_id, '_qr_code_url', $file_url);
        
        return true;
    }
    
    /**
     * Generate QR codes for all products
     */
    private function generate_all_qr_codes() {
        // Get all products
        $args = array(
            'post_type' => 'product',
            'posts_per_page' => -1,
            'fields' => 'ids'
        );
        
        $product_ids = get_posts($args);
        $generated_count = 0;
        
        foreach ($product_ids as $product_id) {
            $product = wc_get_product($product_id);
            
            // Skip products without SKU
            if (!$product || empty($product->get_sku())) {
                continue;
            }
            
            // Generate QR code
            if ($this->generate_product_qr_code($product_id)) {
                $generated_count++;
            }
        }
        
        // Redirect with success message
        wp_redirect(add_query_arg('generated', $generated_count, admin_url('admin.php?page=wc-qr-manager')));
        exit;
    }
}
