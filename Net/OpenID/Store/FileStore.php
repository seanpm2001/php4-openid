<?php

/**
 * This file supplies a Memcached store backend for OpenID servers and
 * consumers.
 *
 * PHP versions 4 and 5
 *
 * LICENSE: See the COPYING file included in this distribution.
 *
 * @package OpenID
 * @author JanRain, Inc. <openid@janrain.com>
 * @copyright 2005 Janrain, Inc.
 * @license http://www.gnu.org/copyleft/lesser.html LGPL
 *
 */

require_once('Interface.php');

function Net_OpenID_mkstemp($dir)
{
    foreach (range(0, 4) as $i) {
        $name = tempnam($dir, "php_openid_filestore_");

        if ($name !== false) {
            return $name;
        }
    }
    return false;
}

function Net_OpenID_mkdtemp($dir)
{
    foreach (range(0, 4) as $i) {
        $name = $dir . strval(DIRECTORY_SEPARATOR) . strval(getmypid()) .
            "-" . strval(rand(1, time()));
        if (!mkdir($name, 0700)) {
            return false;
        } else {
            return $name;
        }
    }
    return false;
}

function Net_OpenID_listdir($dir)
{
    $handle = opendir($dir);
    $files = array();
    while (false !== ($filename = readdir($handle))) {
        $files[] = $filename;
    }
    return $files;
}

function _isFilenameSafe($char)
{
    $letters = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ";
    $digits = "0123456789";
    $_Net_OpenID_filename_allowed = $letters . $digits . ".";
    return (strpos($_Net_OpenID_filename_allowed, $char) !== false);
}

function _safe64($str)
{
    $h64 = Net_OpenID_toBase64(Net_OpenID_CryptUtil::sha1($str));
    $h64 = str_replace('+', '_', $h64);
    $h64 = str_replace('/', '.', $h64);
    $h64 = str_replace('=', '', $h64);
    return $h64;
}

function _filenameEscape($str)
{
    $filename = "";
    for ($i = 0; $i < strlen($str); $i++) {
        $c = $str[$i];
        if (_isFilenameSafe($c)) {
            $filename .= $c;
        } else {
            $filename .= sprintf("_%02X", ord($c));
        }
    }
    return $filename;
}

/**
 * Attempt to remove a file, returning whether the file existed at the
 * time of the call.
 *
 * @return bool $result True if the file was present, false if not.
 */
function _removeIfPresent($filename)
{
    return @unlink($filename);
}

/**
 * Create dir_name as a directory if it does not exist. If it exists,
 * make sure that it is, in fact, a directory.  Returns true if the
 * operation succeeded; false if not.
 */
function _ensureDir($dir_name)
{
    if (@mkdir($dir_name) || is_dir($dir_name)) {
        return true;
    } else {
        return false;
    }
}

/**
 * This is a filesystem-based store for OpenID associations and
 * nonces.  This store should be safe for use in concurrent systems on
 * both windows and unix (excluding NFS filesystems).  There are a
 * couple race conditions in the system, but those failure cases have
 * been set up in such a way that the worst-case behavior is someone
 * having to try to log in a second time.
 *
 * Most of the methods of this class are implementation details.
 * People wishing to just use this store need only pay attention to
 * the constructor.
 *
 * Methods of this object can raise OSError if unexpected filesystem
 * conditions, such as bad permissions or missing directories, occur.
*/
class Net_OpenID_FileStore extends Net_OpenID_OpenIDStore {

    /**
     * Initializes a new FileOpenIDStore.  This initializes the nonce
     * and association directories, which are subdirectories of the
     * directory passed in.
     *
     * @param string $directory This is the directory to put the store
     * directories in.
     */
    function Net_OpenID_FileStore($directory)
    {
        $directory = realpath($directory);

        $this->nonce_dir = $directory . DIRECTORY_SEPARATOR . 'nonces';

        $this->association_dir = $directory . DIRECTORY_SEPARATOR .
            'associations';

        // Temp dir must be on the same filesystem as the assciations
        // $directory and the $directory containing the auth key file.
        $this->temp_dir = $directory . DIRECTORY_SEPARATOR . 'temp';

        $this->auth_key_name = $directory . DIRECTORY_SEPARATOR . 'auth_key';

        $this->max_nonce_age = 6 * 60 * 60; // Six hours, in seconds

        $this->_setup();
    }

    /**
     * Make sure that the directories in which we store our data
     * exist.
     */
    function _setup()
    {
        _ensureDir(dirname($this->auth_key_name));
        _ensureDir($this->nonce_dir);
        _ensureDir($this->association_dir);
        _ensureDir($this->temp_dir);
    }

