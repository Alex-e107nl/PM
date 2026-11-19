<?php
/*
 * e107 website system
 *
 * Copyright (C) 2008-2024 e107 Inc (e107.org)
 * Released under the terms and conditions of the
 * GNU General Public License (http://www.gnu.org/licenses/gpl.txt)
 *
 *	PM plugin - message encryption helper
 *
 *	AES-256-GCM (authenticated) encryption for the private message subject and
 *	body. Output is base64( version|nonce|tag|ciphertext ), which is pure ASCII
 *	and therefore safe to store in a TEXT / MEDIUMTEXT column on both MySQL and
 *	MariaDB.
 *
 *	Key storage
 *	-----------
 *	The key(s) live in a file, one `version:64-hex-chars` line per key, so future
 *	rotation is possible without a schema change. By default the file is
 *	<plugins>/pm/crypto/pm.keys, guarded by a deny-all .htaccess. An admin may
 *	instead define PM_CRYPTO_KEY_PATH (absolute path) to keep the key above the
 *	website root - the preferred, most secure option where the host allows it.
 */

if (!defined('e107_INIT')) { exit; }

class pm_crypto
{
	const CIPHER    = 'aes-256-gcm';
	const NONCE_LEN = 12;	// GCM recommended nonce size
	const TAG_LEN   = 16;	// 128-bit auth tag

	private $keyPath;
	private $keys = NULL;	// cache: version => 32-byte key


	/**
	 * @param string $keyPath absolute path to the key file (optional override)
	 */
	public function __construct($keyPath = '')
	{
		if($keyPath !== '')
		{
			$this->keyPath = $keyPath;
			return;
		}
		if(defined('PM_CRYPTO_KEY_PATH') && PM_CRYPTO_KEY_PATH !== '')
		{
			$this->keyPath = PM_CRYPTO_KEY_PATH;
			return;
		}
		$this->keyPath = e_PLUGIN.'pm/crypto/pm.keys';
	}


	/** Absolute path of the key file in use. */
	public function keyPath()
	{
		return $this->keyPath;
	}


	/** Whether the key file exists. */
	public function keyExists()
	{
		return is_file($this->keyPath);
	}


	/** Whether the requested cipher is usable on this PHP/OpenSSL build. */
	public function supported()
	{
		return function_exists('openssl_encrypt')
			&& in_array(self::CIPHER, openssl_get_cipher_methods(), TRUE);
	}


