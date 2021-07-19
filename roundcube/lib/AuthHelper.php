<?php
/**
 * ownCloud - RoundCube mail plugin
 *
 * @author Martin Reinhardt
 * @author 2019 Leonardo R. Morelli github.com/LeonardoRM
 * @copyright 2013 Martin Reinhardt contact@martinreinhardt-online.de
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
namespace OCA\RoundCube;

use OCP\Authentication\Exceptions\CredentialsUnavailableException;
use OCP\Authentication\Exceptions\PasswordUnavailableException;
use OCP\Authentication\LoginCredentials\IStore;
use OCA\RoundCube\InternalAddress;
use OCA\RoundCube\BackLogin;
use OCP\Util;

class AuthHelper
{
    const COOKIE_RC_SESSID    = "roundcube_sessid";
    const COOKIE_RC_SESSAUTH  = "roundcube_sessauth";

    /** @var email */
    private $email;

    /** @var rcIA */
    private $rcIA;

    /** @var credentialStore */
    private $credentialStore;

    public function __construct(InternalAddress $rcIA, IStore $credentialStore, $email) {
        $this->email = $email;
        $this->rcIA = $rcIA;
        $this->credentialStore = $credentialStore;
    }

    /**
     * Save Login data for later login into roundcube server
     *
     * @param array $params Keys are: [run,uid,password]
     * @return true if login was successfull otherwise false
     */
    public static function postLogin($params) {
        if (strpos($params['uid'], '@') === false) {
            Util::writeLog('roundcube', __METHOD__ . ": username ({$params['uid']}) is not an email address. Hence, the user needs to have an email address configured.", Util::DEBUG);
        }
        $via = \OC::$server->getRequest()->getRequestUri();
        if (preg_match(
            '#(/ocs/v\d.php|'.
            '/apps/calendar/caldav.php|'.
            '/apps/contacts/carddav.php|'.
            '/remote.php/webdav)/#', $via)
        ) {
            return false;
        }
        Util::writeLog('roundcube', __METHOD__ . ": Preparing login of roundcube user '{$params['uid']}'", Util::DEBUG);

        return true;
    }

    /**
     * Logs in to RC webmail.
     * @return bool True on login, false otherwise.
     */
    public function login() {
        try {
            $password = $this->credentialStore->getLoginCredentials()->getPassword();
        } catch (CredentialsUnavailableException | PasswordUnavailableException $e) {
            Util::writeLog('roundcube', __METHOD__ . ": Error while retrieving the password of the $this->email account.", Util::ERROR);
            return false;
        }

        $rcIA = $this->rcIA;
        $backLogin = new BackLogin($this->email, $password, $rcIA->getAddress(), $rcIA->getServer());
        return $backLogin->login();
    }

    /**
     * Logout from RoundCube server by cleaning up session on OwnCloud logout
     * @return boolean True on success, false otherwise.
     */
    public static function logout() {
        $email = \OC::$server->getUserSession()->getUser()->getEMailAddress();
        if (strpos($email, '@') === false) {
            Util::writeLog('roundcube', __METHOD__ . ": user email ($email) is not an email address.", Util::WARN);
            return false;
        }
        // Expires cookies.
        setcookie(self::COOKIE_RC_SESSID,   "-del-", 1, "/", "", true, true);
        setcookie(self::COOKIE_RC_SESSAUTH, "-del-", 1, "/", "", true, true);
        Util::writeLog('roundcube', __METHOD__ . ": Logout of user '$email' from RoundCube done.", Util::INFO);
        return true;
    }

    /**
     * Returns the email address of user, if any.
     * If the uid is an email, it'll return it regardless of the user email.
     * If neither the uid or the user email are an email, it'll return the uid.
     */
    public static function getUserEmail() {
        $uid = \OC::$server->getUserSession()->getUser()->getUID();
        if (strpos($uid, '@') !== false) {
            return $uid;
        }

        $email = \OC::$server->getUserSession()->getUser()->getEMailAddress();
        if (strpos($email, '@') !== false) {
            return $email;
        }

        return $email; // returns a non-empty default
    }

}
