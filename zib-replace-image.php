<?php
/**
 * Plugin Name: WP替换帖子图片
 * Plugin URI: https://github.com/dfgdsgfd/wpqy
 * Description: 批量替换文章中的图片链接，支持域名替换、路径替换、去除-scaled后缀等操作。
 * Version: 1.0.0
 * Author: Qinver
 * Author URI: https://zibll.com
 * License: GPL-2.0+
 * Text Domain: zib-replace-image
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * @description: 注册后台侧边栏菜单
 */
function zib_replace_image_menu()
{
    add_menu_page(
        '替换帖子图片',
        '替换帖子图片',
        'manage_options',
        'zib-replace-image',
        'zib_replace_image_page',
        'dashicons-images-alt2',
        80
    );
}
add_action('admin_menu', 'zib_replace_image_menu');

/**
 * @description: 对文本内容执行替换操作
 * @param {string} $content 原始内容
 * @param {string} $old_domain 旧域名（已规范化）
 * @param {string} $new_domain 新域名（已规范化）
 * @param {bool} $remove_scaled 是否去除-scaled
 * @param {bool} $remove_date_dir 是否将/wp-content/uploads/YYYY/MM/替换为/wp-content/uploads/tc/
 * @return {string} 替换后的内容
 */
function zib_replace_content($content, $old_domain, $new_domain, $remove_scaled, $remove_date_dir)
{
    // 替换域名
    if (!empty($old_domain) && !empty($new_domain)) {
        $content = str_replace($old_domain, $new_domain, $content);
    }

    // 固定替换路径：将 /wp-content/uploads/YYYY/MM/ 替换为 /wp-content/uploads/tc/
    if ($remove_date_dir) {
        $content = preg_replace('/\/wp-content\/uploads\/\d{4}\/\d{2}\//', '/wp-content/uploads/tc/', $content);
    }

    // 去除-scaled
    if ($remove_scaled) {
        $content = preg_replace('/-scaled(\.(webp|jpg|jpeg|png|gif|bmp|svg))/i', '$1', $content);
    }

    return $content;
}

/**
 * @description: 获取数据库诊断信息
 * @param {string} $search_keyword 搜索关键词
 * @return {array} 诊断结果
 */
function zib_replace_image_diagnose($search_keyword)
{
    global $wpdb;

    $diag = array(
        'db_error'       => '',
        'posts_table'    => $wpdb->posts,
        'postmeta_table' => $wpdb->postmeta,
        'total_posts'    => 0,
        'sample_urls'    => array(),
    );

    // 统计数据库中的总文章数
    $diag['total_posts'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts}");
    if ($wpdb->last_error) {
        $diag['db_error'] = $wpdb->last_error;
        return $diag;
    }

    // 从数据库中提取包含 http 的文章内容片段，帮助用户了解实际存储的 URL 格式
    $sample_rows = $wpdb->get_results(
        "SELECT ID, post_title, SUBSTRING(post_content, 1, 500) AS content_sample FROM {$wpdb->posts} WHERE post_content LIKE '%http%' AND post_type NOT IN ('revision','nav_menu_item','customize_changeset') LIMIT 3"
    );
    if ($sample_rows) {
        foreach ($sample_rows as $row) {
            // 提取内容中的 URL
            if (preg_match_all('/https?:\/\/[^\s"\'<>\)]+/', $row->content_sample, $matches)) {
                foreach (array_slice($matches[0], 0, 2) as $url) {
                    $diag['sample_urls'][] = array(
                        'post_id'    => $row->ID,
                        'post_title' => $row->post_title,
                        'url'        => $url,
                    );
                }
            }
        }
    }

    return $diag;
}

/**
 * @description: 处理替换请求
 * @param {string} $old_domain 旧域名
 * @param {string} $new_domain 新域名
 * @param {bool} $remove_scaled 是否去除-scaled
 * @param {bool} $remove_date_dir 是否将/wp-content/uploads/YYYY/MM/替换为/wp-content/uploads/tc/
 * @param {bool} $dry_run 是否仅预览
 * @return {array} 替换结果
 */
