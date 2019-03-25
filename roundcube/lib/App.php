<?php
/**
 * ownCloud - roundcube mail plugin
 *
 * @author Martin Reinhardt and David Jaedke
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
namespace OCA\RoundCube;

use OCP\AppFramework\Http\JSONResponse;
use OCA\RoundCube\DBUtil;

/**
 * This class manages the roundcube app.
 * It enables the db integration and
 * connects to the roundcube installation via the roundcube API
 */
class App
{
    const SESSION_RC_PRIVKEY  = 'OC\\ROUNDCUBE\\privateKey';
    const SESSION_RC_USER     = 'OC\\ROUNDCUBE\\rcUser';
    const SESSION_RC_SESSID   = 'OC\\ROUNDCUBE\\rcSessID';
    const SESSION_RC_SESSAUTH = 'OC\\ROUNDCUBE\\rcSessAuth';

    private $path = '';

    /**
     * Write to the PHP session
     *
     * @param
     *            Session Key $key
     * @param
     *            Value for the variable $value
     */
    private static function setSessionVariable($key, $value)
    {
        if (isset(\OC::$server)) {
            \OC::$server->getSession()->set($key, $value);
        } else {
            $_SESSION[$key] = $value;
        }
    }

    /**
     * Read from the PHP session
     *
     * @param
     *            Session Key $key
     *
     * @return Value of the session variable
     */
    private static function getSessionVariable($key)
    {
        if (isset(\OC::$server)) {
            return \OC::$server->getSession()->get($key);
        } else {
            return isset($_SESSION[$key]) ? $_SESSION[$key] : false;
        }
    }

    /**
     * @brief write basic information for the user in the app configu
     *
     * @param
     *            oc username $ocUser
     * @return s true/false
     *
     *         This function creates a simple personal entry for each user to distinguish them later
     *
     *         It also chekcs the login data
     */
    private static function writeBasicData($ocUser)
    {
        DBUtil::addUser(array('uid' => $ocUser));
        return self::checkLoginData($ocUser, 1);
    }

    /**
     * @brief chek the login parameters
     *
     * @param
     *            user object $ocUser
     * @param
     *            whether basic user data has been written to db
     * @return s the login data
     *
     *         This function tries to load the configured login data for roundcube and return it.
     */
    public static function checkLoginData($ocUser, $written = 0)
    {
        \OCP\Util::writeLog('roundcube', __METHOD__ . ': Checking login data for oc user ' . $ocUser, \OCP\Util::DEBUG);
        $mailEntries = DBUtil::getUser($ocUser);
        if (count($mailEntries) > 0) {
            \OCP\Util::writeLog('roundcube', __METHOD__ . ': Found login data for oc user ' . $ocUser, \OCP\Util::DEBUG);
            return $mailEntries;
        } elseif ($written === 0) {
            \OCP\Util::writeLog('roundcube', __METHOD__ . ': Did not found login data for oc user ' . $ocUser, \OCP\Util::DEBUG);
            return self::writeBasicData($ocUser);
        }
    }

    /**
     * Generate a private/public key pair.
     *
     * @param
     *            User ID$user.
     * @param
     *            Passphrase to $passphrase
     *
     * @return array('privateKey', 'publicKey')
     */
    public static function generateKeyPair($user, $passphrase)
    {
        /* Create the private and public keys */
        $res = openssl_pkey_new();
        /* Extract the private key from $res to $privKey */
        if (!openssl_pkey_export($res, $privKey, $passphrase)) {
            return false;
        }
        /* Extract the public key from $res to $pubKey */
        $pubKey = openssl_pkey_get_details($res); // it's a resource
        if ($pubKey === false) {
            return false;
        }
        $pubKey = $pubKey['key'];
        // We now store the public key unencrypted in the user preferences.
        // The private key already is encrypted with the user's password,
        // so there is no need to encrypt it again.
        \OC::$server->getConfig()->setUserValue($user, 'roundcube', 'publicSSLKey', $pubKey);
        \OC::$server->getConfig()->setUserValue($user, 'roundcube', 'privateSSLKey', $privKey);
        $uncryptedPrivKey = openssl_pkey_get_private($privKey, $passphrase);
        return array(
            'privateKey' => $uncryptedPrivKey, // it's a resource
            'publicKey' => $pubKey
        );
    }

