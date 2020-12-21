<?php
/*

Copyright 2020 Andrey Hristov (andrey % php net)

Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated
documentation files (the "Software"), to deal in the Software without restriction, including without limitation
the rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the Software,
and to permit persons to whom the Software is furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED
TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL
THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF
CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER
DEALINGS IN THE SOFTWARE.

*/
$fileName = $argv[1];
$outDirectory = $argv[2];
$tileWidth = @max(416, (int) $argv[3]);
$tileHeight = @max(416, (int) $argv[4]);

processFile($fileName, $outDirectory, $tileWidth, $tileHeight);

function getAnnotationsFromFile(string $fileName, int $imageWidth, int $imageHeight) : array {
	$annotations = file($fileName);

	$an = [];
	foreach ($annotations as $annotation) {
		$res = sscanf($annotation, "%d %f %f %f %f", $label, $centerX, $centerY, $bboxW, $bboxH);

		$centerXpx = $centerX * $imageWidth;
		$centerYpx = $centerY * $imageHeight;
		$bboxWpx = $bboxW * $imageWidth;
		$bboxHpx = $bboxH * $imageHeight;
		$upLeftX = $centerXpx - ($bboxWpx / 2);
		$upLeftY = $centerYpx - ($bboxHpx / 2);
		$downRightX = $centerXpx + ($bboxWpx / 2);
		$downRightY = $centerYpx + ($bboxHpx / 2);

		$ab = [];
		$postprocess = function($in) : float { return $in; };
		if (1) {
			$postprocess = function($in) : int { return round($in); };
		}
		
		$ab['label'] = $label;
		$ab['centerXpx'] = $centerXpx;
		$ab['centerYpx'] = $centerYpx;
		$ab['bboxWpx'] = $postprocess($bboxWpx);
		$ab['bboxHpx'] = $postprocess($bboxHpx);
		$ab['upLeftX'] = $postprocess($upLeftX);
		$ab['upLeftY'] = $postprocess($upLeftY);
		$ab['downRightX'] = $postprocess($downRightX) - 1;
		$ab['downRightY'] = $postprocess($downRightY) - 1;


		$an[] = $ab;
	}
	return $an;
}

