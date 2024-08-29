<?php
/**
 * Plugin Name: External Links Manager
 * Plugin URI: http://zaraytech/plugins/external-links-manager/
 * Description: Manages external links as a custom post type with auto-fetching of titles and publication dates.
 * Version: 1.8
 * Author: Richu
 * Author URI: http://zaraytech.com
 * License: GPL2
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

require_once 'simple_html_dom.php';

class External_Links_Manager {

    public function __construct() {
        add_action('init', array($this, 'create_external_link_post_type'));
        add_action('add_meta_boxes', array($this, 'add_external_link_meta_boxes'));
        add_action('save_post', array($this, 'save_external_link_meta'));
        add_action('wp_ajax_fetch_url_info', array($this, 'fetch_url_info'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('vc_before_init', array($this, 'integrate_with_vc'));
        add_filter('single_template', array($this, 'load_external_link_template'));
        add_filter('post_type_link', array($this, 'modify_external_link_permalink'), 10, 2);
        add_filter('the_content', array($this, 'modify_external_link_content'), 20);
    }

    public function create_external_link_post_type() {
        $args = array(
            'labels' => array(
                'name' => __('External Links'),
                'singular_name' => __('External Link'),
            ),
            'public' => true,
            'has_archive' => true,
            'supports' => array('title', 'custom-fields'),
            'taxonomies' => array('category'),
            'menu_icon' => 'dashicons-admin-links',
        );
        register_post_type('external_link', $args);
    }

    public function add_external_link_meta_boxes() {
        add_meta_box(
            'external_link_details',
            'External Link Details',
            array($this, 'external_link_details_callback'),
            'external_link',
            'normal',
            'high'
        );
    }

    public function external_link_details_callback($post) {
        wp_nonce_field('save_external_link_details', 'external_link_details_nonce');
        $url = get_post_meta($post->ID, '_external_link_url', true);
        $date = get_post_meta($post->ID, '_external_link_date', true);
        ?>
        <p>
            <label for="external_link_url">URL:</label>
            <input type="url" id="external_link_url" name="external_link_url" value="<?php echo esc_attr($url); ?>" style="width: 100%;">
            <button type="button" id="fetch_url_info" class="button">Fetch URL Info</button>
        </p>
        <p>
            <label for="external_link_date">Publication Date:</label>
            <input type="date" id="external_link_date" name="external_link_date" value="<?php echo esc_attr($date); ?>">
        </p>
        <div id="url_info_message"></div>
        <?php
    }

    public function save_external_link_meta($post_id) {
        if (!isset($_POST['external_link_details_nonce']) || !wp_verify_nonce($_POST['external_link_details_nonce'], 'save_external_link_details')) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        if (isset($_POST['external_link_url'])) {
            update_post_meta($post_id, '_external_link_url', sanitize_url($_POST['external_link_url']));
        }
        if (isset($_POST['external_link_date'])) {
            $date = sanitize_text_field($_POST['external_link_date']);
            update_post_meta($post_id, '_external_link_date', $date);

            // Set the post category based on the extracted year
            $year = date('Y', strtotime($date));
            $category = get_term_by('name', $year, 'category');
            if ($category) {
                wp_set_object_terms($post_id, $category->term_id, 'category');
                error_log("Set category for post $post_id to $year");
            } else {
                $category_id = wp_create_category($year);
                wp_set_object_terms($post_id, $category_id, 'category');
                error_log("Created new category $year and set it for post $post_id");
            }
        }
    }

    public function fetch_url_info() {
        check_ajax_referer('fetch_url_info', 'nonce');
        
        $url = sanitize_url($_POST['url']);
        error_log("Fetching URL info for: " . $url);

        $response = wp_remote_get($url);

        if (is_wp_error($response)) {
            error_log("Failed to fetch URL: " . $response->get_error_message());
            wp_send_json_error('Failed to fetch URL: ' . $response->get_error_message());
            return;
        }

        $body = wp_remote_retrieve_body($response);
        $title = $this->extract_title($body, $url);
        $date = $this->extract_date($body, $url);

        error_log("Extracted title: " . ($title ? $title : "No title found"));
        error_log("Extracted date: " . ($date ? $date : "No date found"));

        $result = array();
        if ($title) {
            $result['title'] = $title;
        }
        if ($date) {
            $result['date'] = $date;
        }

        if (!empty($result)) {
            error_log("Sending success response: " . json_encode($result));
            wp_send_json_success($result);
        } else {
            $debug_info = array(
                'url' => $url,
                'body_length' => strlen($body),
                'title_found' => ($title !== null),
                'date_found' => ($date !== null)
            );
            error_log("No information found. Debug info: " . json_encode($debug_info));
            wp_send_json_error('No information found. Debug info: ' . json_encode($debug_info));
        }
    }

    private function extract_title($html, $url) {
        // Create a DOM object
        $dom = new simple_html_dom();
        $dom->load($html);

        // Try extracting from Open Graph meta tag
        if ($og_title = $dom->find('meta[property="og:title"]', 0)) {
            return $og_title->content;
        }
        
        // Try standard title tag
        if ($title_tag = $dom->find('title', 0)) {
            return $title_tag->plaintext;
        }

        // Try h1 tags
        if ($h1_tag = $dom->find('h1', 0)) {
            return $h1_tag->plaintext;
        }

        // Fallback to existing URL parsing logic
        $path = parse_url($url, PHP_URL_PATH);
        $path_parts = explode('/', trim($path, '/'));
        $last_part = end($path_parts);
        return ucwords(str_replace('-', ' ', $last_part));
    }

    private function extract_date($html, $url) {
        // Try to extract from schema.org metadata
        if (preg_match('/"datePublished":\s*"([^"]+)"/i', $html, $matches)) {
            return date('Y-m-d', strtotime($matches[1]));
        }

        // Try to extract from Open Graph meta tag
        if (preg_match('/<meta property="article:published_time" content="([^"]+)"/i', $html, $matches)) {
            return date('Y-m-d', strtotime($matches[1]));
        }

        // Try to extract from other common meta tags
        $patterns = array(
            '/<meta name="date" content="([^"]+)"/i',
            '/<time datetime="([^"]+)"/i',
            '/\d{4}[-\/]\d{2}[-\/]\d{2}/',  // Generic date pattern
        );

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html, $matches)) {
                return date('Y-m-d', strtotime($matches[1]));
            }
        }

        // If no date found in the HTML, try to extract from the URL
        if (preg_match('/(\d{4})\/(\d{2})\/(\d{2})/', $url, $matches)) {
            return $matches[1] . '-' . $matches[2] . '-' . $matches[3];
        }

        // If still no date found, return the current date
        return date('Y-m-d');
    }

    public function enqueue_admin_scripts($hook) {
        if ('post.php' != $hook && 'post-new.php' != $hook) {
            return;
        }
        
        wp_enqueue_script('external-links-admin', plugins_url('admin.js', __FILE__), array('jquery'), null, true);
        wp_localize_script('external-links-admin', 'externalLinksAdmin', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('fetch_url_info')
        ));
    }

    public function integrate_with_vc() {
        vc_map(array(
            'name' => 'External Links',
            'base' => 'vc_external_links',
            'category' => 'Content',
            'params' => array(
                array(
                    'type' => 'textfield',
                    'heading' => 'Count',
                    'param_name' => 'count',
                    'value' => 5,
                    'description' => 'Number of external links to display',
                ),
                array(
                    'type' => 'dropdown',
                    'heading' => 'Order By',
                    'param_name' => 'orderby',
                    'value' => array(
                        'Date' => 'date',
                        'Title' => 'title',
                    ),
                    'std' => 'date',
                    'description' => 'Order external links by',
                ),
                array(
                    'type' => 'dropdown',
                    'heading' => 'Order',
                    'param_name' => 'order',
                    'value' => array(
                        'Descending' => 'DESC',
                        'Ascending' => 'ASC',
                    ),
                    'std' => 'DESC',
                    'description' => 'Sort order',
                ),
                array(
                    'type' => 'textfield',
                    'heading' => 'Category',
                    'param_name' => 'category',
                    'description' => 'Enter category ID to filter external links',
                ),
            ),
        ));

        add_shortcode('vc_external_links', array($this, 'vc_external_links_shortcode'));
    }

    public function vc_external_links_shortcode($atts) {
        $atts = shortcode_atts(array(
            'count' => 5,
            'orderby' => 'date',
            'order' => 'DESC',
            'category' => '',
        ), $atts, 'vc_external_links');

        $args = array(
            'post_type' => 'external_link',
            'posts_per_page' => $atts['count'],
            'orderby' => $atts['orderby'],
            'order' => $atts['order'],
        );

        if (!empty($atts['category'])) {
            $args['cat'] = $atts['category'];
        }

        $query = new WP_Query($args);
        $output = '<ul class="external-links-list">';

        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $date = get_post_meta(get_the_ID(), '_external_link_date', true);
                $external_url = get_post_meta(get_the_ID(), '_external_link_url', true);
                $output .= '<li>';
                if ($date) {
                    $output .= '<span class="date">' . date('F j, Y', strtotime($date)) . '</span><br>';
                }
                $output .= '<a href="' . esc_url($external_url) . '" target="_blank" rel="noopener noreferrer">' . get_the_title() . '</a>';
                $output .= '</li>';
            }
        }

        $output .= '</ul>';
        wp_reset_postdata();
        return $output;
    }

    public function load_external_link_template($single_template) {
        global $post;

        if ($post->post_type == 'external_link') {
            $single_template = dirname(__FILE__) . '/single-external_link.php';
        }
        return $single_template;
    }

    public function modify_external_link_permalink($permalink, $post) {
        if ($post->post_type === 'external_link') {
            $external_url = get_post_meta($post->ID, '_external_link_url', true);
            if (!empty($external_url)) {
                return $external_url;
            }
        }
        return $permalink;
    }

    public function modify_external_link_content($content) {
        if (is_singular('external_link')) {
            $external_url = get_post_meta(get_the_ID(), '_external_link_url', true);
            if (!empty($external_url)) {
                $content = '<p>External Link: <a href="' . esc_url($external_url) . '" target="_blank" rel="noopener noreferrer">' . esc_url($external_url) . '</a></p>' . $content;
            }
        }
        return $content;
    }
}

new External_Links_Manager();