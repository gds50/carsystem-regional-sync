<?php
/**
 * Milestone 3 admin shell.
 */
$tabs = [
    'connection' => __('Connection', 'carsystem-regional-sync'),
    'region'     => __('Region', 'carsystem-regional-sync'),
    'partner'    => __('Partner', 'carsystem-regional-sync'),
    'exclusions' => __('Exclusions', 'carsystem-regional-sync'),
    'sync'       => __('Sync', 'carsystem-regional-sync'),
    'logs'       => __('Logs', 'carsystem-regional-sync'),
];

$activeTab = isset($_GET['tab']) ? sanitize_key((string) $_GET['tab']) : 'connection';

if (! isset($tabs[$activeTab])) {
    $activeTab = 'connection';
}
?>
<div class="wrap">
    <h1><?php echo esc_html__('Carsystem Regional Sync', 'carsystem-regional-sync'); ?></h1>

    <h2 class="nav-tab-wrapper">
        <?php foreach ($tabs as $tabKey => $tabLabel) : ?>
            <?php
            $isActive = $tabKey === $activeTab;
            $tabUrl = add_query_arg(
                [
                    'page' => 'crs-sync',
                    'tab'  => $tabKey,
                ],
                admin_url('admin.php')
            );
            ?>
            <a href="<?php echo esc_url($tabUrl); ?>" class="nav-tab <?php echo $isActive ? 'nav-tab-active' : ''; ?>">
                <?php echo esc_html($tabLabel); ?>
            </a>
        <?php endforeach; ?>
    </h2>

    <div style="margin-top: 16px;">
        <?php if ($activeTab === 'connection') : ?>
            <?php
            $testStatus = (string) ($connectionTest['status'] ?? '');
            $testMessage = (string) ($connectionTest['message'] ?? '');
            $testedAt = (string) ($connectionTest['tested_at'] ?? '');
            $noticeClass = $testStatus === 'success' ? 'notice notice-success' : 'notice notice-error';
            ?>

            <?php if ($testStatus !== '' && $testMessage !== '') : ?>
                <div class="<?php echo esc_attr($noticeClass); ?>" style="margin: 0 0 16px 0;">
                    <p><strong><?php echo esc_html($testMessage); ?></strong></p>
                    <?php if ($testedAt !== '') : ?>
                        <p><?php echo esc_html(sprintf('Tested at (UTC): %s', $testedAt)); ?></p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <p><?php echo esc_html__('Use this action to verify access to the remote WordPress REST API.', 'carsystem-regional-sync'); ?></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="crs_test_connection">
                <?php wp_nonce_field('crs_test_connection'); ?>
                <?php submit_button(__('Test connection', 'carsystem-regional-sync')); ?>
            </form>
        <?php elseif ($activeTab === 'region') : ?>
            <p><?php echo esc_html__('Region settings UI shell is ready. Fields will be added in next milestones.', 'carsystem-regional-sync'); ?></p>
        <?php elseif ($activeTab === 'partner') : ?>
            <p><?php echo esc_html__('Partner tab shell is ready.', 'carsystem-regional-sync'); ?></p>
        <?php elseif ($activeTab === 'exclusions') : ?>
            <p><?php echo esc_html__('Exclusions tab shell is ready.', 'carsystem-regional-sync'); ?></p>
        <?php elseif ($activeTab === 'sync') : ?>
            <?php
            $runStatus = (string) ($primaryRegionalization['status'] ?? '');
            $runMessage = (string) ($primaryRegionalization['message'] ?? '');
            $runStarted = (string) ($primaryRegionalization['started_at'] ?? '');
            $runFinished = (string) ($primaryRegionalization['finished_at'] ?? '');
            $runChecked = (int) ($primaryRegionalization['checked_count'] ?? 0);
            $runUpdated = (int) ($primaryRegionalization['updated_count'] ?? 0);
            $runSkipped = (int) ($primaryRegionalization['skipped_count'] ?? 0);
            $runErrors = (int) ($primaryRegionalization['error_count'] ?? 0);
            $runNoticeClass = $runStatus === 'success' ? 'notice notice-success' : 'notice notice-warning';
            ?>

            <p><?php echo esc_html__('Run primary regionalization for local products and categories.', 'carsystem-regional-sync'); ?></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="crs_run_primary_regionalization">
                <?php wp_nonce_field('crs_run_primary_regionalization'); ?>
                <?php submit_button(__('Primary regionalization', 'carsystem-regional-sync')); ?>
            </form>

            <?php if ($runStatus !== '') : ?>
                <div class="<?php echo esc_attr($runNoticeClass); ?>" style="margin: 16px 0 0 0;">
                    <p><strong><?php echo esc_html(sprintf('Status: %s', $runStatus)); ?></strong></p>
                    <?php if ($runMessage !== '') : ?>
                        <p><?php echo esc_html($runMessage); ?></p>
                    <?php endif; ?>
                    <p><?php echo esc_html(sprintf('Checked: %d | Updated: %d | Skipped: %d | Errors: %d', $runChecked, $runUpdated, $runSkipped, $runErrors)); ?></p>
                    <?php if ($runStarted !== '' || $runFinished !== '') : ?>
                        <p><?php echo esc_html(sprintf('Started (UTC): %s | Finished (UTC): %s', $runStarted, $runFinished)); ?></p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php elseif ($activeTab === 'logs') : ?>
            <?php if ($logs === []) : ?>
                <p><?php echo esc_html__('No logs yet.', 'carsystem-regional-sync'); ?></p>
            <?php else : ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php echo esc_html__('ID', 'carsystem-regional-sync'); ?></th>
                            <th><?php echo esc_html__('Run type', 'carsystem-regional-sync'); ?></th>
                            <th><?php echo esc_html__('Status', 'carsystem-regional-sync'); ?></th>
                            <th><?php echo esc_html__('Started (UTC)', 'carsystem-regional-sync'); ?></th>
                            <th><?php echo esc_html__('Finished (UTC)', 'carsystem-regional-sync'); ?></th>
                            <th><?php echo esc_html__('Checked', 'carsystem-regional-sync'); ?></th>
                            <th><?php echo esc_html__('Updated', 'carsystem-regional-sync'); ?></th>
                            <th><?php echo esc_html__('Created', 'carsystem-regional-sync'); ?></th>
                            <th><?php echo esc_html__('Skipped', 'carsystem-regional-sync'); ?></th>
                            <th><?php echo esc_html__('Errors', 'carsystem-regional-sync'); ?></th>
                            <th><?php echo esc_html__('Message', 'carsystem-regional-sync'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log) : ?>
                            <tr>
                                <td><?php echo esc_html((string) ($log['id'] ?? '')); ?></td>
                                <td><?php echo esc_html((string) ($log['run_type'] ?? '')); ?></td>
                                <td><?php echo esc_html((string) ($log['status'] ?? '')); ?></td>
                                <td><?php echo esc_html((string) ($log['started_at'] ?? '')); ?></td>
                                <td><?php echo esc_html((string) ($log['finished_at'] ?? '')); ?></td>
                                <td><?php echo esc_html((string) ($log['checked_count'] ?? '0')); ?></td>
                                <td><?php echo esc_html((string) ($log['updated_count'] ?? '0')); ?></td>
                                <td><?php echo esc_html((string) ($log['created_count'] ?? '0')); ?></td>
                                <td><?php echo esc_html((string) ($log['skipped_count'] ?? '0')); ?></td>
                                <td><?php echo esc_html((string) ($log['error_count'] ?? '0')); ?></td>
                                <td><?php echo esc_html((string) ($log['message'] ?? '')); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
