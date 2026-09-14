<?php
/** Reference images live outside the webroot and are served only after AM login. */
function am_reference_photo_root(): string { return (string)am_env('AM_REFERENCE_PHOTO_DIR', '/var/lib/am-reference-photos'); }
function am_reference_photo_prepare(string $input, string $output): array {
    if (!extension_loaded('gd')) throw new RuntimeException('Image processing is unavailable. Ask the AM administrator.');
    $info = @getimagesize($input);
    if (!$info || !in_array($info['mime'], ['image/jpeg','image/png','image/webp'], true)) throw new RuntimeException('Use a JPEG, PNG or WebP photograph. Convert HEIC to JPEG first.');
    if ($info[0] < 40 || $info[1] < 40 || $info[0] > 10000 || $info[1] > 10000 || $info[0]*$info[1] > 24000000) throw new RuntimeException('Use a clear image between 40 pixels and 24 megapixels.');
    $im = @imagecreatefromstring(file_get_contents($input));
    if (!$im) throw new RuntimeException('This image could not be read.');
    if ($info['mime'] === 'image/jpeg' && function_exists('exif_read_data')) {
        $exif = @exif_read_data($input); $orientation = (int)($exif['Orientation'] ?? 1);
        if (in_array($orientation, [2,5,7], true)) imageflip($im, IMG_FLIP_HORIZONTAL);
        if ($orientation === 4) imageflip($im, IMG_FLIP_VERTICAL);
        $rotation = [3=>180,5=>90,6=>-90,7=>-90,8=>90][$orientation] ?? 0;
        if ($rotation) $im = imagerotate($im, $rotation, 0);
        $info[0] = imagesx($im); $info[1] = imagesy($im);
    }
    // Re-encoding removes GPS and other metadata.
    $ratio = min(1, 1600/max($info[0],$info[1]));
    $out = imagecreatetruecolor((int)round($info[0]*$ratio),(int)round($info[1]*$ratio));
    $white = imagecolorallocate($out,255,255,255); imagefill($out,0,0,$white);
    imagecopyresampled($out,$im,0,0,0,0,imagesx($out),imagesy($out),$info[0],$info[1]);
    if (!imagejpeg($out,$output,85)) throw new RuntimeException('Photo could not be stored.');
    unset($out, $im); chmod($output,0600);
    return ['sha256'=>hash_file('sha256',$output), 'bytes'=>filesize($output)];
}