    /**
     * Create a temporary file on the same filesystem as
     * $this->auth_key_name and $this->association_dir.
     *
     * The temporary directory should not be cleaned if there are any
     * processes using the store. If there is no active process using
     * the store, it is safe to remove all of the files in the
     * temporary directory.
     *
     * @return array ($fd, $filename)
     */
    function _mktemp()
    {
        $name = Net_OpenID_mkstemp($dir = $this->temp_dir);
        $file_obj = @fopen($name, 'wb');
        if ($file_obj !== false) {
            return array($file_obj, $name);
        } else {
            _removeIfPresent($name);
        }
    }

    /**
     * Read the auth key from the auth key file. Will return None if
     * there is currently no key.
     *
     * @return mixed
     */
    function readAuthKey()
    {
        $auth_key_file = @fopen($this->auth_key_name, 'rb');
        if ($auth_key_file === false) {
            return null;
        }

        $key = fread($auth_key_file, filesize($this->auth_key_name));
        fclose($auth_key_file);

        return $key;
    }

    /**
     * Generate a new random auth key and safely store it in the
     * location specified by $this->auth_key_name.
     *
     * @return string $key
     */
    function createAuthKey()
    {
        $auth_key = Net_OpenID_CryptUtil::randomString($this->AUTH_KEY_LEN);

        list($file_obj, $tmp) = $this->_mktemp();

        fwrite($file_obj, $auth_key);
        fflush($file_obj);

        if (!link($tmp, $this->auth_key_name)) {
            // The link failed, either because we lack the permission,
            // or because the file already exists; try to read the key
            // in case the file already existed.
            $auth_key = $this->readAuthKey();

            if (!$auth_key) {
                return null;
            } else {
                _removeIfPresent($tmp);
            }
        }

        return $auth_key;
    }

    /**
     * Retrieve the auth key from the file specified by
     * $this->auth_key_name, creating it if it does not exist.
     *
     * @return string $key
     */
    function getAuthKey()
    {
        $auth_key = $this->readAuthKey();
        if ($auth_key === null) {
            $auth_key = $this->createAuthKey();

            if (strlen($auth_key) != $this->AUTH_KEY_LEN) {
                $fmt = 'Got an invalid auth key from %s. Expected '.
                    '%d-byte string. Got: %s';
                $msg = sprintf($fmt, $this->auth_key_name, $this->AUTH_KEY_LEN,
                               $auth_key);
                trigger_error($msg, E_USER_WARNING);
                return null;
            }
        }
        return $auth_key;
    }

    /**
     * Create a unique filename for a given server url and
     * handle. This implementation does not assume anything about the
     * format of the handle. The filename that is returned will
     * contain the domain name from the server URL for ease of human
     * inspection of the data directory.
     *
     * @return string $filename
     */
    function getAssociationFilename($server_url, $handle)
    {
        if (strpos($server_url, '://') === false) {
            trigger_error(sprintf("Bad server URL: %s", $server_url),
                          E_USER_WARNING);
            return null;
        }

        list($proto, $rest) = explode('://', $server_url, 2);
        $parts = explode('/', $rest);
        $domain = _filenameEscape($parts[0]);
        $url_hash = _safe64($server_url);
        if ($handle) {
            $handle_hash = _safe64($handle);
        } else {
            $handle_hash = '';
        }

        $filename = sprintf('%s-%s-%s-%s', $proto, $domain, $url_hash,
                            $handle_hash);

        return $this->association_dir. DIRECTORY_SEPARATOR . $filename;
    }

    /**
     * Store an association in the association directory.
     */
    function storeAssociation($server_url, $association)
    {
        $association_s = $association->serialize();
        $filename = $this->getAssociationFilename($server_url,
                                                  $association->handle);
        list($tmp_file, $tmp) = $this->_mktemp();

        if (!$tmp_file) {
            trigger_error("_mktemp didn't return a valid file descriptor",
                          E_USER_WARNING);
            return null;
        }

        fwrite($tmp_file, $association_s);

        fflush($tmp_file);

        fclose($tmp_file);

        if (!rename($tmp, $filename)) {
            // We only expect EEXIST to happen only on Windows. It's
            // possible that we will succeed in unlinking the existing
            // file, but not in putting the temporary file in place.
            unlink($filename);

            // Now the target should not exist. Try renaming again,
            // giving up if it fails.
            if (!rename($tmp, $filename)) {
                _removeIfPresent($tmp);
                return null;
            }
        }

        // If there was an error, don't leave the temporary file
        // around.
        _removeIfPresent($tmp);
    }