    /**
     * Get users public key
     *
     * @param user $user
     * @return public key
     */
    public static function getPublicKey($user)
    {
        $pubKey = \OC::$server->getConfig()->getUserValue($user, 'roundcube', 'publicSSLKey', false);
        \OCP\Util::writeLog('roundcube', __METHOD__ . ': ' . $pubKey, \OCP\Util::DEBUG);
        return $pubKey;
    }

    /**
     * Get private key for user
     *
     * @param user Username
     * @param passphrase Key passphrase (user's password)
     * @return private key|boolean
     */
    public static function getPrivateKey($user, $passphrase)
    {
        $privKey = \OC::$server->getConfig()->getUserValue($user, 'roundcube', 'privateSSLKey', false);
        if ($privKey === false) {
            // need to create key pair
            $result = self::generateKeyPair($user, $passphrase);
            $uncryptedPrivKey = $result['privateKey'];
        } else {
            $uncryptedPrivKey = openssl_pkey_get_private($privKey, $passphrase);
        }

        // Save private key for later usage, need to export in order
        // to convert from a resource to real data.
        openssl_pkey_export($uncryptedPrivKey, $exportedPrivKey);
        self::setSessionVariable(App::SESSION_RC_PRIVKEY, $exportedPrivKey);

        return $uncryptedPrivKey;
    }

    /**
     * encrypt data ssl
     *
     * @param
     *            object to encrypt $entry
     * @param
     *            public key $pubKey
     * @return boolean|unknown
     */
    public static function cryptMyEntry($entry, $pubKey)
    {
        \OCP\Util::writeLog('roundcube', __METHOD__ . ': Starting encryption.', \OCP\Util::DEBUG);
        if (openssl_public_encrypt($entry, $encryptedData, $pubKey) === false) {
            \OCP\Util::writeLog('roundcube', 'AuthHelper.php->cryptMyEntry(): Error during crypting entry', \OCP\Util::ERROR);
            return false;
        }
        \OCP\Util::writeLog('roundcube', __METHOD__ . ': Encryption done with data ', \OCP\Util::DEBUG);
        $encrypted = base64_encode($encryptedData);
        return $encrypted;
    }

    /**
     * decrypt ssl-encrypted data
     *
     * @param
     *            data to encrypt $entry
     * @param
     *            private key $privKey
     * @return void|unknown
     */
    public static function decryptMyEntry($entry, $privKey)
    {
        \OCP\Util::writeLog('roundcube', __METHOD__ . ': Starting decryption.', \OCP\Util::DEBUG);
        $data = base64_decode($entry);
        if (openssl_private_decrypt($data, $decrypted, $privKey) === false) {
            \OCP\Util::writeLog('roundcube', __METHOD__ . ': Decryption finished with errors.', \OCP\Util::ERROR);
            return;
        }
        \OCP\Util::writeLog('roundcube', __METHOD__ . ': Decryption finished successfully.', \OCP\Util::DEBUG);
        return $decrypted;
    }

    /**
     * Use the pulic key of the respective user to encrypt the given
     * email identity and store it in the data-base.
     *
     * @param owncloud $ocUser
     * @param roundcube $emailUser
     * @param roundcube $emailPassword
     * @param
     *            set to false if don't want to persist/read data to db $persist
     * @return The IMAP credentials.|unknown
     */
    public static function cryptEmailIdentity($ocUser, $emailUser, $emailPassword, $persist = true)
    {
        \OCP\Util::writeLog('roundcube', __METHOD__ . ': Updating roundcube profile for ' . $ocUser . ' (mail user: ' . $emailUser . ')', \OCP\Util::DEBUG);

        $pubKey = self::getPublicKey($ocUser);

        if ($pubKey === false) {
            \OCP\Util::writeLog('roundcube', 'Found no valid public key for user ' . $ocUser . ' (mail user: ' . $emailUser . ')', \OCP\Util::ERROR);
            return false;
        }
        \OCP\Util::writeLog('roundcube', 'Found valid public key for user ' . $ocUser . ': ' . $pubKey . ')', \OCP\Util::DEBUG);
        if ($persist) {
            $mail_userdata_entries = self::checkLoginData($ocUser);
            $mail_userdata = $mail_userdata_entries[0];
            if ($mail_userdata_entries === false) {
                \OCP\Util::writeLog('roundcube', __METHOD__ . ':  Found no valid mail login data ', \OCP\Util::ERROR);
                return false;
            } else {
                \OCP\Util::writeLog('roundcube', __METHOD__ . ':  Found valid mail login data for user ' . $ocUser . ' (mail user: ' . $emailUser . ')', \OCP\Util::INFO);
            }
        }
        $mail_username = self::cryptMyEntry($emailUser, $pubKey);
        $mail_password = self::cryptMyEntry($emailPassword, $pubKey);

        if ($mail_username === false || $mail_password === false) {
            \OCP\Util::writeLog('roundcube', 'Encryption error for user ' . $ocUser, \OCP\Util::ERROR);
            return false;
        }
        if ($persist) {
            \OCP\Util::writeLog('roundcube', 'Updating roundcube user data (' . $emailUser . ')for oc user ' . $ocUser, \OCP\Util::INFO);
            $stmt = \OC::$server->getDatabaseConnection()->prepare("UPDATE *PREFIX*roundcube SET mail_user = ?, mail_password = ? WHERE oc_user = ?");
            $result = $stmt->execute(array(
                $mail_username,
                $mail_password,
                $ocUser
            ));
            \OCP\Util::writeLog('roundcube', 'Done updating roundcube login data for user ' . $ocUser . ' (mail user: ' . $emailUser . ')' . $ocUser, \OCP\Util::INFO);
        } else {
            $result = array(
                'mail_user' => $mail_username,
                'mail_password' => $mail_password
            );
        }
        return $result;
    }

