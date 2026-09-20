<?php

/**
 * IAssetImageParameter class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Assets;

/**
 * IAssetImageParameter class
 *
 * This is the interface for implementing an asset or an
 * event parameter class in a filter event pipeline in
 * getting and setting its GD or Imagick pixel image.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 0.1.0
 */
interface IAssetImageParameter
{
	/**
	 * @return null|object The GD or Imagick image to be processed.
	 */
	public function getImage();

	/**
	 * @param null|object $image The GD or Imagick image to be processed.
	 */
	public function setImage($image);
}
