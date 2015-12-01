<?php
/**
 * © 2010 frederic.glorieux@fictif.org
 * © 2012 frederic.glorieux@fictif.org & LABEX OBVIL
 *
 * This program is a free software: you can redistribute it and/or modify it
 * under the terms of the GNU Lesser General Public License 
 * http://www.gnu.org/licenses/lgpl.html
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of 
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 *
 */

/** 
 * Set of static functions useful with Sqlite datas, especially FTS3
 * Maybe used inside SQL queries with $dbo->sqliteCreateFunction()
 */
class Sqlite {
  /** sqlite File  */
  private $_sqlitefile;
  /** A text to snip */
  private $_text;
  /**
   * Constructor with a Sqlite base and a path
   */
  public function __construct($sqlitefile = null, $lang = null, $path="", $pars = array())
  {
    $this->_sqlitefile = $sqlitefile;
    $this->pdo = new PDO("sqlite:".$sqlitefile);
    $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_WARNING ); // get error as classical PHP warn
    $this->pdo->exec("PRAGMA temp_store = 2;"); // store temp table in memory (efficiency)
    // matchinfo not available on centOs 5.5
    // $this->pdo->sqliteCreateFunction('matchinfo2occ', 'Sqlite::matchinfo2occ', 1);
    $this->pdo->sqliteCreateFunction('offsets2occ', 'Sqlite::offsets2occ', 1);
  }
  /**
   * Sqlite FTS3
   * Output a concordance from a search row with a text field, and an offset string pack
   * @param string $text The text from which to extract snippets 
   * @param string $offsets A paack of integer in Sqlite format http://www.sqlite.org/fts3.html#section_4_1 
   * @return null 
   */
  public function conc($text, $offsets, $field=null, $href='') {
    $width = 50; // kwic width
    if (!$field) $field = 0;
    $this->_text = $text; // for efficiency, set text at class level
    $offsets = explode(' ',$offsets);
    $mark = 0; // occurrence hilite counter 
    $start = null;
    $count = count($offsets);
    for ($i = 0; $i<$count; $i = $i+4) {
      if($offsets[$i] != $field) continue; // match in another column
      // first index 
      if ($start === null) $start = $offsets[$i+2];
      // if it is a phrase query continuing on next token, go next
      if ($i+6 < $count) {
        $from = $offsets[$i+2]+$offsets[$i+3];
        $length = $offsets[$i+6] - $from;
        $inter = substr($this->_text, $from, $length);
        if(!preg_match('/\pL/', $inter)) continue;
      }
      $mark++; // increment only when a <mark> is openened
      $size = $offsets[$i+2]+$offsets[$i+3]-$start;
      $snip = $this->snip($start, $size);
      echo "\n        " . '<div class="snip">';
      if ($href) echo "\n        " . '<a class="snip" href="' . $href.'#mark'.$mark.'">';
      echo '<span class="left">';
      if (mb_strlen($snip['left']) > $width + 5) { 
        $pos = mb_strrpos($snip['left'], " ", 0 - $width);
        echo '<span class="exleft">' . mb_substr($snip['left'], 0, $pos) . '</span>' . mb_substr($snip['left'], $pos);
      }
      else echo $snip['left'];
      echo ' </span><span class="right"><mark>'.$snip['center'].'</mark>';
      if (mb_strlen($snip['right']) > $width + 5) { 
        $pos = mb_strpos($snip['right']. ' ', " ", $width);
        echo mb_substr($snip['right'], 0, $pos) . '<span class="exright">' . mb_substr($snip['right'], $pos) . '</span>';
      }
      else echo $snip['right'];
      echo '</span>';
      if ($href) echo '</a>';
      echo '</div>';
      $start = null;
      /* TODO limit
      $this->hitsCount++;
      if (($occBookMax && $mark >= $occBookMax)) {
        echo "\n     ".$this->msg('occbookmax', array($mark, ($basehref . $article['articleName'] . '?q=' .$qHref.'#mark1')));
        break;
      }
      if ($this->hitsCount >= $this->hitsMax) break;
      */
    }
  }

  /**
   * For a fulltext search result.
   * Snip a sentence in plain-text according to a byte offset
   * Dependant of a formated text with one sentence by line
   * return is an array with three components : left, center, right
   * reference text is set as a class field
   */
  public function snip($offset, $size) 
  {
    $width = 300; // width max in chars
    $snip = array();
    $start = $offset-$width;
    $length = $width;
    if($start < 0) {
      $start = 0;
      $length = $offset-1;
    }
    if ($length) {
      $left = substr($this->_text, $start, $length);
      // cut at last line break 
      if ($pos = strrpos($left, "\n")) $left = substr($left, $pos);
      // if no cut at a space
      else if ($pos = strpos($left, ' ')) $left = '… '.substr($left, $pos+1);
      $snip['left'] = ltrim(preg_replace('@[  \t\n\r]+@u', ' ', $left));
    }
    $snip['center'] = preg_replace('@[  \t\n\r]+@u', ' ', substr($this->_text, $offset, $size));
    $start = $offset+$size;
    $length = $width;
    $len = strlen($this->_text);
    if ($start + $length - 1 > $len) $length = $len-$start;
    if($length) {
      $right = substr($this->_text, $start, $length);
      // cut at first line break 
      if ($pos = strpos($right, "\n")) $right = substr($right,0, $pos);
      // or cut at last space
      else if ($pos = strrpos($right, ' ')) $right = substr($right, 0, $pos).' …';
      $snip['right'] = rtrim(preg_replace('@[  \t\n\r]+@u', ' ', $right));
    }
    return $snip;
  }

  /**
  Infer occurrences count from a matchinfo SQLite byte blob
  Is a bit slower than offsets, unavailable in sqlite 3.6.20 (CentOS6)
  but more precise with phrase query

$db->sqliteCreateFunction('matchinfo2occ', 'Sqlite::matchinfo2occ', 1);
$res = $db->prepare("SELECT matchinfo2occ(matchinfo(search, 'x')) AS occ , text FROM search  WHERE text MATCH ? ");
$res->execute(array('"Felix the cat"'));

« Felix the cat, Felix the cat » 
the cat felix       = 6 
"felix the cat"     = 2
"felix the cat" the = 4 

  matchinfo(?, 'x') 
  32-bit unsigned integers in machine byte-order
  3 * cols * phrases
  1) In the current row, the number of times the phrase appears in the column. 
  2) The total number of times the phrase appears in the column in all rows in the FTS table. 
  3) The total number of rows in the FTS table for which the column contains at least one instance of the phrase.
  */  
  static function matchinfo2occ($matchinfo)
  {
    $ints = unpack('L*', $matchinfo);
    $occ = 0;
    $max = count($ints)+1;
    for($a = 1; $a <$max; $a = $a+3 ) {
      $occ += $ints[$a];
    }
    return $occ;
  }
  /**
  Infer occurrences count from an offsets() result
  A bit faster than matchinfo, available in sqlite 3.6.20 (CentOS6)
  but less precise for phrase query, 

$db->sqliteCreateFunction('offsets2occ', 'Sqlite::offsets2occ', 1);
$res = $db->prepare("SELECT offsets2occ(offsets(search))AS occ , text FROM search  WHERE text MATCH ? ");
$res->execute(array('"Felix the cat"'));

« Felix the cat, Felix the cat »
  felix the cat       = 6 
  "felix the cat"     = 6  
  "felix the cat" the = 8   

space-separated integers, 4 for each term in text

0   The column number that the term instance occurs in (0 for the leftmost column of the FTS table, 1 for the next leftmost, etc.). 
1   The term number of the matching term within the full-text query expression. Terms within a query expression are numbered starting from 0 in the order that they occur. 
2   The byte offset of the matching term within the column. 
3   The size of the matching term in bytes.
  */ 
  static function offsets2occ($offsets)
  {
    $occ = (1+substr_count($offsets, ' '))/4 ;
    // result from hidden field
    if ($occ == 1 && $offsets[0] == 1) return 0;
    return $occ;
  }
  /*
  TODO, Repack a matchinfo array to hilite properly phrase query
  */
  static function offsetsRepack($offsets) {
    return $offsets;
  }
  /**
   * Sample code base to test the last implemented function, 
   * see doc of each function for other code samples
   */
  static function doCli()
  {
    $db = new PDO('sqlite::memory:');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_WARNING );
    $text = 'Felix the cat, Felix o the cat';
    $db->exec("
      CREATE VIRTUAL TABLE search USING fts3(text);
      INSERT INTO search (text) VALUES ('$text');
    ");
    $q = 'the NEAR o';
    echo "Search - $q - in : $text\n";
    // here come method specific example
    $db->sqliteCreateFunction('offsets2occ', 'Sqlite::offsets2occ', 1);
    $res = $db->prepare("SELECT offsets2occ(offsets(search))AS occ, offsets(search) AS offsets , text FROM search  WHERE text MATCH ? ");
    $res->execute(array($q));

    while($row = $res->fetch(PDO::FETCH_ASSOC)) {
      echo $row['occ'].' '.$row['text']."\n";
      echo preg_replace(
        array('/(\d+ \d+ \d+ \d+)/'), 
        array("\n".'$1'), 
        $row['offsets']
      );
    }

  }
}

?>