    public static function getRedirectPath($pRcHost, $pRcPort, $pRcPath)
    {
        // Use a relative protocol in case we/roundcube are behind an SSL proxy (see
        // http://tools.ietf.org/html/rfc3986#section-4.2).
        $protocol = '//';
        if (strlen($pRcPort) > 1) {
            $path = $protocol . rtrim($pRcHost, "/") . ":" . $pRcPort . "/" . ltrim($pRcPath, "/");
        } else {
            $path = $protocol . rtrim($pRcHost, "/") . "/" . ltrim($pRcPath, "/");
        }
        return $path;
    }

    public static function saveUserSettings($appName, $ocUser, $rcUser, $rcPassword)
    {
        $l = new \OC::$server->getL10N('roundcube');

        if (isset($appName) && $appName === "roundcube") {
            $result = self::cryptEmailIdentity($ocUser, $rcUser, $rcPassword, true);
            \OCP\Util::writeLog('roundcube', __METHOD__ . ': Starting saving new users data for ' . $ocUser . ' as roundcube user ' . $rcUser, \OCP\Util::DEBUG);

            if ($result) {
                // update login credentials
//                 $rcMaildir = \OC::$server->getConfig()->getAppValue('roundcube', 'maildir', ''); // CCT comentada
// CCT edit
                $rcMaildir = CCTMaildir::getCCTMaildir();
// fin CCT edit
                $rcHost = \OC::$server->getConfig()->getAppValue('roundcube', 'rcHost', '');
                $rcPort = \OC::$server->getConfig()->getAppValue('roundcube', 'rcPort', '');
                if ($rcHost === '') {
                    $rcHost = \OC::$server->getRequest()->getServerHost();
                }
                // login again
                if (self::login($rcHost, $rcPort, $rcMaildir, $rcUser, $rcPassword)) {
                    \OCP\Util::writeLog('roundcube', __METHOD__ . ': Saved user settings successfull.', \OCP\Util::DEBUG);
                    return new JSONResponse(array(
                        'status' => 'success',
                        'data' => array(
                            'message' => $l->t('Email-user credentials successfully stored. Please login again to OwnCloud for applying the new settings.')
                        )
                    ));
                } else {
                    \OCP\Util::writeLog('roundcube', __METHOD__ . ': Login errors', \OCP\Util::DEBUG);
                    return new JSONResponse(array(
                        'status' => 'error',
                        "data" => array(
                            "message" => $l->t("Unable to login into roundcube. There are login errors.")
                        )
                    ));
                }
            } else {
                \OCP\Util::writeLog('roundcube', __METHOD__ . ': Unable to save email credentials.', \OCP\Util::DEBUG);
                return new JSONResponse(array(
                    'status' => 'error',
                    "data" => array(
                        "message" => $l->t("Unable to store email credentials in the data-base.")
                    )
                ));
                return false;
            }
        } else {
            \OCP\Util::writeLog('roundcube', __METHOD__ . ': Not for roundcube app.', \OCP\Util::DEBUG);
            return new JSONResponse(array(
                'status' => 'error',
                "data" => array(
                    "message" => $l->t("Not submitted for us.")
                )
            ));
            return false;
        }
    }
}
