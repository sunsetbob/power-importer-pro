<?php
/**
 * 核心导入器类 (V-Final.3 - 最终重构)
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class PIP_Importer {
    private $csv_path;
    private $variable_product_map = [];
    private $row_count = 0;
    private $current_job_id = 0;
    private $current_processing_post_id = 0;
    private $sku_cache = []; // SKU缓存
    private $sku_cache_loaded = false; // 缓存加载状态
    private $max_cache_size = 10000; // 最大缓存数量限制
    
    public function __construct($csv_path, $job_id) {
        $this->csv_path = $csv_path;
        $this->current_job_id = (int)$job_id;
        // 延迟加载SKU缓存，只在需要时加载
    }
    
    /**
     * 智能SKU缓存初始化 - 分页加载，避免内存过载
     */
    private function init_sku_cache() {
        if ($this->sku_cache_loaded) {
            return; // 已加载，避免重复
        }
        
        global $wpdb;
        
        // 先检查SKU总数
        $total_skus = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_sku' AND meta_value != ''"
        );
        
        if ($total_skus > $this->max_cache_size) {
            $this->log("SKU数量过多({$total_skus})，使用实时查询模式以节省内存", 'INFO');
            $this->sku_cache_loaded = true; // 标记为已处理，但不加载缓存
            return;
        }
        
        // 批量查询所有产品的SKU
        $results = $wpdb->get_results(
            "SELECT post_id, meta_value as sku 
             FROM {$wpdb->postmeta} 
             WHERE meta_key = '_sku' 
             AND meta_value != ''
             LIMIT {$this->max_cache_size}",
            ARRAY_A
        );
        
        foreach ($results as $result) {
            $this->sku_cache[$result['sku']] = (int)$result['post_id'];
        }
        
        $this->sku_cache_loaded = true;
        $this->log("SKU缓存已初始化，共加载 " . count($this->sku_cache) . " 个SKU记录", 'INFO');
        
        // 注册清理钩子
        register_shutdown_function([$this, 'cleanup_cache']);
    }
    
    /**
     * 智能SKU查询 - 缓存优先，回退到数据库查询
     */
    private function get_product_id_by_sku_cached($sku) {
        // 确保缓存已初始化
        if (!$this->sku_cache_loaded) {
            $this->init_sku_cache();
        }
        
        // 如果使用缓存模式
        if (!empty($this->sku_cache)) {
            return $this->sku_cache[$sku] ?? 0;
        }
        
        // 回退到实时数据库查询（大数据量时）
        return $this->get_product_id_by_sku_direct($sku);
    }
    
    /**
     * 直接数据库查询（原方法保留）
     */
    private function get_product_id_by_sku_direct($sku) {
        global $wpdb;
        $product_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM $wpdb->postmeta WHERE meta_key='_sku' AND meta_value='%s' LIMIT 1", 
            $sku
        ));
        return $product_id ? (int)$product_id : 0;
    }
    
    /**
     * 更新SKU缓存
     */
    private function update_sku_cache($sku, $product_id) {
        // 只在缓存模式下更新
        if (!empty($this->sku_cache) && count($this->sku_cache) < $this->max_cache_size) {
            $this->sku_cache[$sku] = (int)$product_id;
        }
    }
    
    /**
     * 清理缓存 - 任务完成后自动调用
     */
    public function cleanup_cache() {
        if (!empty($this->sku_cache)) {
            $cache_size = count($this->sku_cache);
            $this->sku_cache = [];
            $this->log("SKU缓存已清理，释放了 {$cache_size} 个缓存项", 'INFO');
        }
    }
    
    /**
     * 获取缓存统计信息
     */
    public function get_cache_stats() {
        return [
            'cache_loaded' => $this->sku_cache_loaded,
            'cache_size' => count($this->sku_cache),
            'max_cache_size' => $this->max_cache_size,
            'memory_usage' => memory_get_usage(true),
            'memory_peak' => memory_get_peak_usage(true)
        ];
    }

    public function run() {
        try {
            pip_db()->update_job($this->current_job_id, ['status' => 'running', 'started_at' => current_time('mysql', 1)]);
            $this->log(sprintf(__( '--- Import task #%d started ---', 'power-importer-pro' ), $this->current_job_id), 'INFO');

            add_filter( 'upload_dir', [ $this, 'custom_upload_dir' ] );

            if (!file_exists($this->csv_path)) {
                throw new Exception(sprintf(__( 'CSV file not found at path: %s', 'power-importer-pro' ), esc_html($this->csv_path)));
            }

            $file_content = file($this->csv_path, FILE_SKIP_EMPTY_LINES);
            if (false === $file_content) {
                 throw new Exception(sprintf(__( 'Cannot read CSV file: %s', 'power-importer-pro' ), esc_html($this->csv_path)));
            }
            $total_rows = max(0, count($file_content) - 1); // Exclude header row for total count
            pip_db()->update_job($this->current_job_id, ['total_rows' => $total_rows]);

            if (($handle = fopen($this->csv_path, "r")) !== FALSE) {
                $headers = array_map('trim', fgetcsv($handle)); // Read and trim headers
                while (($data = fgetcsv($handle)) !== FALSE) {
                    $this->row_count++;
                    if (count($data) !== count($headers)) {
                        $this->log(sprintf(__( '⚠️ [Row: %d] Column count does not match headers. Skipped.', 'power-importer-pro' ), $this->row_count), 'WARNING');
                        continue;
                    }
                    $product_data = array_combine($headers, $data);
                    $this->process_row($product_data); // process_row will handle its own logging for success/failure of individual items
                    if ($this->row_count % 20 === 0) { // Update progress periodically
                        pip_db()->update_job($this->current_job_id, ['processed_rows' => $this->row_count]);
                    }
                }
                fclose($handle);
            }
            
            pip_db()->update_job($this->current_job_id, ['processed_rows' => $this->row_count]); // Final update of processed rows
            $this->log(__( '--- Import execution finished ---', 'power-importer-pro' ), 'SUCCESS');
            pip_db()->update_job($this->current_job_id, ['status' => 'completed', 'finished_at' => current_time('mysql', 1)]);

        } catch (Exception $e) {
            // Ensure exception messages that might be technical are not directly shown if $e->getMessage() is used in UI.
            // Here, it's for logging, so it's okay, but if this message bubbles up, it needs care.
            $this->log(sprintf(__( 'Fatal error caused task interruption: %s', 'power-importer-pro' ), $e->getMessage()), 'ERROR');
            pip_db()->update_job($this->current_job_id, ['status' => 'failed', 'finished_at' => current_time('mysql', 1), 'error_message' => $e->getMessage()]);
        }

        remove_filter( 'upload_dir', [ $this, 'custom_upload_dir' ] );
    }
    
    private function log($message, $level = 'INFO') {
        pip_db()->add_log($this->current_job_id, $message, $level);
    }

    public function custom_upload_dir( $param ) {
        $base_dir = '/images'; $category_slug = 'uncategorized';
        $post_id = $this->current_processing_post_id;
        if ( $post_id > 0 ) {
            $terms = get_the_terms( $post_id, 'product_cat' );
            if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
                $deepest_term = null;
                foreach ( $terms as $term ) { $children = get_term_children( $term->term_id, 'product_cat' ); if ( empty( $children ) ) { $deepest_term = $term; break; } }
                if ( ! $deepest_term ) { $deepest_term = reset($terms); }
                if ($deepest_term) { $category_slug = $deepest_term->slug; }
            }
        }
        $subdir = $base_dir . '/' . $category_slug;
        $param['path']   = $param['basedir'] . $subdir; $param['url']    = $param['baseurl'] . $subdir; $param['subdir'] = $subdir;
        if ( ! file_exists( $param['path'] ) ) {
            if ( ! wp_mkdir_p( $param['path'] ) ) {
                $this->log( sprintf(__( '⚠️ Could not create custom image directory %s. Default directory will be used.', 'power-importer-pro' ), esc_html($param['path']) ), 'WARNING' );
                remove_filter('upload_dir', [$this, 'custom_upload_dir']);
                return $GLOBALS['wp_upload_dir']; // Return original WordPress upload dir array
            }
        }
        return $param;
    }
    
    private function process_row($product_data) {
        $type = strtolower(trim($product_data['Type'] ?? 'simple'));
        $name = trim($product_data['Name'] ?? 'Untitled Product');
        switch ($type) {
            case 'simple': case 'variable': $this->process_parent_product($product_data, $type, $name); break;
            case 'variation': $this->process_variation_product($product_data, $name); break;
            default: $this->log("❓ [行号: {$this->row_count}] 未知的产品类型 '{$type}'，已跳过。", 'WARNING'); break;
        }
    }
    
    private function process_parent_product($data, $type, $name) {
        $sku = trim($data['SKU']);
        if (empty($sku)) {
            $this->log(sprintf("❌ [Row: %d] Product type '%s' with name '%s' has empty SKU. Skipped.", $this->row_count, esc_html($type), esc_html($name)), 'ERROR');
            return;
        }
        if ($existing_id = $this->get_product_id_by_sku_cached($sku)) {
            $this->log(sprintf("↪️ [Row: %d] SKU '%s' already exists (ID: %d). Skipped product '%s'.", $this->row_count, esc_html($sku), $existing_id, esc_html($name)), 'INFO');
            $this->variable_product_map[$sku] = $existing_id;
            return;
        }
        kses_remove_filters();
        $post_id = wp_insert_post(['post_title' => $name, 'post_content' => $data['Description'] ?? '', 'post_excerpt' => $data['Short description'] ?? '', 'post_status'  => 'publish', 'post_type' => 'product']);
        kses_init_filters();
        if (is_wp_error($post_id) || !$post_id) {
            $error_message = is_wp_error($post_id) ? $post_id->get_error_message() : __('Returned invalid product ID (0).', 'power-importer-pro');
            $this->log(sprintf("❌ [Row: %d] Failed to create product '%s': %s", $this->row_count, esc_html($name), $error_message), 'ERROR');
            return;
        }
        
        $this->variable_product_map[$sku] = $post_id;
        wp_set_object_terms($post_id, $type, 'product_type');
        update_post_meta($post_id, '_sku', $sku);
        $this->update_sku_cache($sku, $post_id);
        $this->set_categories($post_id, $data['Categories'] ?? '');
        
        $this->current_processing_post_id = $post_id;

        $regular_price = $data['Regular price'] ?? ''; $sale_price = $data['Sale price'] ?? '';
        update_post_meta($post_id, '_regular_price', $regular_price);
        if (!empty($sale_price)) { update_post_meta($post_id, '_sale_price', $sale_price); update_post_meta($post_id, '_price', $sale_price); } else { update_post_meta($post_id, '_price', $regular_price); }
        update_post_meta($post_id, '_manage_stock', 'no'); update_post_meta($post_id, '_stock_status', 'instock');
        if (!empty($data['Tags'])) { $tags = array_map('trim', explode(',', $data['Tags'])); wp_set_object_terms($post_id, $tags, 'product_tag'); }
        $this->set_attributes($post_id, $data, $type === 'variable');
        if (!empty($data['brands']) && taxonomy_exists('product_brand')) { wp_set_object_terms($post_id, trim($data['brands']), 'product_brand', true); }
        if (!empty($data['Meta: rank_math_focus_keyword'])) update_post_meta($post_id, 'rank_math_focus_keyword', $data['Meta: rank_math_focus_keyword']);
        if (!empty($data['Meta: rank_math_title'])) update_post_meta($post_id, 'rank_math_title', $data['Meta: rank_math_title']);
        if (!empty($data['Meta: rank_math_description'])) update_post_meta($post_id, 'rank_math_description', $data['Meta: rank_math_description']);
        
        $this->set_images($post_id, $data['Images'] ?? '', $name);
        $this->current_processing_post_id = 0;
        
        $this->log(sprintf("✅ [Row: %d] Successfully created %s product: '%s' (ID: %d)", $this->row_count, esc_html($type), esc_html($name), $post_id), 'SUCCESS');
    }

    private function process_variation_product($data, $name) {
        $sku = trim($data['SKU']);
        if (empty($sku)) {
            $this->log(sprintf("  ↳ [Row: %d] Variation SKU is empty for '%s'. Skipped.", $this->row_count, esc_html($name)), 'INFO');
            return;
        }
        if ($this->get_product_id_by_sku_cached($sku)) {
            $this->log(sprintf("  ↳ [Row: %d] Variation SKU '%s' already exists. Skipped creation for '%s'.", $this->row_count, esc_html($sku), esc_html($name)), 'INFO');
            return;
        }
        
        $parent_sku = trim($data['Parent']); $parent_id = null;
        if (isset($this->variable_product_map[$parent_sku])) { $parent_id = $this->variable_product_map[$parent_sku]; } else { $parent_id = $this->get_product_id_by_sku_cached($parent_sku); }
        if (!$parent_id) {
            $this->log(sprintf("❌ [Row: %d] Variation '%s' (SKU: %s) could not find parent product with SKU '%s'. Skipped.", $this->row_count, esc_html($name), esc_html($sku), esc_html($parent_sku)), 'ERROR');
            return;
        }
        
        $this->current_processing_post_id = $parent_id; // Set for image directory context
        $this->variable_product_map[$parent_sku] = $parent_id; // Ensure parent is mapped

        $variation = new WC_Product_Variation(); $variation->set_parent_id($parent_id);
        $attributes = [];
        // Assuming only one attribute for variation for simplicity, as per original logic. Expand if needed.
        if (!empty($data['Attribute 1 name']) && !empty($data['Attribute 1 value(s)'])) {
            $attr_name = wc_sanitize_taxonomy_name(trim($data['Attribute 1 name']));
            $attr_value = trim($data['Attribute 1 value(s)']);
            // For global attributes, the slug should be used for the key.
            // For local attributes, the name is used. This assumes global attributes are pre-registered with correct slugs.
            $attribute_taxonomy_name = $attr_name; // Fallback for local if not found as global
            $parent_product = wc_get_product($parent_id);
            if($parent_product){
                foreach($parent_product->get_attributes() as $parent_attr_key => $parent_attr_obj){
                    if( $parent_attr_obj->get_name() === $attr_name || $parent_attr_key === $attr_name || $parent_attr_key === 'pa_' . $attr_name){
                         $attribute_taxonomy_name = $parent_attr_key; // Use the exact key from parent
                         break;
                    }
                }
            }
            $attributes[$attribute_taxonomy_name] = $attr_value;
        }
        $variation->set_attributes($attributes);
        $variation->set_sku($sku); $variation->set_regular_price($data['Regular price'] ?? '');
        if (!empty($data['Sale price'])) { $variation->set_sale_price($data['Sale price'] ?? ''); }
        $variation->set_manage_stock(false); // Default for variations unless specified
        $variation->set_stock_status('instock'); // Default
        
        $variation_id = $variation->save();
        if (is_wp_error($variation_id)) {
            $this->log(sprintf("❌ [Row: %d] Failed to save variation for SKU '%s': %s", $this->row_count, esc_html($sku), $variation_id->get_error_message()), 'ERROR');
            return;
        }
        $this->update_sku_cache($sku, $variation_id);
        
        $parent_product_title = get_the_title($parent_id);
        $variation_image_alt = $parent_product_title . ' - ' . ($data['Attribute 1 value(s)'] ?? $sku);
        $this->set_images($variation_id, $data['Images'] ?? '', $variation_image_alt, true);
        $this->current_processing_post_id = 0; // Reset after image processing
        
        $this->log(sprintf("  ↳ [Row: %d] Successfully created variation (ID: %d) for SKU '%s', linked to parent '%s'.", $this->row_count, $variation_id, esc_html($sku), esc_html($parent_sku)), 'SUCCESS');
    }

    private function set_images($post_id, $images_str, $post_title, $is_variation = false) {
        if (empty($images_str)) return;
        $image_urls = array_filter(array_map('trim', explode(',', $images_str)));
        $gallery_ids = []; $thumbnail_set = false;
        foreach ($image_urls as $index => $url) {
            $filename = sanitize_title($post_title) . '-' . $post_id . '-' . ($index + 1);
            $attachment_id = pip_wp_save_img($url, $filename, $post_title);
            if ($attachment_id && !is_wp_error($attachment_id)) {
                if (!$thumbnail_set) {
                    if($is_variation) {
                        update_post_meta($post_id, '_thumbnail_id', $attachment_id);
                    } else {
                        set_post_thumbnail($post_id, $attachment_id);
                    }
                    $thumbnail_set = true;
                } else {
                    if (!$is_variation) {
                        $gallery_ids[] = $attachment_id;
                    }
                }
            } else {
                $error_detail = is_wp_error($attachment_id) ? $attachment_id->get_error_message() : 'Unknown error';
                $this->log(sprintf("  - ⚠️ " . __( 'Image download or processing failed for URL: %s. Error: %s', 'power-importer-pro' ), esc_url($url), esc_html($error_detail)), 'WARNING');
            }
        }
        if (!$is_variation && !empty($gallery_ids)) { update_post_meta($post_id, '_product_image_gallery', implode(',', $gallery_ids)); }
    }

    private function set_categories($post_id, $categories_str) {
        if (empty($categories_str)) return;
        $category_paths = array_map('trim', explode(',', $categories_str)); $term_ids = [];
        foreach ($category_paths as $path) {
            $parts = array_map('trim', explode('>', $path)); $parent_id = 0; $term_id = 0;
            foreach ($parts as $part) {
                $term = term_exists($part, 'product_cat', $parent_id);
                if (!$term) { $term = wp_insert_term($part, 'product_cat', ['parent' => $parent_id]); }
                $term_id = is_array($term) ? $term['term_id'] : $term; $parent_id = $term_id;
            }
            if ($term_id) $term_ids[] = intval($term_id);
        }
        if (!empty($term_ids)) { wp_set_object_terms($post_id, array_unique($term_ids), 'product_cat'); }
    }
    
    private function set_attributes($post_id, $data, $is_for_variation) {
        $attributes = [];
        for ($i = 1; $i <= 3; $i++) {
            $name_key = "Attribute $i name"; $value_key = "Attribute $i value(s)"; $global_key = "Attribute $i global"; $visible_key = "Attribute $i visible";
            if (!empty($data[$name_key]) && !empty($data[$value_key])) {
                $attr_name = trim($data[$name_key]); $is_global = ($data[$global_key] ?? '0') === '1';
                $taxonomy_name = $is_global ? wc_attribute_taxonomy_name($attr_name) : wc_sanitize_taxonomy_name($attr_name);
                $values = array_map('trim', explode(',', $data[$value_key]));
                if($is_global){
                    if (!taxonomy_exists($taxonomy_name)) { wc_create_attribute(['name' => $attr_name, 'slug' => wc_sanitize_taxonomy_name($attr_name)]); register_taxonomy($taxonomy_name, ['product', 'product_variation']); }
                    foreach($values as $value){ if(!term_exists($value, $taxonomy_name)){ wp_insert_term($value, $taxonomy_name); } }
                    wp_set_object_terms($post_id, $values, $taxonomy_name);
                }
                $attributes[$taxonomy_name] = [ 'name' => $is_global ? $taxonomy_name : $attr_name, 'value' => implode(' | ', $values), 'position' => $i - 1, 'is_visible' => ($data[$visible_key] ?? '0') === '1' ? 1 : 0, 'is_variation' => $is_for_variation, 'is_taxonomy' => $is_global ? 1 : 0, ];
            }
        }
        if (!empty($attributes)) { update_post_meta($post_id, '_product_attributes', $attributes); }
    }

    /**
     * 新增：设置行计数器（用于AJAX分片处理）
     */
    public function set_row_count($row_count) {
        $this->row_count = $row_count;
    }
    
    /**
     * 新增：处理单行数据（用于AJAX分片处理）
     */
    public function process_single_row($product_data) {
        $this->process_row($product_data);
    }
    
    /**
     * 新增：获取变量产品映射（用于变体产品处理）
     */
    public function get_variable_product_map() {
        return $this->variable_product_map;
    }
    
    /**
     * 新增：设置变量产品映射（用于变体产品处理）
     */
    public function set_variable_product_map($map) {
        $this->variable_product_map = $map;
    }
}