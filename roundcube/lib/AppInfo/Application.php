<?php
/**
 * ownCloud - RoundCube mail plugin
 *
 * @license AGPL-3.0
 * @author Martin Reinhardt and David Jaedke
 * @author 2021 Igor Torrente
 * @author 2019 Leonardo R. Morelli github.com/LeonardoRM
 * @copyright 2012 Martin Reinhardt contact@martinreinhardt-online.de
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU AFFERO GENERAL PUBLIC LICENSE
 * License as published by the Free Software Foundation; either
 * version 3 of the License, or any later version.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU AFFERO GENERAL PUBLIC LICENSE for more details.
 *
 * You should have received a copy of the GNU Lesser General Public
 * License along with this library.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\RoundCube\AppInfo;

use OCP\Authentication\LoginCredentials\IStore;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCA\RoundCube\Controller\SettingsController;
use OCA\RoundCube\Controller\PageController;
use Psr\Container\ContainerInterface;
use OCP\AppFramework\App;
use OCP\IRequest;
use OCP\Util;

class Application extends App implements IBootstrap
{
    public const APP_ID = 'roundcube';

    public function __construct(array $params = []) {
        parent::__construct(self::APP_ID, $params);
    }

    public function register(IRegistrationContext $context): void {
        $context->registerService(PageController::class, function (ContainerInterface $c) {
            return new PageController(self::APP_ID, $c->get(IRequest::class), $c->get(IStore::class));
        }, false);

        $context->registerService(SettingsController::class, function(ContainerInterface $c) {
            return new SettingsController(self::APP_ID, $c->get(IRequest::class));
        }, false);
    }

    public function boot(IBootContext $context): void {
        $this->registerHooksAndEvents();
        $this->addNavigationManager();
        \OCP\App::registerAdmin('roundcube', 'adminSettings');
    }

    /* Register the hooks and events */
    private function registerHooksAndEvents() {
        Util::connectHook('OC_User', 'post_login', 'OCA\RoundCube\AuthHelper', 'postLogin');
        Util::connectHook('OC_User', 'logout', 'OCA\RoundCube\AuthHelper', 'logout');
    }

    private function addNavigationManager() {
        \OC::$server->getNavigationManager()->add(function () {
            $urlGen = \OC::$server->getURLGenerator();
            return array(
                'id'    => 'roundcube',
                'order' => 0,
                'href'  => $urlGen->linkToRoute('roundcube.page.index'),
                'icon'  => $urlGen->imagePath('roundcube', 'mail.svg'),
                'name'  => \OC::$server->getL10N('roundcube')->t('Email')
            );
        });
    }
}

