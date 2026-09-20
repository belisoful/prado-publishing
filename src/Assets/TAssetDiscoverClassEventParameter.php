<?php

/**
 * TAssetDiscoverClassEventParameter class file
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Assets;

use Prado\TEventParameter;
use Prado\TPropertyValue;

/**
 * TAssetDiscoverClassEventParameter class
 *
 * TAssetDiscoverClassEventParameter carries the parameter data for the
 * {@see \Prado\Web\TPublishingManager::onDiscoverClass onDiscoverClass} event.
 * Handlers inspect {@see getFilePath FilePath} and, when they recognize the path,
 * set the {@see setClass Class} to the {@see \Prado\Web\Assets\IAsset} class that
 * publishes it, optionally rewriting {@see setFilePath FilePath}.
 *
 * The event starts with the {@see \Prado\Web\TPublishingManager::getDefaultAssetClass
 * DefaultAssetClass}. When no handler changes the Class, the manager applies its
 * built-in image-extension detection.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 0.1.0
 */
class TAssetDiscoverClassEventParameter extends TEventParameter
{
	/** @var string the IAsset class for the file path */
	private string $_class;

	/** @var string the file path determining the asset class */
	private string $_filePath;

	/** @var bool whether a handler set the class */
	private bool $_classSet = false;

	/**
	 * Constructor.
	 * @param string $class the default IAsset class for the file path
	 * @param string $filePath the file path determining the asset class
	 */
	public function __construct(string $class = '', string $filePath = '')
	{
		$this->_class = TPropertyValue::ensureString($class);
		$this->setFilePath($filePath);
		parent::__construct();
	}

	/**
	 * @return string the IAsset class for the file path, default ''.
	 */
	public function getClass(): string
	{
		return $this->_class;
	}

	/**
	 * @param string $value the IAsset class for the file path.
	 */
	public function setClass($value): void
	{
		$this->_class = TPropertyValue::ensureString($value);
		$this->_classSet = true;
	}

	/**
	 * @return bool whether a handler set the class, even to the default class.
	 */
	public function getIsClassSet(): bool
	{
		return $this->_classSet;
	}

	/**
	 * @return string the file path determining the asset class, default ''.
	 */
	public function getFilePath(): string
	{
		return $this->_filePath;
	}

	/**
	 * @param string $value the file path determining the asset class.
	 */
	public function setFilePath($value): void
	{
		$this->_filePath = TPropertyValue::ensureString($value);
	}
}