function zib_replace_image_process($old_domain, $new_domain, $remove_scaled = true, $remove_date_dir = false, $dry_run = true)
{
    global $wpdb;

    // 规范化域名，去除尾部斜杠
    $old_domain = rtrim($old_domain, '/');
    $new_domain = rtrim($new_domain, '/');

    $results = array(
        'total'        => 0,
        'changed'      => 0,
        'meta_total'   => 0,
        'meta_changed' => 0,
        'details'      => array(),
        'search_term'  => $old_domain,
        'db_error'     => '',
        'diagnostics'  => zib_replace_image_diagnose($old_domain),
    );

    $search_term = '%' . $wpdb->esc_like($old_domain) . '%';

    // 搜索 wp_posts：在 post_content 和 post_excerpt 中查找，不限制任何类型和状态
    $posts = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT ID, post_title, post_content, post_excerpt, post_type, post_status FROM {$wpdb->posts} WHERE post_content LIKE %s OR post_excerpt LIKE %s",
            $search_term,
            $search_term
        )
    );

    if ($wpdb->last_error) {
        $results['db_error'] = $wpdb->last_error;
    }

    if (!is_array($posts)) {
        $posts = array();
    }

    $results['total'] = count($posts);

    foreach ($posts as $post) {
        $old_content = $post->post_content;
        $old_excerpt = $post->post_excerpt;

        $new_content = zib_replace_content($old_content, $old_domain, $new_domain, $remove_scaled, $remove_date_dir);
        $new_excerpt = zib_replace_content($old_excerpt, $old_domain, $new_domain, $remove_scaled, $remove_date_dir);

        if ($old_content !== $new_content || $old_excerpt !== $new_excerpt) {
            $results['changed']++;
            $results['details'][] = array(
                'id'     => $post->ID,
                'title'  => $post->post_title,
                'type'   => $post->post_type,
                'status' => $post->post_status,
                'source' => '文章内容',
            );

            if (!$dry_run) {
                $update_data = array();
                $update_format = array();
                if ($old_content !== $new_content) {
                    $update_data['post_content'] = $new_content;
                    $update_format[] = '%s';
                }
                if ($old_excerpt !== $new_excerpt) {
                    $update_data['post_excerpt'] = $new_excerpt;
                    $update_format[] = '%s';
                }
                $wpdb->update(
                    $wpdb->posts,
                    $update_data,
                    array('ID' => $post->ID),
                    $update_format,
                    array('%d')
                );
                clean_post_cache($post->ID);
            }
        }
    }

    // 搜索 wp_postmeta：在 meta_value 中查找（排除序列化的值，避免数据损坏）
    $metas = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT meta_id, post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE meta_value LIKE %s AND meta_value NOT LIKE %s AND meta_value NOT LIKE %s",
            $search_term,
            'a:%',
            'O:%'
        )
    );

    if (!is_array($metas)) {
        $metas = array();
    }

    $results['meta_total'] = count($metas);
    $meta_post_ids = array();
    $title_cache = array();

    foreach ($metas as $meta) {
        $old_value = $meta->meta_value;
        $new_value = zib_replace_content($old_value, $old_domain, $new_domain, $remove_scaled, $remove_date_dir);

        if ($old_value !== $new_value) {
            if (!isset($meta_post_ids[$meta->post_id])) {
                $meta_post_ids[$meta->post_id] = true;
                $results['meta_changed']++;

                if (!isset($title_cache[$meta->post_id])) {
                    $title_cache[$meta->post_id] = $wpdb->get_var($wpdb->prepare("SELECT post_title FROM {$wpdb->posts} WHERE ID = %d", $meta->post_id));
                }
                $results['details'][] = array(
                    'id'     => $meta->post_id,
                    'title'  => $title_cache[$meta->post_id] ? $title_cache[$meta->post_id] : '(无标题)',
                    'type'   => 'postmeta',
                    'status' => $meta->meta_key,
                    'source' => '文章元数据',
                );
            }

            if (!$dry_run) {
                $wpdb->update(
                    $wpdb->postmeta,
                    array('meta_value' => $new_value),
                    array('meta_id' => $meta->meta_id),
                    array('%s'),
                    array('%d')
                );
            }
        }
    }

    return $results;
}

/**
 * @description: 后台管理页面
 */