	/** All keys, version => 32-byte key. */
	private function loadKeys()
	{
		if($this->keys !== NULL)
		{
			return $this->keys;
		}
		$this->keys = array();
		if(!is_file($this->keyPath))
		{
			return $this->keys;
		}
		$lines = file($this->keyPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
		if($lines !== FALSE)
		{
			foreach($lines as $line)
			{
				$line = trim($line);
				$pos  = strpos($line, ':');
				if($pos === FALSE)
				{
					continue;
				}
				$version = substr($line, 0, $pos);
				$hex     = substr($line, $pos + 1);
				if(preg_match('/^v\d+$/', $version) !== 1)
				{
					continue;
				}
				if(!ctype_xdigit($hex) || strlen($hex) !== 64)
				{
					continue;
				}
				$this->keys[$version] = hex2bin($hex);
			}
		}
		return $this->keys;
	}


	/** Highest version number currently stored (0 when none). */
	private function highestVersion()
	{
		$max = 0;
		foreach(array_keys($this->loadKeys()) as $v)
		{
			if(preg_match('/^v(\d+)$/', $v, $m) === 1)
			{
				$max = max($max, (int) $m[1]);
			}
		}
		return $max;
	}


	/** @return string|NULL current (highest) version, NULL when no key exists. */
	public function currentVersion()
	{
		$n = $this->highestVersion();
		return $n ? 'v'.$n : NULL;
	}


	/** Whether at least one key is available. */
	public function hasKey()
	{
		return $this->currentVersion() !== NULL;
	}


	/**
	 * Ensure a key exists. Creates the folder, deny .htaccess and a new key
	 * when none is present.
	 *
	 * @return string|false current version on success, false on failure
	 */
	public function ensureKey()
	{
		if($this->hasKey())
		{
			return $this->currentVersion();
		}
		if(!$this->supported())
		{
			return false;
		}
		return $this->createKey() ? $this->currentVersion() : false;
	}


	/**
	 * Create a new key (appended as a new version).
	 *
	 * @param string $version optional explicit version, e.g. 'v2'
	 * @return boolean
	 */
	public function createKey($version = '')
	{
		if(!$this->supported())
		{
			return false;
		}
		$dir = dirname($this->keyPath);
		if(!is_dir($dir) && !@mkdir($dir, 0700, TRUE))
		{
			return false;
		}
		if($version === '')
		{
			$version = 'v'.($this->highestVersion() + 1);
		}
		$hex = bin2hex(random_bytes(32));
		$existing = is_file($this->keyPath) ? (string) file_get_contents($this->keyPath) : '';
		if($existing !== '' && substr($existing, -1) !== "\n")
		{
			$existing .= "\n";
		}
		if(@file_put_contents($this->keyPath, $existing.$version.':'.$hex."\n", LOCK_EX) === FALSE)
		{
			return false;
		}
		@chmod($this->keyPath, 0600);
		$this->protectFolder($dir);
		$this->keys = NULL;	// refresh cache
		return true;
	}


	/**
	 * Write a deny-all .htaccess into the key folder so the key can never be
	 * fetched over HTTP (Apache 2.4 + 2.2 covered).
	 *
	 * @param string $dir
	 * @return void
	 */
	protected function protectFolder($dir)
	{
		$ht = $dir.DIRECTORY_SEPARATOR.'.htaccess';
		if(is_file($ht))
		{
			return;
		}
		$rules = "# e107 PM crypto - no public access to this folder\n"
			."Options -Indexes\n"
			."<IfModule mod_authz_core.c>\n"
			."\tRequire all denied\n"
			."</IfModule>\n"
			."<IfModule !mod_authz_core.c>\n"
			."\tOrder allow,deny\n"
			."\tDeny from all\n"
			."</IfModule>\n";
		@file_put_contents($ht, $rules);
		@chmod($ht, 0644);
	}


	/** @return string|NULL 32-byte key for a version, NULL when unknown. */
	private function keyFor($version)
	{
		$keys = $this->loadKeys();
		return isset($keys[$version]) ? $keys[$version] : NULL;
	}


	/**
	 * Encrypt a plain string.
	 *
	 * @param string $plain
	 * @return string|false base64 payload, false on failure
	 */
	public function encrypt($plain)
	{
		if(!$this->supported())
		{
			return false;
		}
		$version = $this->ensureKey();
		if($version === false)
		{
			return false;
		}
		$key = $this->keyFor($version);
		if($key === NULL)
		{
			return false;
		}
		$nonce  = random_bytes(self::NONCE_LEN);
		$tag    = '';
		$cipher = openssl_encrypt((string) $plain, self::CIPHER, $key, OPENSSL_RAW_DATA, $nonce, $tag);
		if($cipher === false)
		{
			return false;
		}
		return base64_encode($version.'|'.$nonce.$tag.$cipher);
	}


	/**
	 * Version prefix of an encrypted payload (e.g. 'v1'), NULL when the
	 * value is empty or not an encrypted payload.
	 *
	 * @param string $encoded
	 * @return string|NULL
	 */
	public function versionOf($encoded)
	{
		$encoded = (string) $encoded;
		if($encoded === '')
		{
			return NULL;
		}
		$decoded = base64_decode($encoded, TRUE);
		if($decoded === false)
		{
			return NULL;
		}
		$pos = strpos($decoded, '|');
		if($pos === FALSE)
		{
			return NULL;
		}
		$version = substr($decoded, 0, $pos);
		return preg_match('/^v\d+$/', $version) === 1 ? $version : NULL;
	}

	/**
	 * Whether a stored value is an encrypted payload (vs. legacy plaintext).
	 *
	 * @param string $encoded
	 * @return boolean
	 */
	public function isEncrypted($encoded)
	{
		$encoded = (string) $encoded;
		if($encoded === '')
		{
			return false;
		}
		$decoded = base64_decode($encoded, TRUE);
		if($decoded === false)
		{
			return false;
		}
		$pos = strpos($decoded, '|');
		if($pos === FALSE)
		{
			return false;
		}
		return preg_match('/^v\d+$/', substr($decoded, 0, $pos)) === 1;
	}


	/**
	 * Decrypt a stored value. Legacy (pre-encryption) plaintext is returned
	 * unchanged, so this is safe to call on a mixed table during migration.
	 *
	 * @param string $encoded
	 * @return string|false plaintext, false when the key is missing or the tag fails
	 */
	public function decrypt($encoded)
	{
		$encoded = (string) $encoded;
		if($encoded === '')
		{
			return '';
		}
		if(!$this->isEncrypted($encoded))
		{
			return $encoded;	// legacy plaintext
		}
		$decoded = base64_decode($encoded, TRUE);
		$pos     = strpos($decoded, '|');
		$version = substr($decoded, 0, $pos);
		$blob    = substr($decoded, $pos + 1);

		$key = $this->keyFor($version);
		if($key === NULL)
		{
			return false;	// key no longer available
		}
		$nonce  = substr($blob, 0, self::NONCE_LEN);
		$tag    = substr($blob, self::NONCE_LEN, self::TAG_LEN);
		$cipher = substr($blob, self::NONCE_LEN + self::TAG_LEN);
		return openssl_decrypt($cipher, self::CIPHER, $key, OPENSSL_RAW_DATA, $nonce, $tag);
	}
}
