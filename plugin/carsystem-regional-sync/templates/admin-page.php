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
            <p><?php echo esc_html__('Manual sync controls will be implemented in later milestones.', 'carsystem-regional-sync'); ?></p>
        <?php elseif ($activeTab === 'logs') : ?>
            <p><?php echo esc_html__('Logs screen shell is ready. Log storage integration will be added later.', 'carsystem-regional-sync'); ?></p>
        <?php endif; ?>
    </div>
</div>