function convertAnnotations(array $inputAns, int $tileWidth, int $tileHeight, $minObjectSegmentWidth, $minObjectSegmentHeight) : array {

	$outputAns = []; // [col][row]
  // printf("tile size %f,%f\n", $tileWidth, $tileHeight);
	foreach ($inputAns as $inputAn) {
    //printf("input %d: %f,%f %f,%f\n", $inputAn['label'], $inputAn['upLeftX'], $inputAn['upLeftY'], $inputAn['downRightX'], $inputAn['downRightY']);
		$tileUL_W = (int) floor( $inputAn['upLeftX'] / $tileWidth );
		$tileUL_H = (int) floor( $inputAn['upLeftY'] / $tileHeight );
		$tileDR_W = (int) floor( $inputAn['downRightX'] / $tileWidth );
		$tileDR_H = (int) floor( $inputAn['downRightY'] / $tileHeight );
    //printf("tile $tileUL_W,$tileUL_H to $tileDR_W,$tileDR_H\n");

		// The simplest case
		if ($tileUL_W === $tileDR_W && $tileUL_H === $tileDR_H) {
			echo "One tile\n";

			$upLeftX_TileOffset = $inputAn['upLeftX'] - ($tileUL_W * $tileWidth);
			$upLeftY_TileOffset = $inputAn['upLeftY'] - ($tileUL_H * $tileHeight);
			$downRightX_TileOffset = $inputAn['downRightX'] - ($tileDR_W * $tileWidth);
			$downRightY_TileOffset = $inputAn['downRightY'] - ($tileDR_H * $tileHeight);

			
			$bboxWidth_px = $downRightX_TileOffset - $upLeftX_TileOffset;
			$bboxHeight_px = $downRightY_TileOffset - $upLeftY_TileOffset;


			$bboxWidth_rel = $bboxWidth_px / $tileWidth;
			$bboxHeight_rel = $bboxHeight_px / $tileHeight;
			
			$centerXpx = $upLeftX_TileOffset + $bboxWidth_px / 2;
			$centerYpx = $upLeftY_TileOffset + $bboxHeight_px / 2;

			$centerXrel = $centerXpx / $tileWidth;
			$centerYrel = $centerYpx / $tileHeight;

			printf("0] UL [$upLeftX_TileOffset $upLeftY_TileOffset] DR [$downRightX_TileOffset $downRightY_TileOffset] BBOX_PX [$bboxWidth_px $bboxHeight_px] BBOX_REL [$bboxWidth_rel $bboxHeight_rel] CENTER_PX [$centerXpx $centerYpx] CENTER_REL[$centerXrel $centerYrel]\n");
		
			$outputAns[$tileUL_W][$tileUL_H][] = $thisAn = sprintf("%d %1.14f %1.14f %1.14f %1.14f", $inputAn['label'], $centerXrel, $centerYrel, $bboxWidth_rel, $bboxHeight_rel);
		} else {
			//printf("Horiz splits $tileDR_W $tileUL_W over %d\n", $tileDR_W - $tileUL_W);
			//printf("Vertic splits $tileDR_H $tileUL_H over %d\n", $tileDR_H - $tileUL_H);
			//echo "Splits over\n";
			$tileCols = $tileDR_W - $tileUL_W;
			$tileRows = $tileDR_H - $tileUL_H;
			//printf("W:[$tileUL_W] [$tileDR_W] cols=$tileCols\n");
			//printf("H:[$tileUL_H] [$tileDR_H] rows=$tileRows\n");
			if ($tileRows === 0) {
        echo "One row\n";
				for ($tileCounter = 0; $tileCounter <= $tileCols; $tileCounter++) {
					$upLeftY_TileOffset = $inputAn['upLeftY'] - ($tileUL_H * $tileHeight);
					$downRightY_TileOffset = $inputAn['downRightY'] - ($tileDR_H * $tileHeight);
					
					if ($tileCounter === 0) { // first tile

						$upLeftX_TileOffset = $inputAn['upLeftX'] - ($tileUL_W * $tileWidth);
						$downRightX_TileOffset = $tileWidth - 1;  // should it be -1 ?
					
					} else if (($tileCounter + 0) === $tileCols) { // last tile

						$upLeftX_TileOffset = 0;
						$downRightX_TileOffset = $inputAn['downRightX'] - ($tileDR_W * $tileWidth);

					} else { // mid tile

						$upLeftX_TileOffset = 0;
						$downRightX_TileOffset = $tileWidth - 1;  // should it be -1 ?

					}

					$bboxWidth_px = $downRightX_TileOffset - $upLeftX_TileOffset;
					$bboxHeight_px = $downRightY_TileOffset - $upLeftY_TileOffset;

					$bboxWidth_rel = $bboxWidth_px / $tileWidth;
					$bboxHeight_rel = $bboxHeight_px / $tileHeight;
			
					$centerXpx = $upLeftX_TileOffset + $bboxWidth_px / 2;
					$centerYpx = $upLeftY_TileOffset + $bboxHeight_px / 2;
			
					$centerXrel = $centerXpx / $tileWidth;
					$centerYrel = $centerYpx / $tileHeight;

					printf("1] UL [$upLeftX_TileOffset $upLeftY_TileOffset] DR [$downRightX_TileOffset $downRightY_TileOffset] BBOX_PX [$bboxWidth_px $bboxHeight_px] BBOX_REL [$bboxWidth_rel $bboxHeight_rel] CENTER_PX [$centerXpx $centerYpx] CENTER_REL[$centerXrel $centerYrel]\n");

					$outputAns[$tileUL_W + $tileCounter][$tileUL_H][] = $thisAn = sprintf("%d %1.14f %1.14f %1.14f %1.14f", $inputAn['label'], $centerXrel, $centerYrel, $bboxWidth_rel, $bboxHeight_rel);
//					// echo "$thisAn\n";
				}
			} else if ($tileCols === 0) {
        echo "One column\n";
				for ($tileCounter = 0; $tileCounter <= $tileRows; $tileCounter++) {
					$upLeftX_TileOffset = $inputAn['upLeftX'] - ($tileUL_W * $tileWidth);
					$downRightX_TileOffset = $inputAn['downRightX'] - ($tileDR_W * $tileWidth);
					
					if ($tileCounter === 0) { // first tile
						// echo "First in a column\n";

						$upLeftY_TileOffset = $inputAn['upLeftY'] - ($tileUL_H * $tileHeight);
						$downRightY_TileOffset = $tileHeight - 1; // should it be -1 ?
					} else if (($tileCounter + 0) === $tileRows) { // last tile
						// echo "Last in a column\n";

						$upLeftY_TileOffset = 0;
						$downRightY_TileOffset = $inputAn['downRightY'] - ($tileDR_H * $tileHeight);					
					} else { // mid tile
						// echo "Mid in a column\n";

						$upLeftY_TileOffset = 0;
						$downRightY_TileOffset = $tileHeight - 1; // should it be -1 ?
					}
				

					$bboxWidth_px = $downRightX_TileOffset - $upLeftX_TileOffset;
					$bboxHeight_px = $downRightY_TileOffset - $upLeftY_TileOffset;

					$bboxWidth_rel = $bboxWidth_px / $tileWidth;
					$bboxHeight_rel = $bboxHeight_px / $tileHeight;
			
					$centerXpx = $upLeftX_TileOffset + $bboxWidth_px / 2;
					$centerYpx = $upLeftY_TileOffset + $bboxHeight_px / 2;
			
					$centerXrel = $centerXpx / $tileWidth;
					$centerYrel = $centerYpx / $tileHeight;

					printf("2] UL [$upLeftX_TileOffset $upLeftY_TileOffset] DR [$downRightX_TileOffset $downRightY_TileOffset] BBOX_PX [$bboxWidth_px $bboxHeight_px] BBOX_REL [$bboxWidth_rel $bboxHeight_rel] CENTER_PX [$centerXpx $centerYpx] CENTER_REL[$centerXrel $centerYrel]\n");

					$outputAns[$tileUL_W][$tileUL_H + $tileCounter][] = $thisAn = sprintf("%d %1.14f %1.14f %1.14f %1.14f", $inputAn['label'], $centerXrel, $centerYrel, $bboxWidth_rel, $bboxHeight_rel);
          // printf("Tile %d,%d = $upLeftX_TileOffset,$upLeftY_TileOffset to $downRightX_TileOffset,$downRightY_TileOffset\n", $tileUL_W + $tileCounter, $tileUL_H);
					echo "$thisAn\n";
				}
			} else {
				$tileRows++;
				$tileCols++;
				$tileNumber = (($tileRows + 0) * ($tileCols + 0));

				printf("It's complicated: TileNumber=%d\n", $tileNumber);

				for ($tileCounter = 0; $tileCounter < $tileNumber; $tileCounter++) {
					if (($tileCounter % $tileCols) === 0) { // first in a row
						//echo "First in the row\n";
						
						$upLeftX_TileOffset = $inputAn['upLeftX'] - ($tileUL_W * $tileWidth);
						$downRightX_TileOffset = $tileWidth - 1;  // should it be -1 ?
					
						if ($tileCounter === 0) {
							//echo "First in the first row\n";
							$upLeftY_TileOffset = $inputAn['upLeftY'] - ($tileUL_H * $tileHeight);
							$downRightY_TileOffset = $tileHeight - 1; // should it be -1 ?
						} else {
							$upLeftY_TileOffset = 0;
							if (($tileCounter + $tileCols) >= $tileNumber) { // last row
								//echo "First in the last row\n";
								$downRightY_TileOffset = $inputAn['downRightY'] - ($tileDR_H * $tileHeight);
							} else {
								//echo "First in a mid row\n";
								$downRightY_TileOffset = $tileHeight - 1; // should it be -1 ?
							}
						}
					} else if ((($tileCounter + 1) % $tileCols) === 0) { // last in a row
						// echo "Last in a row\n";

						$upLeftX_TileOffset = 0;
						$downRightX_TileOffset = $inputAn['downRightX'] - ($tileDR_W * $tileWidth);

						if ($tileCounter < $tileCols) { // last in first row
							// echo "Last in the first row\n";

							$upLeftY_TileOffset = $inputAn['upLeftY'] - ($tileUL_H * $tileHeight);
							$downRightY_TileOffset = $tileHeight - 1;
						} else {
							$upLeftY_TileOffset = 0;
							if (($tileCounter + $tileCols) >= $tileNumber) { // last row
								// echo "Last in the last row\n";

								$downRightY_TileOffset = $inputAn['downRightY'] - ($tileDR_H * $tileHeight);
							} else {
								// echo "Last in mid row\n";

								$downRightY_TileOffset = $tileHeight - 1; // should it be -1 ?
							}
						}
					} else { // a horizontally mid tile 
						echo "Mid in the row\n";

						$upLeftX_TileOffset = 0;
						$downRightX_TileOffset = $tileWidth - 1;  // should it be -1 ?
						if ($tileCounter < $tileCols) { // first row
							echo "First in a mid column\n";
							
							$upLeftY_TileOffset = $inputAn['upLeftY'] - ($tileUL_H * $tileHeight);
							$downRightY_TileOffset = $tileHeight - 1; // should it be -1 ?
						} else if (($tileCounter + $tileCols) >= $tileNumber) { // last row
							echo "Last in a mid column\n";

							$upLeftY_TileOffset = 0;
							$downRightY_TileOffset = $inputAn['downRightY'] - ($tileDR_H * $tileHeight);
						} else { // mid row
							echo "Mid in a mid column\n";

							$upLeftY_TileOffset = 0;
							$downRightY_TileOffset = $tileHeight - 1; // should it be -1 ?
						}
						
					}
					$bboxWidth_px = $downRightX_TileOffset - $upLeftX_TileOffset;
					$bboxHeight_px = $downRightY_TileOffset - $upLeftY_TileOffset;
					
					if ($minObjectSegmentWidth > $bboxWidth_px || $minObjectSegmentHeight > $bboxHeight_px) {
						echo "!!!Skip due to small size: ${bboxWidth_px}x${bboxHeight_px} !!!\n";
						continue;
					}

					$bboxWidth_rel = $bboxWidth_px / $tileWidth;
					$bboxHeight_rel = $bboxHeight_px / $tileHeight;
			
					$centerXpx = $upLeftX_TileOffset + $bboxWidth_px / 2;
					$centerYpx = $upLeftY_TileOffset + $bboxHeight_px / 2;
			
					$centerXrel = $centerXpx / $tileWidth;
					$centerYrel = $centerYpx / $tileHeight;

					printf("3] UL [$upLeftX_TileOffset $upLeftY_TileOffset] DR [$downRightX_TileOffset $downRightY_TileOffset] BBOX_PX [$bboxWidth_px $bboxHeight_px] BBOX_REL [$bboxWidth_rel $bboxHeight_rel] CENTER_PX [$centerXpx $centerYpx] CENTER_REL[$centerXrel $centerYrel]\n");

					$outputAns[$tileUL_W + ($tileCounter % $tileCols)][$tileUL_H + ((int) floor($tileCounter / $tileCols))][] = $thisAn = sprintf("%d %1.14f %1.14f %1.14f %1.14f", $inputAn['label'], $centerXrel, $centerYrel, $bboxWidth_rel, $bboxHeight_rel);
//					// echo "$thisAn\n";

				}
			}
		}
	}

	return $outputAns;
}


