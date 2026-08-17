<?php

declare(strict_types=1);

namespace Asbs\ShopwareStreamDeck;

use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Kernel;

final class ASBusStreamDeckDashboard extends Plugin
{
    /**
     * Shopware only drops the plugin's own `system_config` rows on uninstall;
     * plugin-owned tables are the plugin's responsibility. Without this the API
     * keys survived an uninstall with "delete all data" and reappeared in the
     * config card after a reinstall.
     */
    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);

        if ($uninstallContext->keepUserData()) {
            return;
        }

        // Same accessor core's own Plugin::removeMigrations() uses — always
        // available during the uninstall lifecycle, no nullable container.
        $connection = Kernel::getConnection();

        $connection->executeStatement('DROP TABLE IF EXISTS `asbs_streamdeck_api_key`');

        // Core cleans up `system_config` for the *current* plugin class only.
        // Shops that ran the pre-rename technical name keep orphaned rows that
        // nothing else would ever remove.
        $connection->executeStatement(
            'DELETE FROM `system_config` WHERE `configuration_key` LIKE :prefix',
            ['prefix' => 'AsbsShopwareStreamDeck.%'],
        );
    }
}