    /**
     * Retrieve an association. If no handle is specified, return the
     * association with the latest expiration.
     *
     * @return mixed $association
     */
    function getAssociation($server_url, $handle = null)
    {
        if ($handle === null) {
            $handle = '';
        }

        // The filename with the empty handle is a prefix of all other
        // associations for the given server URL.
        $filename = $this->getAssociationFilename($server_url, $handle);

        if ($handle) {
            return $this->_getAssociation($filename);
        } else {
            $association_files = Net_OpenID_listdir($this->association_dir);
            $matching_files = array();

            // strip off the path to do the comparison
            $name = basename($filename);
            foreach ($association_files as $association_file) {
                if (strpos($association_file, $name) == 0) {
                    $matching_files[] = $association_file;
                }
            }

            $matching_associations = array();
            // read the matching files and sort by time issued
            foreach ($matching_files as $name) {
                $full_name = $this->association_dir . DIRECTORY_SEPARATOR .
                    $name;
                $association = $this->_getAssociation($full_name);
                if ($association !== null) {
                    $matching_associations[] = array($association->issued,
                                                     $association);
                }
            }

            $issued = array();
            $assocs = array();
            foreach ($matching_associations as $key => $assoc) {
                $issued[$key] = $assoc[0];
                $assocs[$key] = $assoc[1];
            }

            array_multisort($issued, SORT_DESC, $assocs, SORT_DESC,
                            $matching_associations);

            // return the most recently issued one.
            if ($matching_associations) {
                list($issued, $assoc) = $matching_associations[0];
                return $assoc;
            } else {
                return null;
            }
        }
    }

    function _getAssociation($filename)
    {
        $assoc_file = @fopen($filename, 'rb');

        if ($assoc_file === false) {
            return null;
        }

        $assoc_s = fread($assoc_file, filesize($filename));
        fclose($assoc_file);

        if (!$assoc_s) {
            return null;
        }

        $association =
            Net_OpenID_Association::deserialize('Net_OpenID_Association',
                                                $assoc_s);

        if (!$association) {
            _removeIfPresent($filename);
            return null;
        }

        if ($association->getExpiresIn() == 0) {
            _removeIfPresent($filename);
            return null;
        } else {
            return $association;
        }
    }

    /**
     * Remove an association if it exists. Do nothing if it does not.
     *
     * @return bool $success
     */
    function removeAssociation($server_url, $handle)
    {
        $assoc = $this->getAssociation($server_url, $handle);
        if ($assoc === null) {
            return false;
        } else {
            $filename = $this->getAssociationFilename($server_url, $handle);
            return _removeIfPresent($filename);
        }
    }

    /**
     * Mark this nonce as present.
     */
    function storeNonce($nonce)
    {
        $filename = $this->nonce_dir . DIRECTORY_SEPARATOR . $nonce;
        $nonce_file = fopen($filename, 'w');
        if ($nonce_file === false) {
            return false;
        }
        fclose($nonce_file);
        return true;
    }

    /**
     * Return whether this nonce is present. As a side effect, mark it
     * as no longer present.
     *
     * @return bool $present
     */
    function useNonce($nonce)
    {
        $filename = $this->nonce_dir . DIRECTORY_SEPARATOR . $nonce;
        $st = @stat($filename);

        if ($st === false) {
            return false;
        }

        // Either it is too old or we are using it. Either way, we
        // must remove the file.
        if (!unlink($filename)) {
            return false;
        }

        $now = time();
        $nonce_age = $now - $st[9];

        // We can us it if the age of the file is less than the
        // expiration time.
        return $nonce_age <= $this->max_nonce_age;
    }

    /**
     * Remove expired entries from the database. This is potentially
     * expensive, so only run when it is acceptable to take time.
     */
    function clean()
    {
        $nonces = Net_OpenID_listdir($this->nonce_dir);
        $now = time();

        // Check all nonces for expiry
        foreach ($nonces as $nonce) {
            $filename = $this->nonce_dir . DIRECTORY_SEPARATOR . $nonce;
            $st = @stat($filename);

            if ($st !== false) {
                // Remove the nonce if it has expired
                $nonce_age = $now - $st[9];
                if ($nonce_age > $this->max_nonce_age) {
                    _removeIfPresent($filename);
                }
            }
        }

        $association_filenames = Net_OpenID_listdir($this->association_dir);
        foreach ($association_filenames as $association_filename) {
            $association_file = fopen($association_filename, 'rb');

            if ($association_file !== false) {
                $assoc_s = fread($association_file,
                                 filesize($association_filename));
                fclose($association_file);

                // Remove expired or corrupted associations
                $association =
                  Net_OpenID_Association::deserialize('Net_OpenID_Association',
                                                      $assoc_s);
                if ($association === null) {
                    _removeIfPresent($association_filename);
                } else {
                    if ($association->getExpiresIn() == 0) {
                        _removeIfPresent($association_filename);
                    }
                }
            }
        }
    }
}

?>