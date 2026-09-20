<?php

/**
 * TSimpleImageParameter class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Tests\Fixtures;

use Prado\Web\Assets\IAssetImageParameter;

/**
 * TSimpleImageParameter class.
 *
 * A test image parameter that only carries a GD image, without the save flag of
 * {@see \Prado\Web\Assets\TAssetEventParameter}.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 */
class TSimpleImageParameter implements IAssetImageParameter
{
	/** @var mixed the image. */
	private $_image;

	public function __construct($image = null)
	{
		$this->_image = $image;
	}

	public function getImage()
	{
		return $this->_image;
	}

	public function setImage($image)
	{
		$this->_image = $image;
	}
}
