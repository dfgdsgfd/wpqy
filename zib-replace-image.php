<?php
/*
 * @Author        : Qinver
 * @Url           : zibll.com
 * @Date          : 2024-01-01 00:00:00
 * @LastEditTime  : 2024-01-01 00:00:00
 * @Email         : 770349780@qq.com
 * @Project       : Zibll子比主题
 * @Description   : WP替换帖子图片插件
 * @Read me       : 批量替换文章中的图片链接，支持域名替换、路径替换、去除scaled等操作
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
 * @description: 处理替换请求
 * @param {string} $old_domain 旧域名
 * @param {string} $new_domain 新域名
 * @param {string} $old_path 旧路径前缀
 * @param {string} $new_path 新路径前缀
 * @param {bool} $remove_scaled 是否去除-scaled
 * @param {bool} $dry_run 是否仅预览
 * @return {array} 替换结果
 */
function zib_replace_image_process($old_domain, $new_domain, $old_path, $new_path, $remove_scaled = true, $dry_run = true)
{
    global $wpdb;

    $results = array(
        'total'   => 0,
        'changed' => 0,
        'details' => array(),
    );

    // 构建搜索关键词：用旧域名在帖子内容中查找
    $posts = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT ID, post_title, post_content FROM {$wpdb->posts} WHERE post_content LIKE %s AND post_status IN ('publish','draft','pending','private')",
            '%' . $wpdb->esc_like($old_domain) . '%'
        )
    );

    $results['total'] = count($posts);

    foreach ($posts as $post) {
        $old_content = $post->post_content;
        $new_content = $old_content;

        // 替换域名
        if (!empty($old_domain) && !empty($new_domain)) {
            $new_content = str_replace($old_domain, $new_domain, $new_content);
        }

        // 替换路径前缀
        if (!empty($old_path) && !empty($new_path)) {
            $new_content = str_replace($old_path, $new_path, $new_content);
        }

        // 去除-scaled
        if ($remove_scaled) {
            $new_content = preg_replace('/-scaled(\.(webp|jpg|jpeg|png|gif|bmp|svg))/i', '$1', $new_content);
        }

        if ($old_content !== $new_content) {
            $results['changed']++;
            $results['details'][] = array(
                'id'    => $post->ID,
                'title' => $post->post_title,
            );

            if (!$dry_run) {
                $wpdb->update(
                    $wpdb->posts,
                    array('post_content' => $new_content),
                    array('ID' => $post->ID),
                    array('%s'),
                    array('%d')
                );
                // 清除缓存
                clean_post_cache($post->ID);
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
        $old_path       = isset($_POST['old_path']) ? sanitize_text_field(wp_unslash($_POST['old_path'])) : '';
        $new_path       = isset($_POST['new_path']) ? sanitize_text_field(wp_unslash($_POST['new_path'])) : '';
        $remove_scaled  = isset($_POST['remove_scaled']) ? true : false;
        $action_type    = sanitize_text_field(wp_unslash($_POST['zib_replace_action']));

        if (empty($old_domain)) {
            $message = '<div class="notice notice-error"><p>请填写旧域名。</p></div>';
        } else {
            $dry_run = ($action_type === 'preview');
            $results = zib_replace_image_process($old_domain, $new_domain, $old_path, $new_path, $remove_scaled, $dry_run);

            if ($dry_run) {
                $message = '<div class="notice notice-info"><p>预览完成：共找到 ' . esc_html($results['total']) . ' 篇包含旧链接的文章，其中 ' . esc_html($results['changed']) . ' 篇将被修改。</p></div>';
            } else {
                $message = '<div class="notice notice-success"><p>替换完成：共处理 ' . esc_html($results['total']) . ' 篇文章，成功修改 ' . esc_html($results['changed']) . ' 篇。</p></div>';
            }
        }
    }

    ?>
    <div class="wrap">
        <h1>替换帖子图片链接</h1>
        <p class="description">批量替换文章中的图片链接。支持域名替换、路径替换、去除-scaled后缀等操作。</p>
        <p class="description"><strong>示例：</strong><br>
            旧链接：<code>https://wp-cs-files-tc.yuelk.com/wp-content/uploads/2025/08/20250826212841535-IMG_0546-scaled.webp</code><br>
            新链接：<code>http://192.168.50.154/wp-content/uploads/tc/20250901231003714-IMG_0748.webp</code>
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
                    <th scope="row"><label for="old_path">旧路径前缀</label></th>
                    <td>
                        <input type="text" id="old_path" name="old_path" class="regular-text"
                               placeholder="例如：wp-content/uploads/2025/08/">
                        <p class="description">要替换的旧路径前缀（可选），如 <code>wp-content/uploads/2025/08/</code></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="new_path">新路径前缀</label></th>
                    <td>
                        <input type="text" id="new_path" name="new_path" class="regular-text"
                               placeholder="例如：wp-content/uploads/tc/">
                        <p class="description">替换后的新路径前缀（可选），如 <code>wp-content/uploads/tc/</code></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">去除-scaled</th>
                    <td>
                        <label for="remove_scaled">
                            <input type="checkbox" id="remove_scaled" name="remove_scaled" value="1" checked>
                            去除图片文件名中的 <code>-scaled</code> 后缀
                        </label>
                        <p class="description">例如：<code>image-scaled.webp</code> → <code>image.webp</code></p>
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
                        <th style="width:100px;">操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($results['details'] as $detail) : ?>
                        <tr>
                            <td><?php echo esc_html($detail['id']); ?></td>
                            <td><?php echo esc_html($detail['title']); ?></td>
                            <td><a href="<?php echo esc_url(get_edit_post_link($detail['id'])); ?>" target="_blank">编辑</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php
}
