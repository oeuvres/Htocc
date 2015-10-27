<?php
/**
LGPL http://www.gnu.org/licenses/lgpl.html
© 2010 frederic.glorieux@fictif.org et École nationale des chartes
© 2012–2013 frederic.glorieux@fictif.org
© 2013–2015 frederic.glorieux@fictif.org et LABEX OBVIL

Generic tools for files, especially zip

*/
class Phips_File {
  /**
   * Explore recursively a folder by glob
   */
  static public function scanglob($srcglob, $function) 
  {
    // scan files or folder, think to b*/*/*.xml
    foreach(glob($srcglob) as $srcfile) {
      if (is_dir($srcfile)) {
        self::scanglob($srcfile, $function);
      }
      $function($srcfile);
    }
    // glob sended is a single file, no recursion in subfolder, stop here
    if (isset($srcfile) && $srcglob == $srcfile) return;
    // continue scan in all subfolders, with the same file glob
    $pathinfo=pathinfo($srcglob);
    if (!$pathinfo['dirname']) $pathinfo['dirname']=".";
    foreach( glob( $pathinfo['dirname'].'/*', GLOB_ONLYDIR) as $srcdir) {
      $name=pathinfo($srcdir, PATHINFO_BASENAME);
      if ('_' == $name[0] || '.' == $name[0]) continue;
      self::scanglob($srcdir.'/'.$pathinfo['basename'], $function);
    }
  }
  /**
   * Delete all files in a directory, create it if not exist
   */
  static public function newdir($dir, $depth=0) 
  {
    if (is_file($dir)) return unlink($dir);
    // attempt to create the folder we want empty
    if (!$depth && !file_exists($dir)) {
      mkdir($dir, 0775, true);
      @chmod($dir, 0775);  // let @, if www-data is not owner but allowed to write
      return;
    }
    // should be dir here
    if (is_dir($dir)) {
      $handle=opendir($dir);
      while (false !== ($entry = readdir($handle))) {
        if ($entry == "." || $entry == "..") continue;
        self::newDir($dir.'/'.$entry, $depth+1);
      }
      closedir($handle);
      // do not delete the root dir
      if ($depth > 0) rmdir($dir);
      // timestamp newDir
      else touch($dir);
      return;
    }
  }
  /** 
   * List filenames recursively from a directory
   */
  static function namelist($dir, $ext=null, $stream=null) 
  {
    $dir=rtrim($dir, '/\\').'/';
    $namelist=array();
    $handle = opendir($dir);
    if (!$handle) return $filename;
    while (false !== ($entry = readdir($handle))) {
      if ($entry[0]=='.' || $entry[0]=='_') continue;
      if ($entry=='teiheader' || $entry=='onix') continue;
      $file=$dir.$entry;
      if (is_file($file)) {
        if ($ext && $ext != pathinfo($file, PATHINFO_EXTENSION) ) continue; 
        $name=pathinfo($file, PATHINFO_FILENAME);
        if (!isset($namelist[$name])) $namelist[$name] = array();
        else if ($stream) {
          fwrite($stream, "Namelist: $name is not unique ($file)\n");
        }
        $namelist[$name][] = $file;
      }
      if (is_dir($file)) {
        $namelist=array_merge($namelist, self::namelist($file));
      }
    }
    return $namelist;
  }

  /**
   * Recursive file copy
   * no more RecursiveIteratorIterator, pbs with SKIP_DOTS and other things
   */
  static public function copy($src, $dst) 
  {
    if (is_file($src)) {
      if (!file_exists($f = dirname($dst))) {
        mkdir($f, 0775, true);
        @chmod($f, 0775);  // let @, if www-data is not owner but allowed to write
      }
      copy($src, $dst);
      return;
    }
    if (is_dir($src)) {
      $src = rtrim($src, '/');
      $dst = rtrim($dst, '/');
      if (!file_exists($dst)) {
        mkdir($dst, 0775, true);
        @chmod($dst, 0775);  // let @, if www-data is not owner but allowed to write
      }
      $handle = opendir($src);
      while (false !== ($entry = readdir($handle))) {
        if ($entry[0] == '.') continue;
        self::copy($src . '/' . $entry, $dst . '/' . $entry);
      }
      closedir($handle);
    }
  }
  /**
   * Zip folder to a zip file
   */
  static public function zip($zipfile, $srcdir) 
  {
    $zip = new ZipArchive;
    if(!file_exists($zipfile)) $zip->open($zipfile, ZIPARCHIVE::CREATE);
    else $zip->open($zipfile);
    self::zipdir($zip, $srcdir);
    $zip->close();
  }
  /**
   * The recursive method to zip dir
   * start with files (especially for mimetype epub)
   */
  static private function zipdir($zip, $srcdir, $localdir="")
  {
    $srcdir=rtrim($srcdir, "/\\").'/';
    // files 
    foreach( array_filter(glob($srcdir . '/*'), 'is_file') as $path ) {
      $name = basename($path);
      if ($name == '.' || $name == '..') continue;
      $localname = $localdir . $name;
      $zip->addFile($path, $localname);
    }
    // dirs
    foreach( glob($srcdir . '/*', GLOB_ONLYDIR) as $path ) {
      $name = basename($path) . '/';
      if ($name == '.' || $name == '..') continue;
      $localname = $localdir . $name;
      $zip->addEmptyDir($localname);
      self::zipdir($zip, $path, $localname);
    }
    /*
    while (false !== ($entry = readdir($handle))) {
      if ($entry == "." || $entry == "..") continue;
      $file=$dir.$entry; // the file to add
      $name=$entryDir.$entry; // the zip name for the file
      if (is_dir($file)) {
        $zip->addEmptyDir($name.'/');
        self::zipDir($zip, $file.'/', $name.'/');
      }
      else if (is_file($file)) {
        $zip->addFile($file, $name);
      }
    }
    */
  }
  /**
   * load a json resource as an array()
   */
  static function json($file)
  {
    $content=file_get_contents($file);
    $content=substr($content, strpos($content, '{'));
    $content= json_decode($content, true);
    switch (json_last_error()) {
      case JSON_ERROR_NONE:
      break;
      case JSON_ERROR_DEPTH:
        echo "$file — Maximum stack depth exceeded\n";
      break;
      case JSON_ERROR_STATE_MISMATCH:
        echo "$file — Underflow or the modes mismatch\n";
      break;
      case JSON_ERROR_CTRL_CHAR:
        echo "$file — Unexpected control character found\n";
      break;
      case JSON_ERROR_SYNTAX:
        echo "$file — Syntax error, malformed JSON\n";
      break;
      case JSON_ERROR_UTF8:
        echo "$file — Malformed UTF-8 characters, possibly incorrectly encoded\n";
      break;
      default:
        echo "$file — Unknown error\n";
      break;
    }
    return $content;
  }

}



?>