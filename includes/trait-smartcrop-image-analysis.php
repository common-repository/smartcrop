<?php
/**
 * SmartCrop image analysis trait.
 *
 * @package SmartCrop
 * @since   1.0.0
 */

/**
 * Trait for methods for image analysis in order to smartly crop.
 *
 * Based on code by Greg Schoppe: {@link https://github.com/gschoppe/PHP-Smart-Crop}
 *
 * @since 1.0.0
 */
trait SmartCrop_Image_Analysis {
	/**
	 * Stores the image width and height. Here for IDE code completion.
	 *
	 * @var null|array {'width'=>int, 'height'=>int}
	 */
	protected $size = null;

	/**
	 * Smooths the current image.
	 *
	 * @since 1.0.0
	 *
	 * @abstract
	 *
	 * @param int $smoothness Amount of smoothness to apply.
	 */
	abstract public function smartcrop_filter_smooth( $smoothness );

	/**
	 * Get the average red, green, and blue values for a region of the image.
	 *
	 * @param int $src_x The upper left x position of the crop region.
	 * @param int $src_y The upper left y position of the crop region.
	 * @param int $src_w The width of the region.
	 * @param int $src_h The height of the region.
	 *
	 * @return array Returned array matches return value for `imagecolorsforindex()`.
	 */
	abstract public function smartcrop_get_average_rgb_color_for_region( $src_x, $src_y, $src_w, $src_h );

	/**
	 * Get the entry for a region of the image.
	 *
	 * @param int $src_x The upper left x coordinate of the crop region.
	 * @param int $src_y The upper left y coordinate of the crop region.
	 * @param int $src_w The width of the region.
	 * @param int $src_h The height of the region.
	 *
	 * @return float The amount of entropy for the region.
	 */
	abstract public function smartcrop_get_entropy_for_region( $src_x, $src_y, $src_w, $src_h );

	/**
	 * Get the crop coordinates for the most interesting part of the image.
	 *
	 * @param int $dest_w New width in pixels.
	 * @param int $dest_h New height in pixels.
	 *
	 * @return array First item is the x coordinate, the second item is the y coordinate.
	 */
	public function smartcrop_get_crop_coordinates( $dest_w, $dest_h ) {
		list( $focus_x, $focus_y, $focus_x_weight, $focus_y_weight ) = $this->smartcrop_get_focal_point();

		// Is the image wider than it is tall?
		if ( $this->size['width'] / $this->size['height'] >= $dest_w / $dest_h ) {
			$y = 0;

			// Which side of the focal point is more interesting?
			if ( $focus_x_weight > 0 ) {
				// Put the center point on the right rule of thirds line.
				$x = ( $focus_x * $this->size['width'] ) - ( ( 2 / 3 ) * $dest_w );
			} elseif ( $focus_x_weight < 0 ) {
				// Put the center point on the left rule of thirds line.
				$x = ( $focus_x * $this->size['width'] ) - ( ( 1 / 3 ) * $dest_w );
			} else {
				// Center the image on the focal point.
				$x = ( $focus_x * $this->size['width'] ) - ( 0.5 * $dest_w );
			}

			// Make sure the coordinate is not out of bounds.
			if ( $x >= $this->size['width'] - $dest_w ) {
				$x = $this->size['width'] - $dest_w - 1;
			}
		} else {
			$x = 0;

			if ( $focus_y_weight > 0 ) {
				// Put the center point on the top rule of thirds line.
				$y = ( $focus_y * $this->size['height'] ) - ( ( 2 / 3 ) * $dest_h );
			} elseif ( $focus_y_weight < 0 ) {
				// Put the center point on the bottom rule of thirds line.
				$y = ( $focus_y * $this->size['height'] ) - ( ( 1 / 3 ) * $dest_h );
			} else {
				// Center the image on the focal point.
				$y = ( $focus_y * $this->size['height'] ) - ( 0.5 * $dest_h );
			}

			// Make sure the coordinate is not out of bounds.
			if ( $y >= $this->size['height'] - $dest_h ) {
				$y = $this->size['height'] - $dest_h - 1;
			}
		}

		$x = max( 0, $x );
		$y = max( 0, $y );

		return array( $x, $y );
	}

	/**
	 * Finds the coordinates of the most interesting part of the image.
	 *
	 * @param int   $slice_count Number of slides to slice the image up into. More is slower but more accurate.
	 * @param float $weight      Weight between entropy method (0) and color method (1) to use to find the most
	 *                           interesting part. Defaults to 0.5.
	 *
	 * @return array The x and y coordinates, followed by which direction is more interesting.
	 */
	public function smartcrop_get_focal_point( $slice_count = 20, $weight = 0.5 ) {
		// Smooth the image a little to help reduce the effects of noise.
		$this->smartcrop_filter_smooth( 7 );

		// Find the average color of the whole image.
		$average_color = $this->smartcrop_get_average_rgb_color_for_region( 0, 0, $this->size['width'], $this->size['height'] );
		$average_color = $this->smartcrop_color_rgb_to_lab( $average_color );

		list( $x, $x_weight ) = $this->smartcrop_find_best_slice( $slice_count, $weight, $average_color, 'vertical' );
		list( $y, $y_weight ) = $this->smartcrop_find_best_slice( $slice_count, $weight, $average_color, 'horizontal' );

		return array( $x, $y, $x_weight, $y_weight );
	}