function processFile(string $fileName, string $outDirectory, int $tileWidth, int $tileHeight) : void
{
	printf("Processing %s\n", $fileName);
	$imagePathInfo = pathinfo($fileName);
	$baseName = $imagePathInfo['filename'];

	$imageSize = getimagesize($fileName);
	$imageWidth = $imageSize[0];
	$imageHeight = $imageSize[1];

	$additionalHorizontalTile = 0;
	$fullFitWidth = floor($imageWidth / $tileWidth);
	$restWidth = $imageWidth - $fullFitWidth * $tileWidth;
	if ($restWidth) {
		$restWidth = $tileWidth;
		$additionalHorizontalTile = 1;
	}

	$additionalVerticalTile = 0;
	$fullFitHeight = floor($imageHeight / $tileHeight);
	$restHeight = $imageHeight - $fullFitHeight * $tileHeight;
	if ($restHeight) {
		$restHeight = $tileHeight;
		$additionalVerticalTile = 1;
	}
	// $restWidth and $restHeight could be zero, then the tiling is a perfect fit

	$annotationsFile = $imagePathInfo['dirname'] . DIRECTORY_SEPARATOR . $baseName . '.txt';
	$annotations = getAnnotationsFromFile($annotationsFile, $imageWidth, $imageHeight);
	
	$minObjectSegmentWidth = 32;
	$minObjectSegmentHeight = 32;
	
  	$convertedAns = convertAnnotations($annotations, $tileWidth, $tileHeight, $minObjectSegmentWidth, $minObjectSegmentHeight);
//	var_export($convertedAns);
	$origImage = imagecreatefromjpeg($fileName);

	$sha1_orig = sha1_file($fileName);
	
	// There will be (($fullFitWidth + 1) * ($fullFitHeight + 1)) tiles
	for ($tileRow = 0; $tileRow < ($fullFitWidth + $additionalHorizontalTile); $tileRow++) {
		for ($tileCol = 0; $tileCol < ($fullFitHeight + $additionalVerticalTile) ; $tileCol++) {
			// Empty tiles are skipped
			if (isset($convertedAns[$tileCol][$tileRow])) {
				$thisTileWidth = $tileCol < $fullFitWidth? $tileWidth : $restWidth;
				$thisTileHeight = $tileRow < $fullFitHeight? $tileHeight : $restHeight;
			
				$tile = imagecreatetruecolor($thisTileWidth, $thisTileHeight);

				$destX = 0;
				$destY = 0;
				$srcX = $tileCol * $tileWidth;
				$srcY = $tileRow * $tileHeight;
				$srcWidth = $tileWidth;
				$srcHeight = $tileHeight;
        print("Creating image for tile col=$tileCol,row=$tileRow from x=$srcX,y=$srcY\n");
			
				$res = imagecopy($tile, $origImage, $destX, $destY, $srcX, $srcY, $srcWidth, $srcHeight);
				if (FALSE === $res) {
					// printf("Could not copy tile [%d : %d]\n", tileRow, tileCol);
				}

				$tileFileName = sprintf("%s%s-%s-%dx%d-%02d-%02d.jpg",
											$outDirectory . DIRECTORY_SEPARATOR,
											$baseName,
											substr($sha1_orig, 0, 16),
											$tileWidth,
											$tileHeight,
											$tileRow,
											$tileCol);
				imagejpeg($tile, $tileFileName);

				$tileAnsFileName = sprintf("%s%s-%s-%dx%d-%02d-%02d.txt",
											$outDirectory . DIRECTORY_SEPARATOR,
											$baseName,
											substr($sha1_orig, 0, 16),
											$tileWidth,
											$tileHeight,
											$tileRow,
											$tileCol);

				$tileAnsContent = implode("\n", $convertedAns[$tileCol][$tileRow]);
				file_put_contents($tileAnsFileName, $tileAnsContent);
				printf("Processed %-60s\t\twith annotation\t\t%s\n", $tileFileName, $tileAnsFileName);
			}
		}
	}
	printf("Processed %s\n", $fileName);
}
