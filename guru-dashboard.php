<?php
/**
 * Plugin Name: Guru Dashboard
 * Description: Advanced user dashboard system with visual menu builder
 * Version: 3.0.0
 * Author: Alireza Fatemi
 * Author URI: https://alirezafatemi.ir
 * Text Domain: guru-dashboard
 * Requires at least: 5.0
 * Tested up to: 6.8
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) exit;

define('GURU_DASHBOARD_SETTINGS', 'guru_dashboard_settings');
define('GURU_DASHBOARD_PLUGIN_DIR', plugin_dir_path(__FILE__));

final class Guru_Dashboard {
    private static $instance;
    private $options;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->options = get_option(GURU_DASHBOARD_SETTINGS, []);
        
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);

        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'settings_init']);
        add_action('admin_enqueue_scripts', [$this, 'admin_enqueue_assets']);
        add_action('admin_footer', [$this, 'admin_inline_scripts']);
        
        add_action('init', [$this, 'handle_profile_update']);
        add_action('template_redirect', [$this, 'handle_dashboard_redirects']);
        add_filter('get_avatar', [$this, 'custom_avatar'], 10, 5);
        add_action('login_enqueue_scripts', [$this, 'custom_login_style']);
        add_filter('login_headerurl', [$this, 'custom_login_logo_url']);
        add_filter('login_headertext', [$this, 'custom_login_logo_title']);
        
        if ($this->is_woocommerce_enabled()) {
            add_filter('woocommerce_get_endpoint_url', [$this, 'custom_wc_get_endpoint_url'], 10, 4);
        }

        add_shortcode('guru_user_dashboard', [$this, 'render_dashboard_shortcode']);
    }
    
    private function is_woocommerce_enabled() {
        return !empty($this->options['enable_woocommerce']) && 
               in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')));
    }
    
    public function activate() {
        $page_title = 'پنل کاربری';
        $dashboard_page = get_page_by_title($page_title);
        
        if (!$dashboard_page) {
            $page_id = wp_insert_post([
                'post_title'   => $page_title,
                'post_content' => '[guru_user_dashboard]',
                'post_status'  => 'publish',
                'post_author'  => 1,
                'post_type'    => 'page',
            ]);
            $this->options['dashboard_page_id'] = $page_id;
            update_option(GURU_DASHBOARD_SETTINGS, $this->options);
        } else {
             if (empty($this->options['dashboard_page_id'])) {
                $this->options['dashboard_page_id'] = $dashboard_page->ID;
                update_option(GURU_DASHBOARD_SETTINGS, $this->options);
             }
        }
        
        $current_options = get_option(GURU_DASHBOARD_SETTINGS, []);
        if (empty($current_options)) {
             update_option(GURU_DASHBOARD_SETTINGS, [
                'dashboard_page_id' => $dashboard_page ? $dashboard_page->ID : 0,
                'dashboard_content' => '',
                'primary_color' => '#0600C9',
                'enable_woocommerce' => false
            ]);
        }
        
        flush_rewrite_rules();
    }

    public function deactivate() {
        flush_rewrite_rules();
    }

    public function add_admin_menu() {
        add_menu_page(
            'Guru Dashboard',
            'Guru Dashboard',
            'manage_options',
            'guru_dashboard',
            [$this, 'settings_page_render'],
            'dashicons-dashboard',
            56
        );
    }
    
    public function settings_init() {
        register_setting('guruDashboardPage', GURU_DASHBOARD_SETTINGS, [$this, 'sanitize_settings']);
        
        add_settings_section('gd_general_section', 'تنظیمات عمومی پنل کاربری', null, 'guruDashboardPage');
        add_settings_field('gd_dashboard_page_id', 'صفحه پنل کاربری', [$this, 'dashboard_page_render'], 'guruDashboardPage', 'gd_general_section');
        add_settings_field('gd_login_logo_field', 'URL لوگوی صفحه ورود', [$this, 'login_logo_render'], 'guruDashboardPage', 'gd_general_section');
        add_settings_field('gd_primary_color_field', 'رنگ اصلی پنل', [$this, 'primary_color_render'], 'guruDashboardPage', 'gd_general_section');
        add_settings_field('gd_enable_woocommerce_field', 'فعال‌سازی ووکامرس', [$this, 'enable_woocommerce_render'], 'guruDashboardPage', 'gd_general_section');
        
        add_settings_section('gd_dashboard_content_section', 'محتوای پیشخوان پنل کاربری', null, 'guruDashboardPage');
        add_settings_field('gd_dashboard_content_field', 'محتوای سفارشی پیشخوان', [$this, 'dashboard_content_render'], 'guruDashboardPage', 'gd_dashboard_content_section');
        
        add_settings_section('gd_menu_builder_section', 'سازنده منوی سفارشی پنل کاربری', null, 'guruDashboardPage');
        add_settings_field('gd_menu_items_field', 'منوهای سفارشی', [$this, 'menu_builder_render'], 'guruDashboardPage', 'gd_menu_builder_section');
    }
    
    public function sanitize_settings($input) {
        $sanitized_input = [];
        
        $old_page_id = $this->options['dashboard_page_id'] ?? 0;
        $new_page_id = isset($input['dashboard_page_id']) ? absint($input['dashboard_page_id']) : 0;
        
        if ($new_page_id && $new_page_id !== $old_page_id) {
            if ($old_page_id) {
                $old_page = get_post($old_page_id);
                if ($old_page) {
                    $content = str_replace('[guru_user_dashboard]', '', $old_page->post_content);
                    wp_update_post(['ID' => $old_page_id, 'post_content' => $content]);
                }
            }
            
            $new_page = get_post($new_page_id);
            if ($new_page && strpos($new_page->post_content, '[guru_user_dashboard]') === false) {
                wp_update_post(['ID' => $new_page_id, 'post_content' => $new_page->post_content . "\n[guru_user_dashboard]"]);
            }
        }
        
        $sanitized_input['dashboard_page_id'] = $new_page_id;
        
        if (isset($input['login_logo_url'])) {
            $sanitized_input['login_logo_url'] = esc_url_raw($input['login_logo_url']);
        }
        
        if (isset($input['primary_color'])) {
            $sanitized_input['primary_color'] = sanitize_hex_color($input['primary_color']);
        }
        
        $sanitized_input['enable_woocommerce'] = isset($input['enable_woocommerce']) ? true : false;
        
        if (isset($input['dashboard_content'])) {
            $sanitized_input['dashboard_content'] = wp_kses_post($input['dashboard_content']);
        }
        
        $sanitized_input['menu_items'] = [];
        if (isset($input['menu_items']) && is_array($input['menu_items'])) {
            foreach ($input['menu_items'] as $item) {
                $clean_item = [];
                $clean_item['title'] = isset($item['title']) ? sanitize_text_field($item['title']) : '';
                $clean_item['slug'] = isset($item['slug']) ? sanitize_key($item['slug']) : '';
                $clean_item['icon'] = isset($item['icon']) ? sanitize_text_field($item['icon']) : '';
                $clean_item['type'] = isset($item['type']) ? sanitize_key($item['type']) : 'shortcode';
                $clean_item['content'] = isset($item['content']) ? wp_kses_post($item['content']) : '';
                $clean_item['roles'] = isset($item['roles']) && is_array($item['roles']) ? array_map('sanitize_key', $item['roles']) : [];
                $sanitized_input['menu_items'][] = $clean_item;
            }
        }
        
        return $sanitized_input;
    }

    public function dashboard_page_render() {
        $selected_page = $this->options['dashboard_page_id'] ?? 0;
        wp_dropdown_pages([
            'name' => GURU_DASHBOARD_SETTINGS . '[dashboard_page_id]',
            'selected' => $selected_page,
            'show_option_none' => '— انتخاب صفحه —',
            'option_none_value' => 0
        ]);
        echo '<p class="description">صفحه‌ای را انتخاب کنید تا شورت‌کد <code>[guru_user_dashboard]</code> به آن اضافه شود.</p>';
    }
    
    public function login_logo_render() { 
        $logo_url = $this->options['login_logo_url'] ?? '';
        echo '<div>';
        echo '<input type="text" id="gd_login_logo_url_field" name="'.GURU_DASHBOARD_SETTINGS.'[login_logo_url]" value="'.esc_attr($logo_url).'" class="regular-text">';
        echo ' <button type="button" class="button" id="gd-upload-logo-button">انتخاب از کتابخانه</button>';
        echo '</div>';
        echo '<div id="gd-logo-preview" style="margin-top:10px;">';
        if($logo_url) {
            echo '<img src="'.esc_url($logo_url).'" style="max-width:150px; height:auto; border:1px solid #ddd; padding:5px;">';
        }
        echo '</div>';
    }

    public function primary_color_render() {
        echo '<input type="text" name="'.GURU_DASHBOARD_SETTINGS.'[primary_color]" value="'.esc_attr($this->options['primary_color'] ?? '#0600C9').'" class="gd-color-picker">';
    }

    public function enable_woocommerce_render() {
        $enabled = !empty($this->options['enable_woocommerce']);
        echo '<label><input type="checkbox" name="'.GURU_DASHBOARD_SETTINGS.'[enable_woocommerce]" value="1" '.checked($enabled, true, false).'> فعال‌سازی تاریخچه خرید (نیاز به ووکامرس)</label>';
        echo '<p class="description">با فعال کردن این گزینه، تب "تاریخچه خرید" در پنل کاربری نمایش داده می‌شود.</p>';
    }

    public function dashboard_content_render() {
        wp_editor(
            $this->options['dashboard_content'] ?? '',
            'gd_dashboard_content_editor',
            [
                'textarea_name' => GURU_DASHBOARD_SETTINGS.'[dashboard_content]',
                'media_buttons' => true,
                'textarea_rows' => 10
            ]
        );
        echo '<p class="description">این محتوا در پیشخوان پنل کاربری نمایش داده می‌شود.</p>';
    }

    public function menu_builder_render() {
        $menu_items = $this->options['menu_items'] ?? [];
        global $wp_roles;
        $roles = $wp_roles->get_names();
        ?>
        <div id="gd-menu-builder">
            <div id="gd-menu-items-wrapper">
                <?php if (!empty($menu_items)): foreach ($menu_items as $index => $item): ?>
                <div class="gd-menu-item postbox closed">
                    <div class="gd-menu-item-handle handle">
                        <span class="dashicons dashicons-menu"></span>
                        <h3 class="hndle"><span><?php echo esc_html($item['title'] ?: 'آیتم جدید'); ?></span></h3>
                        <button type="button" class="button-link gd-remove-item"><span class="dashicons dashicons-trash"></span></button>
                    </div>
                    <div class="inside" style="display: none;">
                        <p>
                            <label>عنوان منو:</label>
                            <input type="text" class="widefat gd-item-title" data-field="title" name="<?php echo GURU_DASHBOARD_SETTINGS; ?>[menu_items][<?php echo $index; ?>][title]" value="<?php echo esc_attr($item['title']); ?>">
                        </p>
                        <p>
                            <label>Slug (شناسه انگلیسی):</label>
                            <input type="text" class="widefat" data-field="slug" name="<?php echo GURU_DASHBOARD_SETTINGS; ?>[menu_items][<?php echo $index; ?>][slug]" value="<?php echo esc_attr($item['slug']); ?>">
                        </p>
                        <p>
                            <label>کلاس آیکن (Dashicons):</label>
                            <input type="text" class="widefat" data-field="icon" name="<?php echo GURU_DASHBOARD_SETTINGS; ?>[menu_items][<?php echo $index; ?>][icon]" value="<?php echo esc_attr($item['icon']); ?>">
                        </p>
                        <p>
                            <label>نوع محتوا:</label>
                            <select class="widefat" data-field="type" name="<?php echo GURU_DASHBOARD_SETTINGS; ?>[menu_items][<?php echo $index; ?>][type]">
                                <option value="shortcode" <?php selected($item['type'] ?? 'shortcode', 'shortcode'); ?>>شورت‌کد</option>
                                <option value="html" <?php selected($item['type'] ?? '', 'html'); ?>>HTML</option>
                            </select>
                        </p>
                        <p>
                            <label>محتوا (شورت‌کد / HTML):</label>
                            <textarea class="widefat" data-field="content" name="<?php echo GURU_DASHBOARD_SETTINGS; ?>[menu_items][<?php echo $index; ?>][content]" rows="5"><?php echo esc_textarea($item['content'] ?? ''); ?></textarea>
                        </p>
                        <p>
                            <label>نقش‌های کاربری مجاز:</label>
                            <select class="widefat" data-field="roles" name="<?php echo GURU_DASHBOARD_SETTINGS; ?>[menu_items][<?php echo $index; ?>][roles][]" multiple size="5">
                                <?php foreach($roles as $role_key => $role_name): ?>
                                    <option value="<?php echo esc_attr($role_key); ?>" <?php if(isset($item['roles'])) selected(in_array($role_key, $item['roles'])); ?>>
                                        <?php echo esc_html($role_name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </p>
                    </div>
                </div>
                <?php endforeach; endif; ?>
            </div>
            <button type="button" id="gd-add-menu-item" class="button button-primary">افزودن آیتم منو</button>
            
            <div id="gd-menu-item-template" style="display:none;">
                <div class="gd-menu-item postbox">
                    <div class="gd-menu-item-handle handle">
                        <span class="dashicons dashicons-menu"></span>
                        <h3 class="hndle"><span>آیتم جدید</span></h3>
                        <button type="button" class="button-link gd-remove-item"><span class="dashicons dashicons-trash"></span></button>
                    </div>
                    <div class="inside">
                        <p>
                            <label>عنوان منو:</label>
                            <input type="text" class="widefat gd-item-title" data-field="title" name="" value="">
                        </p>
                        <p>
                            <label>Slug (شناسه انگلیسی):</label>
                            <input type="text" class="widefat" data-field="slug" name="" value="">
                        </p>
                        <p>
                            <label>کلاس آیکن (Dashicons):</label>
                            <input type="text" class="widefat" data-field="icon" name="" value="">
                        </p>
                        <p>
                            <label>نوع محتوا:</label>
                            <select class="widefat" data-field="type">
                                <option value="shortcode">شورت‌کد</option>
                                <option value="html">HTML</option>
                            </select>
                        </p>
                        <p>
                            <label>محتوا (شورت‌کد / HTML):</label>
                            <textarea class="widefat" data-field="content" name="" rows="5"></textarea>
                        </p>
                        <p>
                            <label>نقش‌های کاربری مجاز:</label>
                            <select class="widefat" data-field="roles" name="" multiple size="5">
                                <?php foreach($roles as $role_key => $role_name): ?>
                                    <option value="<?php echo esc_attr($role_key); ?>"><?php echo esc_html($role_name); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </p>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    public function settings_page_render() { 
        ?>
        <div class="wrap gd-admin-wrap">
            <h1>تنظیمات Guru Dashboard</h1>
            <form action="options.php" method="post">
                <?php 
                settings_fields('guruDashboardPage');
                do_settings_sections('guruDashboardPage');
                submit_button();
                ?>
            </form>
        </div>
        <?php 
    }

    public function admin_enqueue_assets($hook) { 
        if ('toplevel_page_guru_dashboard' != $hook) return;
        
        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script('wp-color-picker');
        wp_enqueue_script('jquery-ui-sortable');
        wp_enqueue_media();
        ?>
        <style>
            .gd-admin-wrap{font-family:inherit}
            .gd-admin-wrap h1{color:#1a1f3a;font-size:28px;font-weight:700;margin:0 0 25px;display:flex;align-items:center;gap:12px}
            .gd-admin-wrap .form-table th{color:#1a1f3a;font-weight:600}
            .gd-admin-wrap .form-table td{padding:15px 10px}
            .gd-admin-wrap .description{color:#6b7280;font-size:13px}
            .gd-admin-wrap .button-primary{background:linear-gradient(135deg,#0600C9 0%,#960707 100%)!important;border:none!important;color:#fff!important;padding:8px 20px!important;border-radius:8px!important;box-shadow:0 4px 12px rgba(6,0,201,0.3)!important;transition:all 0.3s!important}
            .gd-admin-wrap .button-primary:hover{transform:translateY(-2px)!important;box-shadow:0 6px 16px rgba(6,0,201,0.4)!important}
            .gd-admin-wrap .button{background:#f3f4f6!important;color:#1a1f3a!important;border:1px solid #d1d5db!important;border-radius:8px!important}
            .gd-admin-wrap .button:hover{background:#e5e7eb!important}
            .gd-admin-wrap .gd-menu-item .inside p{margin:0 0 15px}
            .gd-admin-wrap .gd-menu-item .inside p:last-child{margin-bottom:0}
            .gd-menu-item-handle{cursor:pointer;padding:12px;display:flex;align-items:center;border-bottom:1px solid #e5e7eb;background:#fafafa}
            .gd-menu-item.closed .gd-menu-item-handle{border-bottom:none}
            .gd-menu-item-handle .dashicons-menu{margin-left:8px;cursor:move;color:#0600C9}
            .gd-menu-item-handle h3{margin:0;flex-grow:1;font-size:14px;padding:0!important;color:#1a1f3a;font-weight:600}
            .gd-remove-item{color:#960707!important;transition:color 0.2s}
            .gd-remove-item:hover{color:#dc2626!important}
            #gd-menu-items-wrapper{margin-bottom:15px}
            .gd-menu-item{margin-bottom:10px;border:2px solid #e5e7eb!important;border-radius:12px!important;overflow:hidden}
            .gd-menu-item .inside{padding:15px;background:#fff}
            .gd-menu-item label{font-weight:600;color:#1a1f3a;display:block;margin-bottom:5px}
            .gd-menu-item input[type="text"],.gd-menu-item textarea,.gd-menu-item select{border:2px solid #e5e7eb!important;border-radius:8px!important;padding:8px 12px!important;transition:border-color 0.2s!important}
            .gd-menu-item input[type="text"]:focus,.gd-menu-item textarea:focus,.gd-menu-item select:focus{border-color:#0600C9!important;outline:none!important;box-shadow:0 0 0 3px rgba(6,0,201,0.1)!important}
            #gd-add-menu-item{margin-top:10px}
        </style>
        <?php
    }

    public function admin_inline_scripts() {
        $screen = get_current_screen();
        if ($screen->id !== 'toplevel_page_guru_dashboard') return;
        ?>
        <script>
        jQuery(document).ready(function($){
            $('.gd-color-picker').wpColorPicker();
            
            function gd_update_indices(){
                $('#gd-menu-items-wrapper .gd-menu-item').each(function(index){
                    var baseName='<?php echo GURU_DASHBOARD_SETTINGS; ?>[menu_items]['+index+']';
                    $(this).find('input, select, textarea').each(function(){
                        var fieldName=$(this).data('field');
                        if(fieldName){
                            var finalName=baseName+'['+fieldName+']';
                            if($(this).is('select[multiple]')){
                                finalName+='[]';
                            }
                            $(this).attr('name',finalName);
                        }
                    });
                });
            }
            
            $('#gd-menu-items-wrapper').sortable({
                handle:'.dashicons-menu',
                axis:'y',
                update:function(){
                    gd_update_indices();
                }
            });
            
            $('#gd-menu-builder').on('click','.gd-remove-item',function(e){
                e.preventDefault();
                if(confirm('آیا از حذف این آیتم مطمئن هستید؟')){
                    $(this).closest('.gd-menu-item').remove();
                    gd_update_indices();
                }
            });
            
            $('#gd-add-menu-item').on('click',function(e){
                e.preventDefault();
                var newItemHTML=$('#gd-menu-item-template').html();
                var newItem=$(newItemHTML).appendTo('#gd-menu-items-wrapper');
                newItem.removeClass('closed').find('.inside').show();
                gd_update_indices();
                newItem.find('.gd-item-title').focus();
            });
            
            $('#gd-menu-items-wrapper').on('keyup','.gd-item-title',function(){
                var title=$(this).val()||'آیتم جدید';
                $(this).closest('.gd-menu-item').find('.hndle span').text(title);
            });
            
            $('#gd-menu-items-wrapper').on('click', '.gd-menu-item-handle', function(e) {
                if ($(e.target).is('.gd-remove-item, .dashicons-trash, .dashicons-menu')) return;
                $(this).closest('.postbox').toggleClass('closed').find('.inside').slideToggle('fast');
            });
            
            var mediaUploader;
            $('#gd-upload-logo-button').click(function(e) {
                e.preventDefault();
                if (mediaUploader) {
                    mediaUploader.open();
                    return;
                }
                mediaUploader = wp.media({
                    title: 'انتخاب لوگو',
                    button: { text: 'انتخاب' },
                    multiple: false
                });
                mediaUploader.on('select', function() {
                    var attachment = mediaUploader.state().get('selection').first().toJSON();
                    $('#gd_login_logo_url_field').val(attachment.url);
                    $('#gd-logo-preview').html('<img src="' + attachment.url + '" style="max-width:150px; height:auto; border:1px solid #ddd; padding:5px;">');
                });
                mediaUploader.open();
            });
        });
        </script>
        <?php
    }
    
    public function handle_profile_update() {
        if (isset($_POST['gd_update_profile_nonce']) && wp_verify_nonce($_POST['gd_update_profile_nonce'], 'gd_update_profile')) {
            $current_user = wp_get_current_user();
            if (!$current_user->ID) return;
            
            $user_data = [
                'ID' => $current_user->ID,
                'first_name' => sanitize_text_field($_POST['first_name']),
                'last_name' => sanitize_text_field($_POST['last_name']),
                'user_email' => sanitize_email($_POST['email'])
            ];
            
            if (!empty($_POST['pass1']) && !empty($_POST['pass2']) && $_POST['pass1'] === $_POST['pass2']) {
                wp_set_password($_POST['pass1'], $current_user->ID);
            }
            
            $result = wp_update_user($user_data);
            
            if (!is_wp_error($result)) {
                if(isset($_POST['gd_avatar_attachment_id'])) {
                    update_user_meta($current_user->ID, 'gd_avatar_attachment_id', absint($_POST['gd_avatar_attachment_id']));
                }
                if(isset($_POST['gd_user_color'])) {
                    update_user_meta($current_user->ID, 'gd_user_color', sanitize_hex_color($_POST['gd_user_color']));
                }
                if(isset($_POST['gd_sidebar_bg_id'])) {
                    update_user_meta($current_user->ID, 'gd_sidebar_bg_id', absint($_POST['gd_sidebar_bg_id']));
                }
            }
            
            wp_redirect(add_query_arg(['tab' => 'edit-profile', 'updated' => 'true'], get_permalink()));
            exit;
        }
    }

    public function handle_dashboard_redirects() {
        $dashboard_page_id = $this->options['dashboard_page_id'] ?? 0;
        
        if ($dashboard_page_id && is_page($dashboard_page_id)) {
            if (!is_user_logged_in()) {
                wp_redirect(wp_login_url(get_permalink($dashboard_page_id)));
                exit;
            }
            
            $current_user = wp_get_current_user();
            if ($current_user && in_array('administrator', (array) $current_user->roles)) {
                wp_redirect(admin_url());
                exit;
            }
        }
    }
    
    public function custom_avatar($avatar, $id_or_email, $size, $default, $alt) {
        $user = false;
        
        if(is_numeric($id_or_email)) {
            $user = get_user_by('id', (int)$id_or_email);
        } elseif(is_object($id_or_email)) {
            if(!empty($id_or_email->user_id)) {
                $user = get_user_by('id', (int)$id_or_email->user_id);
            }
        } else {
            $user = get_user_by('email', $id_or_email);
        }
        
        if(!$user) return $avatar;
        
        $attachment_id = get_user_meta($user->ID, 'gd_avatar_attachment_id', true);
        
        if($attachment_id) {
            $src = wp_get_attachment_image_url($attachment_id, 'thumbnail');
            if($src) {
                $avatar = '<img src="'.esc_url($src).'" width="'.esc_attr($size).'" height="'.esc_attr($size).'" alt="'.esc_attr($alt).'" class="avatar avatar-'.esc_attr($size).' photo" />';
            }
        }
        
        return $avatar;
    }

    private function get_shortcode_output($shortcode_string) {
        ob_start();
        echo do_shortcode(wp_kses_post($shortcode_string));
        return ob_get_clean();
    }
    
    private function hex_to_rgb($hex) {
        $hex = str_replace('#', '', $hex);
        if(strlen($hex) == 3) {
            $r = hexdec(substr($hex,0,1).substr($hex,0,1));
            $g = hexdec(substr($hex,1,1).substr($hex,1,1));
            $b = hexdec(substr($hex,2,1).substr($hex,2,1));
        } else {
            $r = hexdec(substr($hex,0,2));
            $g = hexdec(substr($hex,2,2));
            $b = hexdec(substr($hex,4,2));
        }
        return "$r, $g, $b";
    }
    
    private function adjust_brightness($hex, $percent) {
        $hex = str_replace('#', '', $hex);
        $r = hexdec(substr($hex,0,2));
        $g = hexdec(substr($hex,2,2));
        $b = hexdec(substr($hex,4,2));
        
        $r = max(0, min(255, $r + ($r * $percent / 100)));
        $g = max(0, min(255, $g + ($g * $percent / 100)));
        $b = max(0, min(255, $b + ($b * $percent / 100)));
        
        return sprintf("#%02x%02x%02x", $r, $g, $b);
    }
    
    public function render_dashboard_shortcode() {
        if (!is_user_logged_in() || current_user_can('administrator')) return '';
        
        $current_user = wp_get_current_user();
        $user_color = get_user_meta($current_user->ID, 'gd_user_color', true);
        $sidebar_bg_id = get_user_meta($current_user->ID, 'gd_sidebar_bg_id', true);
        $sidebar_bg_url = $sidebar_bg_id ? wp_get_attachment_image_url($sidebar_bg_id, 'large') : '';
        $primary_color = $user_color ?: ($this->options['primary_color'] ?? '#0600C9');
        $secondary_color = '#960707';
        
        $primary_rgb = $this->hex_to_rgb($primary_color);
        $primary_light = $this->adjust_brightness($primary_color, 10);
        $primary_dark = $this->adjust_brightness($primary_color, -10);
        
        $menu_config = $this->options['menu_items'] ?? [];
        $active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'dashboard';
        $user_roles = (array) $current_user->roles;
        
        $base_menus = [
            ['slug'=>'dashboard','title'=>'پیشخوان','icon'=>'dashicons-dashboard','roles'=>['all']]
        ];
        
        if ($this->is_woocommerce_enabled()) {
            $base_menus[] = ['slug'=>'order-history','title'=>'تاریخچه خرید','icon'=>'dashicons-cart','roles'=>['all']];
        }
        
        $base_menus[] = ['slug'=>'edit-profile','title'=>'سفارشی‌سازی پنل','icon'=>'dashicons-admin-customizer','roles'=>['all']];
        
        $all_menus = array_merge($base_menus, $menu_config);
        
        $visible_menus = [];
        foreach ($all_menus as $item) {
            if (!empty($item['roles']) && (in_array('all', $item['roles']) || array_intersect($user_roles, $item['roles']))) {
                $visible_menus[] = $item;
            }
        }
        
        $color_palette = ['#0600C9', '#960707', '#343536', '#6366f1', '#8b5cf6', '#ec4899', '#f59e0b', '#10b981'];
        
        ob_start();
        ?>
        <style>
            :root{
                --gd-primary:<?php echo esc_attr($primary_color); ?>;
                --gd-primary-rgb:<?php echo esc_attr($primary_rgb); ?>;
                --gd-primary-light:<?php echo esc_attr($primary_light); ?>;
                --gd-primary-dark:<?php echo esc_attr($primary_dark); ?>;
                --gd-secondary:<?php echo esc_attr($secondary_color); ?>;
                --gd-dark:#1a1f3a;
                --gd-gray:#6b7280;
                --gd-light:#f3f4f6;
                --gd-user-color:<?php echo esc_attr($primary_color); ?>;
            }
            *{margin:0;padding:0;box-sizing:border-box}
            .gd-dashboard-wrapper{direction:rtl;display:flex;flex-wrap:wrap;background:rgba(255,255,255,0.7);backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px);border-radius:24px;box-shadow:0 8px 32px rgba(var(--gd-primary-rgb),0.15);overflow:hidden;max-width:100%;margin:0 auto;border:1px solid rgba(255,255,255,0.3);font-family:inherit;min-height:80vh}
            .gd-sidebar{width:280px;background:linear-gradient(145deg,var(--gd-primary),var(--gd-secondary));backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px);color:#fff;padding:2rem 0;flex-shrink:0;position:relative;z-index:1;border-radius:24px 0 0 24px}
            .gd-sidebar.has-bg-image{background-image:url(<?php echo esc_url($sidebar_bg_url); ?>);background-size:cover;background-position:center}
            .gd-sidebar.has-bg-image::before{content:'';position:absolute;top:0;right:0;bottom:0;left:0;background:linear-gradient(145deg,rgba(var(--gd-primary-rgb),0.85),rgba(150,7,7,0.85));backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);z-index:-1;border-radius:24px 0 0 24px}
            .gd-user-profile{text-align:center;padding:0 1.5rem 1.5rem;border-bottom:1px solid rgba(255,255,255,0.2)}
            .gd-user-profile img.avatar{width:80px;height:80px;border-radius:50%;border:3px solid rgba(255,255,255,0.3);margin-bottom:1rem;box-shadow:0 4px 12px rgba(0,0,0,0.2);transition:all 0.3s}
            .gd-user-profile img.avatar:hover{transform:scale(1.05);border-color:rgba(255,255,255,0.6)}
            .gd-user-profile h3{margin:0;color:#fff;font-weight:700;font-size:17px}
            .gd-user-profile span{font-size:.85em;color:rgba(255,255,255,0.8)}
            .gd-menu{list-style-type:none;padding:0;margin:1.5rem 0 0}
            .gd-menu li a{display:flex;align-items:center;padding:.9rem 1.5rem;color:rgba(255,255,255,0.9);text-decoration:none;transition:all .3s;border-right:3px solid transparent;font-weight:500;font-size:14px}
            .gd-menu li a .dashicons{margin-left:10px;font-size:20px}
            .gd-menu li a:hover,.gd-menu li.active a{background-color:rgba(255,255,255,0.15);border-right-color:rgba(255,255,255,0.9);padding-right:2rem;color:#fff}
            .gd-content{flex-grow:1;padding:2rem;background:rgba(255,255,255,0.5);backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px);width:calc(100% - 280px);border-radius:0 24px 24px 0}
            .gd-content-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:2rem;flex-wrap:wrap;gap:15px}
            .gd-live-clock{font-size:13px;color:var(--gd-dark);background:rgba(255,255,255,0.7);backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);padding:8px 16px;border-radius:12px;direction:ltr;font-weight:500;border:1px solid rgba(var(--gd-primary-rgb),0.2)}
            .gd-content h1{color:var(--gd-dark);width:100%;font-size:24px;font-weight:700;margin-bottom:1.5rem}
            .gd-content-area{width:100%}
            .gd-shortcuts-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:1.2rem;margin-bottom:2rem;width:100%}
            .gd-shortcut-card{background:rgba(255,255,255,0.8);backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px);border-radius:16px;padding:1.5rem;text-align:center;text-decoration:none;color:var(--gd-dark);border:1px solid rgba(var(--gd-primary-rgb),0.15);transition:all 0.3s;box-shadow:0 4px 12px rgba(0,0,0,0.05);position:relative;overflow:hidden}
            .gd-shortcut-card::before{content:'';position:absolute;top:0;left:0;right:0;bottom:0;background:linear-gradient(135deg,var(--gd-primary),var(--gd-secondary));opacity:0;transition:opacity 0.3s}
            .gd-shortcut-card:hover{transform:translateY(-4px);box-shadow:0 8px 24px rgba(var(--gd-primary-rgb),0.2);border-color:var(--gd-primary)}
            .gd-shortcut-card:hover::before{opacity:0.05}
            .gd-shortcut-card > *{position:relative;z-index:1}
            .gd-shortcut-icon{font-size:2.5rem;margin-bottom:.8rem;display:block;filter:drop-shadow(0 2px 4px rgba(0,0,0,0.1))}
            .gd-shortcut-title{font-size:14px;font-weight:600;color:var(--gd-dark)}
            .gd-form{width:100%}
            .gd-form .form-row{margin-bottom:1.5rem}
            .gd-form label{display:block;font-weight:600;margin-bottom:.6rem;color:var(--gd-dark);font-size:14px}
            .gd-form input[type="text"],.gd-form input[type="email"],.gd-form input[type="password"]{width:100%;padding:12px 15px;border:1px solid rgba(var(--gd-primary-rgb),0.2);border-radius:12px;box-sizing:border-box;transition:all .3s;font-size:14px;background:rgba(255,255,255,0.8);backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);color:var(--gd-dark)}
            .gd-form input:focus{border-color:var(--gd-user-color);outline:0;box-shadow:0 0 0 3px rgba(var(--gd-primary-rgb),0.1)}
            .gd-form .button{background:linear-gradient(135deg,var(--gd-user-color) 0%,var(--gd-secondary) 100%);color:#fff;border:0;padding:12px 28px;border-radius:12px;cursor:pointer;font-size:14px;font-weight:600;transition:all .3s;box-shadow:0 4px 12px rgba(var(--gd-primary-rgb),0.3)}
            .gd-form .button:hover{transform:translateY(-2px);box-shadow:0 6px 16px rgba(var(--gd-primary-rgb),0.4)}
            .gd-form fieldset{border:1px solid rgba(var(--gd-primary-rgb),0.2);border-radius:16px;padding:20px;margin-bottom:20px;background:rgba(255,255,255,0.5);backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px)}
            .gd-form legend{padding:0 12px;font-weight:700;color:var(--gd-primary);font-size:15px}
            .gd-form legend h3{margin:0;font-size:15px}
            #gd-avatar-preview img{max-width:100px;height:auto;border-radius:50%;margin-top:12px;border:3px solid rgba(var(--gd-primary-rgb),0.2);box-shadow:0 4px 12px rgba(0,0,0,0.1)}
            #gd-sidebar-bg-preview img{max-width:180px;height:auto;margin-top:12px;border-radius:12px;border:2px solid rgba(var(--gd-primary-rgb),0.2)}
            .gd-alert{padding:12px 18px;margin-bottom:1.2rem;border-radius:12px;border:1px solid transparent;font-weight:500;font-size:14px}
            .gd-alert-success{background-color:rgba(16,185,129,0.1);color:#059669;border-color:rgba(16,185,129,0.3)}
            .gd-color-palette{display:flex;flex-wrap:wrap;gap:10px;margin-top:10px}
            .gd-color-swatch{width:36px;height:36px;border-radius:50%;cursor:pointer;border:2px solid rgba(255,255,255,0.5);box-shadow:0 2px 8px rgba(0,0,0,0.15);transition:all .3s}
            .gd-color-swatch.active{transform:scale(1.15);border-color:var(--gd-user-color);box-shadow:0 4px 12px rgba(0,0,0,0.25)}
            .gd-color-swatch:hover{transform:scale(1.1)}
            .gd-iframe-content{width:100%;height:65vh;border:none;border-radius:16px;box-shadow:0 4px 15px rgba(0,0,0,0.1);display:block;background:rgba(255,255,255,0.8)}
            .gd-content .woocommerce-orders-table,.gd-content .shop_table.order_details{width:100%!important;border-collapse:separate!important;border-spacing:0!important;font-size:13px;background:rgba(255,255,255,0.8);backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);border-radius:16px!important;box-shadow:0 4px 15px rgba(0,0,0,0.08);border:1px solid rgba(var(--gd-primary-rgb),0.15)!important;padding:12px;margin:0}
            .gd-content .woocommerce-orders-table thead{display:table-header-group!important}
            .gd-content .woocommerce-orders-table th{padding:14px!important;border:none!important;border-bottom:2px solid var(--gd-user-color)!important;text-align:right;font-weight:700;color:var(--gd-dark);background:rgba(var(--gd-primary-rgb),0.05)}
            .gd-content .woocommerce-orders-table tbody tr{background:transparent!important;box-shadow:none!important;border:none!important;border-bottom:1px solid rgba(var(--gd-primary-rgb),0.1)!important;transition:background 0.2s}
            .gd-content .woocommerce-orders-table tbody tr:hover{background:rgba(var(--gd-primary-rgb),0.03)!important}
            .gd-content .woocommerce-orders-table tbody tr:last-child{border-bottom:none!important}
            .gd-content .woocommerce-orders-table td{padding:14px!important;border:none!important;vertical-align:middle!important;color:var(--gd-dark)}
            .gd-content .woocommerce-orders-table td:first-child{border-radius:0!important}
            .gd-content .woocommerce-orders-table td:last-child{border-radius:0!important;text-align:left}
            .gd-content .woocommerce-orders-table a:not(.woocommerce-button){text-decoration:none!important;font-weight:700!important;color:var(--gd-user-color)!important;transition:color 0.2s}
            .gd-content .woocommerce-orders-table a:not(.woocommerce-button):hover{color:var(--gd-dark)!important}
            .gd-content .woocommerce-button,.gd-content .order-again a{background:linear-gradient(135deg,var(--gd-user-color) 0%,var(--gd-secondary) 100%)!important;color:#fff!important;border-radius:8px!important;padding:8px 16px!important;line-height:1.5!important;font-weight:600!important;border:none!important;text-decoration:none!important;transition:all 0.3s!important;box-shadow:0 2px 8px rgba(var(--gd-primary-rgb),0.3)!important;font-size:13px!important}
            .gd-content .woocommerce-button:hover,.gd-content .order-again a:hover{transform:translateY(-2px)!important;box-shadow:0 4px 12px rgba(var(--gd-primary-rgb),0.4)!important}
            .gd-content .woocommerce-order-details,.gd-content .woocommerce-customer-details{background:rgba(255,255,255,0.8)!important;backdrop-filter:blur(10px)!important;-webkit-backdrop-filter:blur(10px)!important;padding:24px!important;border-radius:16px!important;border:1px solid rgba(var(--gd-primary-rgb),0.15)!important;margin-bottom:20px!important;box-shadow:0 4px 15px rgba(0,0,0,0.08)}
            .gd-content .woocommerce-order-details h2,.gd-content .woocommerce-customer-details h2{font-size:1.4em!important;margin-bottom:16px!important;color:var(--gd-dark);font-weight:700}
            .gd-content .shop_table.order_details{box-shadow:none!important;border:none!important;padding:0!important}
            .gd-content .shop_table.order_details th,.gd-content .shop_table.order_details td{padding:12px!important;border-bottom:1px solid rgba(var(--gd-primary-rgb),0.1)!important;background:transparent!important}
            .gd-content .shop_table.order_details tfoot tr:last-child th,.gd-content .shop_table.order_details tfoot tr:last-child td{border-bottom:none!important;font-weight:700}
            .gd-content address{font-style:normal;line-height:1.7;background:rgba(243,244,246,0.5);padding:14px;border-radius:12px;border:1px solid rgba(var(--gd-primary-rgb),0.1)}
            .gd-content mark{background-color:var(--gd-user-color);color:#fff;padding:2px 8px;border-radius:6px;font-weight:600}
            @media (max-width:768px){
                .gd-content .woocommerce-orders-table thead{display:none!important}
                .gd-content .woocommerce-orders-table tbody,.gd-content .woocommerce-orders-table tr,.gd-content .woocommerce-orders-table td{display:block;width:100%!important;box-sizing:border-box}
                .gd-content .woocommerce-orders-table tr{margin-bottom:16px;padding:12px;border:1px solid rgba(var(--gd-primary-rgb),0.2)!important;border-radius:12px;box-shadow:0 4px 15px rgba(0,0,0,0.08)}
                .gd-content .woocommerce-orders-table td{text-align:right!important;padding-left:50%!important;position:relative;border-bottom:1px solid rgba(var(--gd-primary-rgb),0.1)!important}
                .gd-content .woocommerce-orders-table td:last-child{border-bottom:0!important;text-align:right!important}
                .gd-content .woocommerce-orders-table td::before{content:attr(data-title);position:absolute;right:12px;width:45%;font-weight:700;color:var(--gd-dark);text-align:right}
            }
            @media (max-width:900px){
                .gd-dashboard-wrapper{flex-direction:column;border-radius:16px}
                .gd-sidebar{width:100%;border-radius:16px 16px 0 0}
                .gd-sidebar.has-bg-image::before{border-radius:16px 16px 0 0}
                .gd-content{width:100%;padding:1.5rem;border-radius:0 0 16px 16px}
                .gd-shortcuts-grid{grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:1rem}
            }
        </style>
        <div class="gd-dashboard-wrapper">
            <aside class="gd-sidebar <?php if($sidebar_bg_url) echo 'has-bg-image'; ?>">
                <div class="gd-user-profile">
                    <?php echo get_avatar($current_user->ID, 80); ?>
                    <h3><?php echo esc_html($current_user->display_name); ?></h3>
                    <span><?php echo esc_html(implode(', ', array_map('ucfirst', $current_user->roles))); ?></span>
                </div>
                <ul class="gd-menu">
                    <?php foreach ($visible_menus as $item): ?>
                        <li class="<?php echo ($item['slug'] === $active_tab) ? 'active' : ''; ?>">
                            <a href="<?php echo esc_url(add_query_arg('tab', $item['slug'], get_permalink($this->options['dashboard_page_id'] ?? 0))); ?>">
                                <?php if(!empty($item['icon'])) echo '<span class="dashicons '.esc_attr($item['icon']).'"></span>'; ?>
                                <span><?php echo esc_html($item['title']); ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                    <li>
                        <a href="<?php echo wp_logout_url(home_url()); ?>">
                            <span class="dashicons dashicons-logout"></span>
                            <span>خروج</span>
                        </a>
                    </li>
                </ul>
            </aside>
            <main class="gd-content">
                <div class="gd-content-header">
                    <h1>
                        <?php
                        if ($active_tab === 'view-order') {
                            $order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
                            echo 'جزئیات سفارش #' . esc_html($order_id);
                        } else {
                            foreach($visible_menus as $item){
                                if($item['slug'] === $active_tab) echo esc_html($item['title']);
                            }
                        }
                        ?>
                    </h1>
                    <div id="gd-live-clock" class="gd-live-clock"></div>
                </div>
                <div class="gd-content-area">
                <?php 
                $content_found = false;
                
                switch ($active_tab) {
                    case 'edit-profile':
                        if (isset($_GET['updated'])) {
                            echo '<div class="gd-alert gd-alert-success">✓ سفارشی‌سازی با موفقیت ذخیره شد.</div>';
                        }
                        ?>
                        <form method="post" class="gd-form">
                            <fieldset>
                                <legend><h3>ویرایش مشخصات</h3></legend>
                                <div class="form-row">
                                    <label for="first_name">نام</label>
                                    <input type="text" id="first_name" name="first_name" value="<?php echo esc_attr($current_user->first_name); ?>">
                                </div>
                                <div class="form-row">
                                    <label for="last_name">نام خانوادگی</label>
                                    <input type="text" id="last_name" name="last_name" value="<?php echo esc_attr($current_user->last_name); ?>">
                                </div>
                                <div class="form-row">
                                    <label for="email">ایمیل</label>
                                    <input type="email" id="email" name="email" value="<?php echo esc_attr($current_user->user_email); ?>" required>
                                </div>
                                <div class="form-row">
                                    <label>تغییر تصویر پروفایل</label>
                                    <button type="button" class="button" id="gd-upload-avatar-button">انتخاب تصویر</button>
                                    <input type="hidden" name="gd_avatar_attachment_id" id="gd_avatar_attachment_id" value="<?php echo esc_attr(get_user_meta($current_user->ID, 'gd_avatar_attachment_id', true)); ?>">
                                    <div id="gd-avatar-preview">
                                        <?php
                                        $avatar_id = get_user_meta($current_user->ID, 'gd_avatar_attachment_id', true);
                                        if($avatar_id) echo wp_get_attachment_image($avatar_id, 'thumbnail');
                                        ?>
                                    </div>
                                </div>
                            </fieldset>
                            
                            <fieldset>
                                <legend><h3>سفارشی‌سازی پنل</h3></legend>
                                <div class="form-row">
                                    <label>رنگ پنل</label>
                                    <div class="gd-color-palette">
                                        <?php foreach($color_palette as $color): ?>
                                            <div class="gd-color-swatch <?php echo ($primary_color===$color?'active':''); ?>" 
                                                 data-color="<?php echo esc_attr($color); ?>" 
                                                 style="background-color:<?php echo esc_attr($color); ?>">
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <input type="hidden" name="gd_user_color" id="gd_user_color" value="<?php echo esc_attr($user_color); ?>">
                                </div>
                                <div class="form-row">
                                    <label>پس‌زمینه سایدبار</label>
                                    <button type="button" class="button" id="gd-upload-sidebar-bg-button">انتخاب تصویر</button>
                                    <button type="button" class="button" id="gd-remove-sidebar-bg-button" style="<?php echo !$sidebar_bg_id ? 'display:none;' : '' ?>">حذف تصویر</button>
                                    <input type="hidden" name="gd_sidebar_bg_id" id="gd_sidebar_bg_id" value="<?php echo esc_attr($sidebar_bg_id); ?>">
                                    <div id="gd-sidebar-bg-preview">
                                        <?php if($sidebar_bg_id) echo wp_get_attachment_image($sidebar_bg_id, 'thumbnail'); ?>
                                    </div>
                                </div>
                            </fieldset>
                            
                            <fieldset>
                                <legend><h3>تغییر رمز عبور</h3></legend>
                                <p style="color:var(--gd-gray);font-size:13px;margin-bottom:12px">این بخش را برای عدم تغییر رمز عبور، خالی بگذارید.</p>
                                <div class="form-row">
                                    <label for="pass1">رمز عبور جدید</label>
                                    <input type="password" id="pass1" name="pass1" autocomplete="new-password">
                                </div>
                                <div class="form-row">
                                    <label for="pass2">تکرار رمز عبور جدید</label>
                                    <input type="password" id="pass2" name="pass2" autocomplete="new-password">
                                </div>
                            </fieldset>
                            
                            <?php wp_nonce_field('gd_update_profile', 'gd_update_profile_nonce'); ?>
                            <p class="form-row">
                                <input type="submit" value="ذخیره تغییرات" class="button">
                            </p>
                        </form>
                        <?php
                        if(function_exists('wp_enqueue_media')){
                            wp_enqueue_media();
                        }
                        $content_found = true;
                        break;
                        
                    case 'dashboard':
                        $custom_content = $this->options['dashboard_content'] ?? '';
                        if (!empty($custom_content)) {
                            echo '<div style="width:100%">' . wpautop($this->get_shortcode_output($custom_content)) . '</div>';
                        }
                        
                        $shortcuts = [];
                        foreach ($visible_menus as $item) {
                            if ($item['slug'] !== 'dashboard' && $item['slug'] !== 'edit-profile') {
                                $shortcuts[] = $item;
                            }
                        }
                        
                        if (!empty($shortcuts)) {
                            echo '<div class="gd-shortcuts-grid">';
                            foreach ($shortcuts as $shortcut) {
                                $url = add_query_arg('tab', $shortcut['slug'], get_permalink($this->options['dashboard_page_id'] ?? 0));
                                $icon = !empty($shortcut['icon']) ? $shortcut['icon'] : 'dashicons-admin-generic';
                                echo '<a href="'.esc_url($url).'" class="gd-shortcut-card">';
                                echo '<span class="dashicons '.esc_attr($icon).' gd-shortcut-icon"></span>';
                                echo '<div class="gd-shortcut-title">'.esc_html($shortcut['title']).'</div>';
                                echo '</a>';
                            }
                            echo '</div>';
                        }
                        
                        $content_found = true;
                        break;
                        
                    case 'order-history':
                        if ($this->is_woocommerce_enabled()) {
                            do_action('woocommerce_account_orders_endpoint', get_query_var('orders'));
                        } else {
                            echo '<p>این بخش نیاز به فعال بودن ووکامرس دارد.</p>';
                        }
                        $content_found = true;
                        break;
                        
                    case 'view-order':
                        if ($this->is_woocommerce_enabled()) {
                            $order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
                            if ($order_id) {
                                $order = wc_get_order($order_id);
                                if ($order && $order->get_customer_id() === get_current_user_id()) {
                                    do_action('woocommerce_account_view-order_endpoint', $order_id);
                                } else {
                                    wc_print_notice('سفارش نامعتبر است یا به حساب شما تعلق ندارد.', 'error');
                                }
                            }
                        } else {
                            echo '<p>این بخش نیاز به فعال بودن ووکامرس دارد.</p>';
                        }
                        $content_found = true;
                        break;
                        
                    default:
                        foreach ($visible_menus as $item) {
                            if (($item['slug'] ?? '') === $active_tab && !empty($item['content'])) {
                                if(($item['type'] ?? 'shortcode') === 'html'){
                                    echo '<iframe class="gd-iframe-content" srcdoc="'.esc_attr($item['content']).'"></iframe>';
                                } else {
                                    echo '<div style="width:100%">' . $this->get_shortcode_output($item['content']) . '</div>';
                                }
                                $content_found = true;
                                break;
                            }
                        }
                        break;
                }
                
                if (!$content_found) {
                    echo '<h3 style="color:var(--gd-gray)">صفحه مورد نظر یافت نشد.</h3>';
                }
                ?>
                </div>
            </main>
        </div>
        <script>
            jQuery(document).ready(function($){
                function setupUploader(buttonId, previewId, hiddenId, removeBtnId=null){
                    var uploader;
                    $(buttonId).click(function(e){
                        e.preventDefault();
                        if(uploader){
                            uploader.open();
                            return;
                        }
                        uploader=wp.media({
                            title:'Select Image',
                            button:{text:'Select'},
                            multiple:false
                        });
                        uploader.on('select',function(){
                            var attachment=uploader.state().get('selection').first().toJSON();
                            $(hiddenId).val(attachment.id);
                            $(previewId).html('<img src="'+attachment.sizes.thumbnail.url+'" style="max-width:100px; height:auto; margin-top:12px; border-radius:50%; border:3px solid rgba(var(--gd-primary-rgb),0.2);">');
                            if(removeBtnId)$(removeBtnId).show();
                        });
                        uploader.open();
                    });
                    if(removeBtnId){
                        $(removeBtnId).on('click', function(){
                            $(hiddenId).val('');
                            $(previewId).html('');
                            $(this).hide();
                        });
                    }
                }
                
                setupUploader('#gd-upload-avatar-button', '#gd-avatar-preview', '#gd_avatar_attachment_id');
                setupUploader('#gd-upload-sidebar-bg-button', '#gd-sidebar-bg-preview', '#gd_sidebar_bg_id', '#gd-remove-sidebar-bg-button');
                
                $('.gd-color-swatch').on('click', function(){
                    $('.gd-color-swatch').removeClass('active');
                    $(this).addClass('active');
                    $('#gd_user_color').val($(this).data('color'));
                });
                
                (function clock(){
                    const clockEl=document.getElementById('gd-live-clock');
                    if(!clockEl)return;
                    
                    function updateClock(){
                        fetch('<?php echo admin_url('admin-ajax.php'); ?>?action=gd_get_time')
                            .then(response => response.json())
                            .then(data => {
                                if(data.success){
                                    clockEl.innerHTML = data.data.formatted;
                                }
                            })
                            .catch(() => {
                                const now = new Date();
                                const options = {
                                    timeZone: 'Asia/Tehran',
                                    weekday: 'long',
                                    year: 'numeric',
                                    month: 'long',
                                    day: 'numeric',
                                    hour: '2-digit',
                                    minute: '2-digit',
                                    second: '2-digit'
                                };
                                clockEl.innerHTML = now.toLocaleDateString('fa-IR-u-nu-latn', options);
                            });
                    }
                    
                    updateClock();
                    setInterval(updateClock, 1000);
                })();
                
                $('.woocommerce-orders-table__cell').each(function(){
                    var title = $(this).data('title');
                    if(title) $(this).attr('data-title', title + ': ');
                });
            });
        </script>
        <?php
        return ob_get_clean();
    }

    public function custom_wc_get_endpoint_url($url, $endpoint, $value, $permalink) {
        $dashboard_page_id = $this->options['dashboard_page_id'] ?? 0;
        
        if ('view-order' === $endpoint && $dashboard_page_id) {
            $dashboard_url = get_permalink($dashboard_page_id);
            return add_query_arg(['tab' => 'view-order', 'order_id' => $value], $dashboard_url);
        }
        
        return $url;
    }

    public function custom_login_style() {
        $logo_url = !empty($this->options['login_logo_url']) ? $this->options['login_logo_url'] : includes_url('images/w-logo-white.png');
        $primary_color = !empty($this->options['primary_color']) ? $this->options['primary_color'] : '#0600C9';
        ?>
        <style type="text/css">
        :root{--gd-login-color:<?php echo esc_attr($primary_color); ?>}
        body.login{background:linear-gradient(135deg,#f3f4f6 0%,#ffffff 100%);direction:rtl;font-family:inherit}
        #login h1 a{background-image:url(<?php echo esc_url($logo_url); ?>);width:200px;height:80px;background-size:contain;background-position:center;background-repeat:no-repeat;margin:0 auto 30px auto}
        #loginform{background:rgba(255,255,255,0.8);backdrop-filter:blur(20px);-webkit-backdrop-filter:blur(20px);border-radius:16px;box-shadow:0 8px 30px rgba(6,0,201,0.15);padding:30px;border:1px solid rgba(6,0,201,0.2);margin-top:20px}
        .login form .input:focus{border-color:var(--gd-login-color);box-shadow:0 0 0 3px rgba(6,0,201,0.1);outline:0}
        .wp-core-ui .button-primary{background:linear-gradient(135deg,var(--gd-login-color) 0%,#960707 100%)!important;border:none!important;width:100%;padding:14px!important;height:auto!important;font-size:15px;border-radius:12px;transition:all .3s ease;box-shadow:0 4px 12px rgba(6,0,201,0.3);font-weight:600}
        .wp-core-ui .button-primary:hover{transform:translateY(-2px);box-shadow:0 6px 16px rgba(6,0,201,0.4)}
        .login #nav a,.login #backtoblog a{color:#1a1f3a;transition:color 0.2s}
        .login #nav a:hover,.login #backtoblog a:hover{color:var(--gd-login-color)}
        #login_error{border-right:4px solid #960707;border-left:0;background:rgba(150,7,7,0.1);color:#960707}
        .login .message{border-right-color:#10b981;background:rgba(16,185,129,0.1);color:#059669}
        </style>
        <?php
    }

    public function custom_login_logo_url() {
        return home_url();
    }

    public function custom_login_logo_title() {
        return get_bloginfo('name');
    }
}

add_action('wp_ajax_gd_get_time', function() {
    date_default_timezone_set('Asia/Tehran');
    $timestamp = current_time('timestamp');
    
    require_once ABSPATH . 'wp-admin/includes/translation-install.php';
    
    $jdate = jdate('l j F Y', $timestamp, '', 'Asia/Tehran', 'fa');
    $time = date('H:i:s', $timestamp);
    
    wp_send_json_success([
        'formatted' => $jdate . ' | ' . $time,
        'timestamp' => $timestamp
    ]);
});

add_action('wp_ajax_nopriv_gd_get_time', function() {
    date_default_timezone_set('Asia/Tehran');
    $timestamp = current_time('timestamp');
    
    $jdate = jdate('l j F Y', $timestamp, '', 'Asia/Tehran', 'fa');
    $time = date('H:i:s', $timestamp);
    
    wp_send_json_success([
        'formatted' => $jdate . ' | ' . $time,
        'timestamp' => $timestamp
    ]);
});

function jdate($format, $timestamp = '', $none = '', $time_zone = 'Asia/Tehran', $tr_num = 'fa') {
    $T_sec = 0;
    if ($time_zone != 'local') date_default_timezone_set(($time_zone == '') ? 'Asia/Tehran' : $time_zone);
    $ts = $T_sec + (($timestamp == '' or $timestamp == 'now') ? time() : tr_num($timestamp));
    $date = explode('_', date('H_i_s_j_n_Y_w_z_m_d', $ts));
    list($j_y, $j_m, $j_d) = gregorian_to_jalali($date[5], $date[4], $date[3]);
    $doy = ($j_m < 7) ? (($j_m - 1) * 31) + $j_d - 1 : (($j_m - 7) * 30) + $j_d + 185;
    $kab = (((($j_y % 33) % 4) - 1) == (int)(($j_y % 33) * 0.05)) ? 1 : 0;
    $sl = strlen($format);
    $out = '';
    for ($i = 0; $i < $sl; $i++) {
        $sub = substr($format, $i, 1);
        if ($sub == '\\') {
            $out .= substr($format, ++$i, 1);
            continue;
        }
        switch ($sub) {
            case 'j': case 'd': $out .= tr_num(sprintf('%02d', $j_d), $tr_num); break;
            case 'n': case 'm': $out .= tr_num(sprintf('%02d', $j_m), $tr_num); break;
            case 'Y': $out .= tr_num($j_y, $tr_num); break;
            case 'H': case 'h': case 'g': case 'G': case 'i': case 's': $out .= tr_num($date[array_search($sub, ['H', 'i', 's', 'h', 'g', 'G'])], $tr_num); break;
            case 'l': $days = ['یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنج‌شنبه', 'جمعه', 'شنبه']; $out .= $days[$date[6]]; break;
            case 'F': $months = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند']; $out .= $months[$j_m - 1]; break;
            default: $out .= $sub;
        }
    }
    return ($tr_num != 'en') ? $out : tr_num($out, 'fa', '.'); 
}

function tr_num($str, $mod = 'en', $mf = '٫') {
    $num_a = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '.'];
    $key_a = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹', $mf];
    return ($mod == 'fa') ? str_replace($num_a, $key_a, $str) : str_replace($key_a, $num_a, $str);
}

function gregorian_to_jalali($g_y, $g_m, $g_d) {
    $g_days_in_month = [31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
    $j_days_in_month = [31, 31, 31, 31, 31, 31, 30, 30, 30, 30, 30, 29];
    $gy = $g_y - 1600;
    $gm = $g_m - 1;
    $gd = $g_d - 1;
    $g_day_no = 365 * $gy + div($gy + 3, 4) - div($gy + 99, 100) + div($gy + 399, 400);
    for ($i = 0; $i < $gm; ++$i) $g_day_no += $g_days_in_month[$i];
    if ($gm > 1 && (($gy % 4 == 0 && $gy % 100 != 0) || ($gy % 400 == 0))) $g_day_no++;
    $g_day_no += $gd;
    $j_day_no = $g_day_no - 79;
    $j_np = div($j_day_no, 12053);
    $j_day_no = $j_day_no % 12053;
    $jy = 979 + 33 * $j_np + 4 * div($j_day_no, 1461);
    $j_day_no %= 1461;
    if ($j_day_no >= 366) {
        $jy += div($j_day_no - 1, 365);
        $j_day_no = ($j_day_no - 1) % 365;
    }
    for ($i = 0; $i < 11 && $j_day_no >= $j_days_in_month[$i]; ++$i) $j_day_no -= $j_days_in_month[$i];
    $jm = $i + 1;
    $jd = $j_day_no + 1;
    return [$jy, $jm, $jd];
}

function div($a, $b) {
    return (int)($a / $b);
}

Guru_Dashboard::get_instance();