	/**
	 * Slices the image up into pieces and analyzes each slice to determine which is the best.
	 *
	 * @param int    $slice_count       Number of slides to slice the image up into. More is slower but more accurate.
	 * @param float  $weight            Weight between entropy method (0) and color method (1) to use to find the most
	 *                                  interesting part. Defaults to 0.5.
	 * @param array  $average_color_lab Average image color in lab color space format.
	 * @param string $slice_direction   Whether to slice "vertical" or "horizontal".
	 *
	 * @return array First item is the coordinate of the center of the best slice,
	 *               the second item is which direction is more interesting.
	 */
	public function smartcrop_find_best_slice( $slice_count, $weight, $average_color_lab, $slice_direction ) {
		if ( 'vertical' === $slice_direction ) {
			$slice_width  = floor( $this->size['width'] / $slice_count );
			$slice_height = $this->size['height'];

			$slice_size_primary   = $slice_width;
			$slice_size_secondary = $this->size['width'];

			$slice_y = 0;
		} else {
			$slice_width  = $this->size['width'];
			$slice_height = floor( $this->size['height'] / $slice_count );

			$slice_size_primary   = $slice_height;
			$slice_size_secondary = $this->size['height'];

			$slice_x = 0;
		}

		$slices = array();
		for ( $i = 0; $i < $slice_count; $i ++ ) {
			if ( 'vertical' === $slice_direction ) {
				$slice_x = $slice_width * $i;
			} else {
				$slice_y = $slice_height * $i;
			}

			// Color
			if ( 0 === $weight ) {
				// A weight of 0 means color is ignored.
				$slice_color = 0;
			} else {
				$slice_average_color_rgb = $this->smartcrop_get_average_rgb_color_for_region(
					$slice_x,
					$slice_y,
					$slice_width,
					$slice_height
				);

				$slice_color = $this->smartcrop_get_color_difference_via_euclidean_distance(
					$average_color_lab,
					$this->smartcrop_color_rgb_to_lab( $slice_average_color_rgb )
				);
			}

			// Entropy
			if ( 1 === $weight ) {
				// A weight of 1 means entropy is ignored.
				$slice_entropy = 0;
			} else {
				$slice_entropy = $this->smartcrop_get_entropy_for_region( $slice_x, $slice_y, $slice_width, $slice_height );
			}

			// Get a weighted average of the color and entropy.
			$slices[ $i ] = $slice_color * $weight + $slice_entropy * ( 1 - $weight );
		}

		// Find the best slice.
		$best_slice = array_search( max( $slices ), $slices, true );

		// Get the center of that slice.
		$center = ( $best_slice + 0.5 ) * $slice_size_primary / $slice_size_secondary;

		$slice_weight = $this->smartcrop_get_slice_weight( $slices, $best_slice );

		return array( $center, $slice_weight );
	}

	/**
	 * Gets the difference between two colors via Euclidean distance.
	 *
	 * @param array $color_1 A color in lab color space format.
	 * @param array $color_2 A color in lab color space format.
	 *
	 * @return float|int The color difference.
	 */
	public function smartcrop_get_color_difference_via_euclidean_distance( $color_1, $color_2 ) {
		$sum_of_squares = 0;
		foreach ( $color_1 as $key => $val ) {
			$sum_of_squares += pow( ( $color_2[ $key ] - $val ), 2 );
		}

		$distance = sqrt( $sum_of_squares );

		// Divide by 10 to put it in similar range to entropy numbers.
		return $distance / 10;
	}

	/**
	 * Gets which direction from the best slice is more interesting.
	 *
	 * @param array $slices     The image slices.
	 * @param int   $best_slice The best slice's array key.
	 *
	 * @return int
	 */
	public function smartcrop_get_slice_weight( $slices, $best_slice ) {
		$slice_count = count( $slices );

		if ( 0 === $best_slice ) {
			$a = 0;
			$b = 1;
		} elseif ( $slice_count - 1 === $best_slice ) {
			$a = 1;
			$b = 0;
		} else {
			$a = $b = 0;

			// Average of the slices to the left of the best slice.
			for ( $i = 0; $i < $best_slice; $i ++ ) {
				$a += $slices[ $i ];
			}
			$a = $a / $best_slice;

			// Average of the slices to the right of the best slice.
			for ( $i = $best_slice + 1; $i < $slice_count; $i ++ ) {
				$b += $slices[ $i ];
			}
			$b = $b / ( $slice_count - ( $best_slice + 1 ) );
		}

		if ( $a > $b ) {
			return 1;
		}

		if ( $a < $b ) {
			return - 1;
		}

		return 0;
	}

	/**
	 * Converts a color in RGB format to lab format.
	 * No, I don't understand how this works either.
	 *
	 * @param array $color Array matching the return value for `imagecolorsforindex()`.
	 *
	 * @return array
	 */
	public function smartcrop_color_rgb_to_lab( $color ) {
		list( $r, $g, $b ) = array_map( function ( $color ) {
			$color = $color / 255;

			if ( $color > 0.04045 ) {
				$color = pow( ( ( $color + 0.055 ) / 1.055 ), 2.4 );
			} else {
				$color = $color / 12.92;
			}

			return $color * 100;
		}, array_values( $color ) );

		$x = 0.4124 * $r + 0.3576 * $g + 0.1805 * $b;
		$y = 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
		$z = 0.0193 * $r + 0.1192 * $g + 0.9505 * $b;

		$l = $a = $b = 0;

		if ( $y != 0 ) {
			$l = 10 * sqrt( $y );
			$a = 17.5 * ( ( 1.02 * $x ) - $y ) / sqrt( $y );
			$b = 7 * ( $y - 0.847 * $z ) / sqrt( $y );
		}

		return compact( 'l', 'a', 'b' );
	}
}
