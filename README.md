# yolo-break-image-into-tiles
Script to break high res images into low res images made of it while keeping/converting the Yolo annotations


# The problem
The idea is that feeding hi-res images into Yolo (for example when using AlexeyAB's implementation) can make small object smaller and hard to recognize.

# The solution
This script breaks a hi-res image into smaller tiles (with dimensions you specify). So, for example let's say you have 1920x1080 (FullHD) images, which have annotations. These images have small objects and you want to better localize the objects. With this script you can break the image into 4 tiles of 960x540 (preserving the aspect ratio), or 6 tiles of 640x540, or whatever suits you (418x418 etc).

# Usage
1. Build a container with the Dockerfile. For example with local image named php:7.4-cli-gd
```
docker build -t php:7.4-cli-gd .
```
2. Once you have the image built you can use it the following way
```
docker run -it --rm \
   -v `pwd`/input:/input \
   -v `pwd`/break_image_into_tiles.php:/break_image_into_tiles.php \
   -v `pwd`/out:/out php:7.4-cli-gd \
   bash -c 'for i in /input/*.jpg; do php /break_image_into_tiles.php "$i" /out 960 540;  done'
```
Where `pwd`/input in this example points to the place in the host where you annotated files are. In this example it is under input/ of the current directory but can be anywhere else.
`pwd`/out is the output directory where the result will be created. The jpg and .txt annotations will have names that won't overwrite the input files. Thus you can also point to the input.
In the last line, 960 and 540 is the width and the height of the tile. You can choose whatever you want. These are absolute values and not relative ones. This script doesn't support relative values.
This command will not look recursively for annotated files. Also, be sure that every jpg file is annotated with .txt or you will see errors.
If you want to recursively find files and break them into tiles use the following command:
```
docker run -it --rm \
   -v `pwd`/input:/input \
   -v `pwd`/break_image_into_tiles.php:/break_image_into_tiles.php \
   -v `pwd`/out:/out \
   php:7.4-cli-gd \
   bash -c 'find /input -type f -name "*.jpg" -exec php /break_image_into_tiles.php "{}" /out 960 540 \;'
```
Be advised that the output will be flattened in the out directory and the directory structure will not be preserved. Thus, if you have files will duplicated names in the input in different directories the result will be overwritten.

