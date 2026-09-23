<?php
namespace OCA\BatchRenamer\AppInfo;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Util;

class Application extends App implements IBootstrap {
    public const APP_ID = 'batch_renamer';

    public function __construct() {
        parent::__construct(self::APP_ID);
    }

    public function register(IRegistrationContext $context): void {}

    public function boot(IBootContext $context): void {
        // Lädt das kompilierte JavaScript in die Web-Oberfläche
        Util::addScript(self::APP_ID, 'batch_renamer');
    }
}