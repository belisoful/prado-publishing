<?php

/**
 * TRectangleImagerFilter class file.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @link https://github.com/belisoful/prado-publishing
 * @license https://github.com/pradosoft/prado/blob/master/LICENSE
 */

namespace Prado\Web\Assets\Behaviors\Filters;

use Prado\TPropertyValue;
use Prado\Web\Assets\TAssetEventParameter;

/**
 * TRectangleImagerFilter class
 *
 * This renders a filled rectangle on the image with GD imagefilledrectangle(), or
 * with an Imagick drawing of the rectangle.
 *
 * The {@see setRectColor RectColor} is the color of the rectangle; it is
 * blended with the image according to its alpha.  The corners of the rectangle
 * are ({@see setRectX1 RectX1}, {@see setRectY1 RectY1}) and ({@see setRectX2
 * RectX2}, {@see setRectY2 RectY2}), inclusive.  Each can be pixels from the
 * left/top, negative pixels from the right/bottom (where "-0" is the last pixel),
 * or a percentage of the dimension (eg. "50%", or "-10%" from the right/bottom).
 * By default the rectangle covers the whole image.
 *
 * Imagick composites the rectangle where GD blends it, so a translucent RectColor
 * over translucent pixels differs slightly from GD, which keeps the alpha of the image.
 *
 * @author Brad Anderson <belisoful@icloud.com>
 * @since 0.1.0
 * @see https://www.php.net/manual/en/function.imagefilledrectangle.php
 */
class TRectangleImagerFilter extends TBaseImagerFilter
{
	/** @var numeric|string the color of the rectangle, "#AARRGGBB" or a numeric GD true color */
	private $_rectColor = '#00FFFFFF';

	/** @var float|string The X position of the first rectangle point, default 0 */
	private $_rectX1 = 0.0;

	/** @var float|string The Y position of the first rectangle point, default 0 */
	private $_rectY1 = 0.0;

	/** @var float|string The X position of the second rectangle point, default -0 */
	private $_rectX2 = -0.0;

	/** @var float|string The Y position of the second rectangle point, default -0 */
	private $_rectY2 = -0.0;

	/**
	 * @return string The color of the rectangle in "#AARRGGBB" format or a
	 *   numeric GD true color, default '#00FFFFFF' (opaque white).
	 */
	public function getRectColor(): string
	{
		return $this->_rectColor;
	}

	/**
	 * @param null|object $image The image to allocate the color in, or null
	 *   to compute the GD true color value without an image.
	 * @return false|int The GD color of the {@see getRectColor RectColor}.
	 */
	protected function getRectColorIndex($image = null)
	{
		$cc = $this->getRectColor();
		if (is_numeric($cc)) {
			return (int) $cc;
		}
		$a = hexdec(substr($cc, 1, 2));
		$r = hexdec(substr($cc, 3, 2));
		$g = hexdec(substr($cc, 5, 2));
		$b = hexdec(substr($cc, 7, 2));
		if (!$image) {
			return ($a << 24) | ($r << 16) | ($g << 8) | $b;
		}
		return imageColorAllocateAlpha($image, $r, $g, $b, $a);
	}

	/**
	 * The value can be a numeric GD true color, "#AARRGGBB" where AA is the
	 * GD alpha [00..7F], or any Web Color accepted by
	 * {@see TPropertyValue::ensureHexColor} (eg. "#RRGGBB", "#RGB", or "White")
	 * which is made opaque.
	 *
	 * @param numeric|string $value The color of the rectangle.
	 */
	public function setRectColor($value)
	{
		if ((is_numeric($value) && $value >= 0)) {
			$this->_rectColor = $value;
			return;
		}
		$value = TPropertyValue::ensureString($value);
		if (preg_match('/^#[0-7][0-9a-fA-F]{7}$/', $value)) {
			$this->_rectColor = $value;
		} else {
			$value = TPropertyValue::ensureHexColor($value);
			$this->_rectColor = '#00' . substr($value, 1);
		}
	}

	/**
	 * This can be a float in pixels, from the left, or a percentage of
	 * the size in the x-axis. When negative, then it measures pixels from
	 * the right or percentage from the right.
	 *
	 * @return float|string The location in the X-axis of the first point, default 0.
	 */
	public function getRectX1()
	{
		return $this->_rectX1;
	}

	/**
	 * This can be a float in pixels, from the left, or a percentage of
	 * the size in the x-axis. When negative, then it measures pixels from
	 * the right or percentage from the right.
	 *
	 * @param float|string $value The location in the X-axis of the first point
	 */
	public function setRectX1($value)
	{
		if (is_numeric($value)) {
			$this->_rectX1 = TPropertyValue::ensureFloat($value);
		} else {
			$this->_rectX1 = TPropertyValue::ensureString($value);
		}
	}

	/**
	 * This can be a float in pixels, from the top, or a percentage of
	 * the size in the y-axis. When negative, then it measures pixels from
	 * the bottom or percentage from the bottom.
	 *
	 * @return float|string The location in the Y-axis of the first point, default 0.
	 */
	public function getRectY1()
	{
		return $this->_rectY1;
	}

