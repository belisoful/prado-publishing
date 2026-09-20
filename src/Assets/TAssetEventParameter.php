<?php

/**
 * TAssetEventParameter class file
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Assets;

use Prado\TEventParameter;
use Prado\Exceptions\TInvalidDataTypeException;
use Prado\Web\Assets\Behaviors\TAssetImageMetaData;
use Prado\Collections\TPriorityList;

/**
 * TAssetEventParameter class
 *
 * TAssetEventParameter encapsulates the parameter data for onProcessAsset events
 * that are broadcasted. The name of the event is specified via {@see setName
 * Name} property.
 *
 * It is also the publishing context of an image: the image being processed, its
 * original and saved types, its palette colors, whether it is saved, and its
 * metadata ({@see getImageMetaData ImageMetaData}). The imagers and their filters
 * share it while the asset publishes.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 0.1.0
 */
class TAssetEventParameter extends TEventParameter implements IAssetImageParameter
{
	/** @var string name of the event being raised */
	private string $_name;

	/** @var string the file path of the asset being processed */
	private string $_filePath;

	/** @var ?IAsset The asset being published */
	private ?IAsset $_asset;

	/** @var ?object GD or Imagick image shared during publishing.  */
	private $_image;

	/** @var int The IMAGETYPE_* image type when opened.  */
	private int $_originalImageType = 0;

	/** @var int The IMAGETYPE_* image type to save.  */
	private int $_imageType = 0;

	/** @var int The number of palette colors, Default 0 - for true color.  */
	private int $_paletteColors = 0;

	/** @var bool should the image be saved.  */
	private bool $_saveImage = false;

	/** @var ?TAssetImageMetaData the metadata of the image. */
	private ?TAssetImageMetaData $_imageMetaData = null;

	/** @var ?TPriorityList The finalizers in priority order. */
	private $_finalizers;

	/**
	 * Constructor.
	 * @param string $name The name of the broadcast event
	 * @param string $filePath File path being processed during publish
	 * @param ?IAsset $asset The asset being published
	 */
	public function __construct(string $name = '', string $filePath = '', ?IAsset $asset = null)
	{
		$this->setName($name);
		$this->setFilePath($filePath);
		$this->setAsset($asset);
		parent::__construct();
	}

	/**
	 * @return string The name of the broadcast event, default ''.
	 */
	public function getName(): string
	{
		return $this->_name;
	}

	/**
	 * @param string $value The name of the broadcast event
	 */
	public function setName(string $value): void
	{
		$this->_name = $value;
	}

	/**
	 * @return string The file path of the processed publishing asset, default ''.
	 */
	public function getFilePath(): string
	{
		return $this->_filePath;
	}

	/**
	 * @param string $value The file path of the processed publishing asset.
	 */
	public function setFilePath(string $value): void
	{
		$this->_filePath = $value;
	}

	/**
	 * @return ?IAsset The asset being published, default null.
	 */
	public function getAsset(): ?IAsset
	{
		return $this->_asset;
	}

	/**
	 * @param ?IAsset $value The asset being published.
	 */
	public function setAsset(?IAsset $value): void
	{
		$this->_asset = $value;
	}

	/**
	 * @return ?object The GD or Imagick image shared among TAssetBehaviors during
	 *   publishing, default null.
	 */
	public function getImage()
	{
		return $this->_image;
	}

	/**
	 * @param ?object $value The GD or Imagick image shared among TAssetBehaviors during publishing.
	 */
	public function setImage($value): void
	{
		$this->_image = $value;
	}

	/**
	 * @return int The IMAGETYPE_* image type when opened, default 0.
	 */
	public function getOriginalImageType(): int
	{
		return $this->_originalImageType;
	}

	/**
	 * @param int $value The IMAGETYPE_* image type when opened.
	 */
	public function setOriginalImageType(int $value): void
	{
		$this->_originalImageType = $value;
	}

	/**
	 * @return int The IMAGETYPE_* image type for saving, default 0.
	 */
	public function getImageType(): int
	{
		return $this->_imageType;
	}

	/**
	 * @param int $value The IMAGETYPE_* image type for saving.
	 */
	public function setImageType(int $value): void
	{
		$this->_imageType = $value;
	}

	/**
	 * @return int The number of palette colors to save the image with, default 0 - true color.
	 */
	public function getPaletteColors(): int
	{
		return $this->_paletteColors;
	}

	/**
	 * @param int $value The number of palette colors to save the image with.
	 */
	public function setPaletteColors(int $value): void
	{
		$this->_paletteColors = $value;
	}

	/**
	 * @return bool Should the image be saved, default false.
	 */
	public function getSaveImage(): bool
	{
		return $this->_saveImage;
	}

	/**
	 * @param bool $value Should the image be saved.
	 */
	public function setSaveImage(bool $value): void
	{
		$this->_saveImage = $value;
	}

	/**
	 * @param bool $value Should the image be saved, OR'd into the current value.
	 */
	public function appendSaveImage(bool $value): void
	{
		$this->_saveImage |= $value;
	}

	/**
	 * @return ?TAssetImageMetaData The metadata of the image being processed, default null.
	 */
	public function getImageMetaData(): ?TAssetImageMetaData
	{
		return $this->_imageMetaData;
	}

	/**
	 * @param ?TAssetImageMetaData $value The metadata of the image being processed.
	 */
	public function setImageMetaData(?TAssetImageMetaData $value): void
	{
		$this->_imageMetaData = $value;
	}

	/**
	 * This is used to write an image to the destination file after processing.
	 * @return TPriorityList The finalizers for the Asset Event.
	 */
	public function getFinalizers(): TPriorityList
	{
		if (!$this->_finalizers) {
			$this->_finalizers = new TPriorityList();
		}
		return $this->_finalizers;
	}

	/**
	 * This adds a finalizer to the TAssetEventParameter for finalization of the asset.
	 * This is used to write an image to the destination file after processing.
	 * @param IAssetFinalizer $value The finalizer for the publish.
	 * @param null|numeric $priority The priority of the finalizer, null for the
	 *   list's default priority.
	 */
	public function addFinalizer(IAssetFinalizer $value, $priority = null)
	{
		$this->getFinalizers()->add($value, $priority);
	}


	/**
	 * This loops through all the {@see getFinalizers Finalizers} to finalize the
	 * processing of the asset.  {@see \Prado\Web\Assets\Behaviors\TAssetImagerBase} uses
	 * this to save the file after all the image processing is complete.
	 * @throws TInvalidDataTypeException when a finalizer is not an IAssetFinalizer.
	 */
	public function finalize(): void
	{
		foreach ($this->getFinalizers()->toArray() as $finalizer) {
			if (!($finalizer instanceof IAssetFinalizer)) {
				throw new TInvalidDataTypeException('asseteventparameter_finalizer_invalid', get_debug_type($finalizer));
			}
			$finalizer->finalize($this->getFilePath(), $this);
		}
	}
}
