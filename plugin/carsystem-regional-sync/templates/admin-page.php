<?php
/** @var array $settings */
/** @var array $logs */
?>
<div class="wrap">
    <h1><?php echo esc_html__('Carsystem Regional Sync', 'carsystem-regional-sync'); ?></h1>

    <form method="post" action="options.php">
        <?php settings_fields('crs_sync_settings_group'); ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="crs-source-url">Source URL</label></th>
                <td><input id="crs-source-url" class="regular-text" name="<?php echo esc_attr(CRS_SYNC_OPTION_KEY); ?>[source_url]" value="<?php echo esc_attr($settings['source_url']); ?>"></td>
            </tr>
            <tr>
                <th scope="row"><label for="crs-api-username">API Username</label></th>
                <td><input id="crs-api-username" class="regular-text" name="<?php echo esc_attr(CRS_SYNC_OPTION_KEY); ?>[api_username]" value="<?php echo esc_attr($settings['api_username']); ?>"></td>
            </tr>
            <tr>
                <th scope="row"><label for="crs-api-password">Application Password</label></th>
                <td><input id="crs-api-password" class="regular-text" type="password" name="<?php echo esc_attr(CRS_SYNC_OPTION_KEY); ?>[api_application_password]" value="********"></td>
            </tr>
            <tr>
                <th scope="row"><label for="crs-region">Region</label></th>
                <td><input id="crs-region" class="regular-text" name="<?php echo esc_attr(CRS_SYNC_OPTION_KEY); ?>[region]" value="<?php echo esc_attr($settings['region']); ?>"></td>
            </tr>
            <tr>
                <th scope="row"><label for="crs-city">City</label></th>
                <td><input id="crs-city" class="regular-text" name="<?php echo esc_attr(CRS_SYNC_OPTION_KEY); ?>[city]" value="<?php echo esc_attr($settings['city']); ?>"></td>
            </tr>
            <tr>
                <th scope="row"><label for="crs-area">Area</label></th>
                <td><input id="crs-area" class="regular-text" name="<?php echo esc_attr(CRS_SYNC_OPTION_KEY); ?>[area]" value="<?php echo esc_attr($settings['area']); ?>"></td>
            </tr>
            <tr>
                <th scope="row"><label for="crs-dictionary">Dictionary</label></th>
                <td><textarea id="crs-dictionary" class="large-text code" rows="8" name="<?php echo esc_attr(CRS_SYNC_OPTION_KEY); ?>[replacement_dictionary]"><?php echo esc_textarea($settings['replacement_dictionary']); ?></textarea></td>
            </tr>
            <tr>
                <th scope="row"><label for="crs-excluded">Excluded slugs</label></th>
                <td><textarea id="crs-excluded" class="large-text code" rows="6" name="<?php echo esc_attr(CRS_SYNC_OPTION_KEY); ?>[excluded_slugs]"><?php echo esc_textarea(implode("\n", (array) $settings['excluded_slugs'])); ?></textarea></td>
            </tr>
            <tr>
                <th scope="row"><label for="crs-sync-time">Sync time</label></th>
                <td><input id="crs-sync-time" type="time" name="<?php echo esc_attr(CRS_SYNC_OPTION_KEY); ?>[sync_time]" value="<?php echo esc_attr($settings['sync_time']); ?>"></td>
            </tr>
            <tr>
                <th scope="row">Auto sync</th>
                <td><label><input type="checkbox" name="<?php echo esc_attr(CRS_SYNC_OPTION_KEY); ?>[auto_sync_enabled]" value="1" <?php checked(! empty($settings['auto_sync_enabled'])); ?>> Enabled</label></td>
            </tr>
        </table>
        <?php submit_button(); ?>
    </form>

    <hr>

    <h2><?php echo esc_html__('Actions', 'carsystem-regional-sync'); ?></h2>
    <p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block; margin-right: 12px;">
            <input type="hidden" name="action" value="crs_test_connection">
            <?php wp_nonce_field('crs_test_connection'); ?>
            <?php submit_button(__('Test connection', 'carsystem-regional-sync'), 'secondary', '', false); ?>
        </form>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block; margin-right: 12px;">
            <input type="hidden" name="action" value="crs_run_primary_regionalization">
            <?php wp_nonce_field('crs_run_primary_regionalization'); ?>
            <?php submit_button(__('Primary regionalization', 'carsystem-regional-sync'), 'secondary', '', false); ?>
        </form>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;">
            <input type="hidden" name="action" value="crs_run_sync_now">
            <?php wp_nonce_field('crs_run_sync_now'); ?>
            <?php submit_button(__('Run sync now', 'carsystem-regional-sync'), 'primary', '', false); ?>
        </form>
    </p>

    <h2><?php echo esc_html__('Recent logs', 'carsystem-regional-sync'); ?></h2>
    <table class="widefat striped">
        <thead>
            <tr>
                <th>ID</th>
                <th>Run Type</th>
                <th>Status</th>
                <th>Started</th>
                <th>Finished</th>
                <th>Checked</th>
                <th>Updated</th>
                <th>Created</th>
                <th>Skipped</th>
                <th>Errors</th>
                <th>Message</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($logs === []) : ?>
                <tr><td colspan="11">No logs yet.</td></tr>
            <?php else : ?>
                <?php foreach ($logs as $log) : ?>
                    <tr>
                        <td><?php echo esc_html((string) $log['id']); ?></td>
                        <td><?php echo esc_html((string) $log['run_type']); ?></td>
                        <td><?php echo esc_html((string) $log['status']); ?></td>
                        <td><?php echo esc_html((string) $log['started_at']); ?></td>
                        <td><?php echo esc_html((string) $log['finished_at']); ?></td>
                        <td><?php echo esc_html((string) $log['checked_count']); ?></td>
                        <td><?php echo esc_html((string) $log['updated_count']); ?></td>
                        <td><?php echo esc_html((string) $log['created_count']); ?></td>
                        <td><?php echo esc_html((string) $log['skipped_count']); ?></td>
                        <td><?php echo esc_html((string) $log['error_count']); ?></td>
                        <td><?php echo esc_html((string) $log['message']); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>
