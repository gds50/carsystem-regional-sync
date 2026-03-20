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
            <p><?php echo esc_html__('Connection settings UI will be implemented in Milestone 4.', 'carsystem-regional-sync'); ?></p>
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
