<?php

/*
 * This file is part of the Stash package.
 *
 * (c) Robert Hafner <tedivm@tedivm.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Stash\Driver;

use Stash;

/**
 * The APC driver is a wrapper for the APC extension, which allows developers to store data in memory.
 *
 * @package Stash
 * @author  Robert Hafner <tedivm@tedivm.com>
 */
class Apc extends AbstractDriver
{
    /**
     * Default maximum time an Item will be stored.
     *
     * @var int
     */
    protected $ttl;

    /**
     * This is an install specific namespace used to segment different applications from interacting with each other
     * when using APC. It's generated by creating an md5 of this file's location.
     *
     * @var string
     */
    protected $apcNamespace;

    /**
     * Whether to use the APCu functions or the original APC ones.
     *
     * @var string
     */
    protected $apcu = false;


    /**
     * The number of records \ApcIterator will grab at once.
     *
     * @var int
     */
    protected $chunkSize = 100;

    /**
     * {@inheritdoc}
     */
    public function getDefaultOptions()
    {
        return array(
            'ttl' => 300,
            'namespace' => md5(__FILE__),

            // Test using the APCUIterator, as some versions of APCU have the
            // custom functions but not the iterator class.
            'apcu' => class_exists('\APCUIterator')
        );
    }

    /**
     * This function should takes an array which is used to pass option values to the driver.
     *
     * * ttl - This is the maximum time the item will be stored.
     * * namespace - This should be used when multiple projects may use the same library.
     *
     * @param array $options
     */
    protected function setOptions(array $options = array())
    {
        $options += $this->getDefaultOptions();

        $this->ttl = (int) $options['ttl'];
        $this->apcNamespace = $options['namespace'];
        $this->apcu = $options['apcu'];
    }

    /**
     * {@inheritdoc}
     */
    public function getData($key)
    {
        $keyString = self::makeKey($key);
        $success = null;
        $data = $this->apcu ? apcu_fetch($keyString, $success) : apc_fetch($keyString, $success);

        return $success ? $data : false;
    }

    /**
     * {@inheritdoc}
     */
    public function storeData($key, $data, $expiration)
    {
        $life = $this->getCacheTime($expiration);
        $apckey = $this->makeKey($key);
        $store = array('data' => $data, 'expiration' => $expiration);

        return $this->apcu ? apcu_store($apckey, $store, $life) : apc_store($apckey, $store, $life);
    }

    /**
     * {@inheritdoc}
     */
    public function clear($key = null)
    {
        if (!isset($key)) {
            return $this->apcu ? apcu_clear_cache() : apc_clear_cache('user');
        } else {
            $keyRegex = '[' . $this->makeKey($key) . '*]';
            $chunkSize = isset($this->chunkSize) && is_numeric($this->chunkSize) ? $this->chunkSize : 100;

            do {
                $emptyIterator = true;
                $it = $this->apcu ? new \APCUIterator($keyRegex, \APC_ITER_KEY, $chunkSize) : new \APCIterator('user', $keyRegex, \APC_ITER_KEY, $chunkSize);

                foreach ($it as $item) {
                    $emptyIterator = false;
                    $this->apcu ? apcu_delete($item['key']) : apc_delete($item['key']);
                }
            } while (!$emptyIterator);
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function purge()
    {
        $now = time();
        $keyRegex = '[' . $this->makeKey(array()) . '*]';
        $chunkSize = isset($this->chunkSize) && is_numeric($this->chunkSize) ? $this->chunkSize : 100;
        $it = $this->apcu ? new \APCUIterator($keyRegex, \APC_ITER_KEY, $chunkSize) : new \APCIterator('user', $keyRegex, \APC_ITER_KEY, $chunkSize);
        foreach ($it as $item) {
            $success = null;
            $data = $this->apcu ? apcu_fetch($item['key'], $success): apc_fetch($item['key'], $success);

            if ($success && is_array($data) && $data['expiration'] <= $now) {
                $this->apcu ? apcu_delete($item['key']) : apc_delete($item['key']);
            }
        }

        return true;
    }

    /**
     * This driver is available if the apc extension is present and loaded on the system.
     *
     * @return bool
     */
    public static function isAvailable()
    {
        // Some versions of HHVM are missing the APCIterator
        if (!class_exists('\APCIterator') && !class_exists('\APCUIterator')) {
            return false;
        }

	    if (PHP_SAPI === 'cli' && !ini_get('apc.enable_cli')) {
		    return false;
	    }

        return function_exists('apcu_fetch') || function_exists('apc_fetch');
    }

    /**
     * Turns a key array into a string.
     *
     * @param  array  $key
     * @return string
     */
    protected function makeKey($key)
    {
        $keyString = md5(__FILE__) . '::'; // make it unique per install

        if (isset($this->apcNamespace)) {
            $keyString .= $this->apcNamespace . '::';
        }

        foreach ($key as $piece) {
            $keyString .= $piece . '::';
        }

        return $keyString;
    }

    /**
     * Converts a timestamp into a TTL.
     *
     * @param  int $expiration
     * @return int
     */
    protected function getCacheTime($expiration)
    {
        $life = $expiration - time();

        return $this->ttl < $life ? $this->ttl : $life;
    }


    /**
     * {@inheritdoc}
     */
    public function isPersistent()
    {
        return true;
    }
}