function zib_replace_image_page()
{
    if (!current_user_can('manage_options')) {
        wp_die('您没有权限访问此页面。');
    }

    $message = '';
    $results = null;

    // 处理表单提交
    if (isset($_POST['zib_replace_action'])) {
        if (!isset($_POST['zib_replace_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['zib_replace_nonce'])), 'zib_replace_image_action')) {
            wp_die('安全验证失败，请重试。');
        }

        $old_domain     = isset($_POST['old_domain']) ? sanitize_text_field(wp_unslash($_POST['old_domain'])) : '';
        $new_domain     = isset($_POST['new_domain']) ? sanitize_text_field(wp_unslash($_POST['new_domain'])) : '';
        $remove_scaled  = isset($_POST['remove_scaled']) ? true : false;
        $remove_date_dir = isset($_POST['remove_date_dir']) ? true : false;
        $action_type    = sanitize_text_field(wp_unslash($_POST['zib_replace_action']));

        if (empty($old_domain)) {
            $message = '<div class="notice notice-error"><p>请填写旧域名。</p></div>';
        } else {
            $dry_run = ($action_type === 'preview');
            $results = zib_replace_image_process($old_domain, $new_domain, $remove_scaled, $remove_date_dir, $dry_run);

            if ($dry_run) {
                $message = '<div class="notice notice-info"><p>预览完成：<br>'
                    . '搜索关键词：<code>' . esc_html($results['search_term']) . '</code><br>'
                    . '文章内容：共找到 ' . esc_html($results['total']) . ' 篇包含旧链接的文章，其中 ' . esc_html($results['changed']) . ' 篇将被修改。<br>'
                    . '文章元数据：共找到 ' . esc_html($results['meta_total']) . ' 条包含旧链接的元数据，涉及 ' . esc_html($results['meta_changed']) . ' 篇文章将被修改。</p></div>';

                // 如果找到 0 篇文章，显示诊断信息帮助排查问题
                if ($results['total'] === 0 && $results['meta_total'] === 0 && !empty($results['diagnostics'])) {
                    $diag = $results['diagnostics'];
                    $message .= '<div class="notice notice-warning"><p><strong>诊断信息：</strong><br>';
                    $message .= '数据库表：<code>' . esc_html($diag['posts_table']) . '</code> / <code>' . esc_html($diag['postmeta_table']) . '</code><br>';
                    $message .= '数据库中总记录数：' . esc_html($diag['total_posts']) . ' 条<br>';

                    if (!empty($diag['db_error'])) {
                        $message .= '<span style="color:red;">数据库错误：' . esc_html($diag['db_error']) . '</span><br>';
                    }

                    if (!empty($results['db_error'])) {
                        $message .= '<span style="color:red;">查询错误：' . esc_html($results['db_error']) . '</span><br>';
                    }

                    if (!empty($diag['sample_urls'])) {
                        $message .= '<br><strong>数据库中的 URL 示例（帮助确认实际存储格式）：</strong><br>';
                        foreach ($diag['sample_urls'] as $sample) {
                            $message .= '文章 #' . esc_html($sample['post_id']) . ' (' . esc_html($sample['post_title']) . '): <code>' . esc_html($sample['url']) . '</code><br>';
                        }
                    } else {
                        $message .= '<em>未在数据库文章内容中找到任何 http 链接。</em><br>';
                    }

                    if ($diag['total_posts'] === 0) {
                        $message .= '<br><span style="color:red;"><strong>⚠ 数据库中没有任何文章记录，请检查数据库连接和表前缀是否正确。</strong></span><br>';
                    }

                    $message .= '</p></div>';
                }
            } else {
                $message = '<div class="notice notice-success"><p>替换完成：<br>'
                    . '文章内容：共处理 ' . esc_html($results['total']) . ' 篇文章，成功修改 ' . esc_html($results['changed']) . ' 篇。<br>'
                    . '文章元数据：共处理 ' . esc_html($results['meta_total']) . ' 条元数据，涉及 ' . esc_html($results['meta_changed']) . ' 篇文章已修改。</p></div>';
            }
        }
    }

    ?>
    <div class="wrap">
        <h1>替换帖子图片链接</h1>
        <p class="description">批量替换文章中的图片链接。支持域名替换、自动将 <code>/wp-content/uploads/YYYY/MM/</code> 替换为 <code>/wp-content/uploads/tc/</code>、去除-scaled后缀等操作。</p>
        <p class="description"><strong>示例：</strong><br>
            旧链接：<code>https://wp-cs-files-tc.yuelk.com/wp-content/uploads/2025/08/20250826212841535-IMG_0546-scaled.webp</code><br>
            新链接：<code>http://192.168.50.154/wp-content/uploads/tc/20250826212841535-IMG_0546.webp</code><br>
            <small>（自动去除 <code>2025/08/</code> 日期目录和 <code>-scaled</code> 后缀）</small>
        </p>

        <?php echo wp_kses_post($message); ?>

        <form method="post" action="">
            <?php wp_nonce_field('zib_replace_image_action', 'zib_replace_nonce'); ?>

            <table class="form-table">
                <tr>
                    <th scope="row"><label for="old_domain">旧域名</label></th>
                    <td>
                        <input type="text" id="old_domain" name="old_domain" class="regular-text"
                               placeholder="例如：https://wp-cs-files-tc.yuelk.com"
                               value="<?php echo isset($_POST['old_domain']) ? esc_attr(sanitize_text_field(wp_unslash($_POST['old_domain']))) : ''; ?>">
                        <p class="description">要替换的旧域名，包含协议（http/https）</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="new_domain">新域名</label></th>
                    <td>
                        <input type="text" id="new_domain" name="new_domain" class="regular-text"
                               placeholder="例如：http://192.168.50.154"
                               value="<?php echo isset($_POST['new_domain']) ? esc_attr(sanitize_text_field(wp_unslash($_POST['new_domain']))) : ''; ?>">
                        <p class="description">替换后的新域名，包含协议（http/https）</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">去除-scaled</th>
                    <td>
                        <label for="remove_scaled">
                            <input type="checkbox" id="remove_scaled" name="remove_scaled" value="1"
                                <?php echo (!isset($_POST['zib_replace_action']) || isset($_POST['remove_scaled'])) ? 'checked' : ''; ?>>
                            去除图片文件名中的 <code>-scaled</code> 后缀
                        </label>
                        <p class="description">例如：<code>image-scaled.webp</code> → <code>image.webp</code></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">替换日期目录为tc</th>
                    <td>
                        <label for="remove_date_dir">
                            <input type="checkbox" id="remove_date_dir" name="remove_date_dir" value="1"
                                <?php echo (!isset($_POST['zib_replace_action']) || isset($_POST['remove_date_dir'])) ? 'checked' : ''; ?>>
                            将上传路径中的 <code>/wp-content/uploads/YYYY/MM/</code> 替换为 <code>/wp-content/uploads/tc/</code>
                        </label>
                        <p class="description">例如：<code>/wp-content/uploads/2025/08/image.webp</code> → <code>/wp-content/uploads/tc/image.webp</code></p>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <button type="submit" name="zib_replace_action" value="preview" class="button button-secondary">预览更改</button>
                &nbsp;&nbsp;
                <button type="submit" name="zib_replace_action" value="execute" class="button button-primary"
                        onclick="return confirm('确定要执行替换操作吗？此操作不可撤销，建议先备份数据库！');">执行替换</button>
            </p>
        </form>

        <?php if ($results && !empty($results['details'])) : ?>
            <h2>受影响的文章列表</h2>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width:80px;">文章ID</th>
                        <th>文章标题</th>
                        <th style="width:100px;">类型</th>
                        <th style="width:100px;">状态</th>
                        <th style="width:100px;">来源</th>
                        <th style="width:100px;">操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($results['details'] as $detail) : ?>
                        <tr>
                            <td><?php echo esc_html($detail['id']); ?></td>
                            <td><?php echo esc_html($detail['title']); ?></td>
                            <td><?php echo esc_html($detail['type']); ?></td>
                            <td><?php echo esc_html($detail['status']); ?></td>
                            <td><?php echo esc_html($detail['source']); ?></td>
                            <td><a href="<?php echo esc_url(get_edit_post_link($detail['id'])); ?>" target="_blank">编辑</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php
}