	/**
	 * This can be a float in pixels, from the top, or a percentage of
	 * the size in the y-axis. When negative, then it measures pixels from
	 * the bottom or percentage from the bottom.
	 *
	 * @param float|string $value The location in the Y-axis of the first point
	 */
	public function setRectY1($value)
	{
		if (is_numeric($value)) {
			$this->_rectY1 = TPropertyValue::ensureFloat($value);
		} else {
			$this->_rectY1 = TPropertyValue::ensureString($value);
		}
	}

	/**
	 * This can be a float in pixels, from the left, or a percentage of
	 * the size in the x-axis. When negative, then it measures pixels from
	 * the right or percentage from the right.
	 *
	 * @return float|string The location in the X-axis of the second point, default -0.
	 */
	public function getRectX2()
	{
		return $this->_rectX2;
	}

	/**
	 * This can be a float in pixels, from the left, or a percentage of
	 * the size in the x-axis. When negative, then it measures pixels from
	 * the right or percentage from the right.
	 *
	 * @param float|string $value The location in the X-axis of the second point
	 */
	public function setRectX2($value)
	{
		if (is_numeric($value)) {
			$this->_rectX2 = TPropertyValue::ensureFloat($value);
		} else {
			$this->_rectX2 = TPropertyValue::ensureString($value);
		}
	}

	/**
	 * This can be a float in pixels, from the top, or a percentage of
	 * the size in the y-axis. When negative, then it measures pixels from
	 * the bottom or percentage from the bottom.
	 *
	 * @return float|string The location in the Y-axis of the second point, default -0.
	 */
	public function getRectY2()
	{
		return $this->_rectY2;
	}

	/**
	 * This can be a float in pixels, from the top, or a percentage of
	 * the size in the y-axis. When negative, then it measures pixels from
	 * the bottom or percentage from the bottom.
	 *
	 * @param float|string $value The location in the Y-axis of the second point
	 */
	public function setRectY2($value)
	{
		if (is_numeric($value)) {
			$this->_rectY2 = TPropertyValue::ensureFloat($value);
		} else {
			$this->_rectY2 = TPropertyValue::ensureString($value);
		}
	}

	/**
	 * Calls GD imagefilledrectangle() with the RectColor and the computed corners.
	 *
	 * @param object &$image The image being filtered.
	 * @param ?TAssetEventParameter $param the publishing context.
	 * @return ?bool Was the image changed.
	 * @see https://www.php.net/manual/en/function.imagefilledrectangle.php
	 */
	protected function filterGdImage(&$image, ?TAssetEventParameter $param = null): ?bool
	{
		if (!$image || !imageistruecolor($image)) {
			return null;
		}
		$sx = imagesx($image);
		$sy = imagesy($image);
		$color = (int) $this->getRectColorIndex($image);
		$x1 = (int) self::computePlacement($this->getRectX1(), $sx);
		$y1 = (int) self::computePlacement($this->getRectY1(), $sy);
		$x2 = (int) self::computePlacement($this->getRectX2(), $sx);
		$y2 = (int) self::computePlacement($this->getRectY2(), $sy);

		imagealphablending($image, true);
		return imagefilledrectangle($image, $x1, $y1, $x2, $y2, $color);
	}

	/**
	 * Draws the rectangle of the RectColor and the computed corners, inclusive, with
	 * Imagick drawImage().  The GD alpha of the color [00..7F], where 7F is invisible,
	 * becomes the opacity of the Imagick fill, and the rectangle is composited over the
	 * image as GD blends it onto the image.
	 *
	 * @param \Imagick &$image The image being filtered.
	 * @param ?TAssetEventParameter $param the publishing context.
	 * @return ?bool Was the image changed.
	 * @see https://www.php.net/manual/en/imagickdraw.rectangle.php
	 */
	protected function filterImagickImage(&$image, ?TAssetEventParameter $param = null): ?bool
	{
		if (!$image) {
			return null;
		}
		$sx = $image->getImageWidth();
		$sy = $image->getImageHeight();
		$color = (int) $this->getRectColorIndex();
		$x1 = (int) self::computePlacement($this->getRectX1(), $sx);
		$y1 = (int) self::computePlacement($this->getRectY1(), $sy);
		$x2 = (int) self::computePlacement($this->getRectX2(), $sx);
		$y2 = (int) self::computePlacement($this->getRectY2(), $sy);

		$draw = new \ImagickDraw();
		$draw->setFillColor(new \ImagickPixel(sprintf(
			'rgba(%d,%d,%d,%F)',
			($color >> 16) & 0xFF,
			($color >> 8) & 0xFF,
			$color & 0xFF,
			1.0 - ((($color >> 24) & 0x7F) / 127.0)
		)));
		$draw->setStrokeColor(new \ImagickPixel('none'));
		$draw->rectangle(min($x1, $x2), min($y1, $y2), max($x1, $x2), max($y1, $y2));

		return $image->drawImage($draw);
	}
}
