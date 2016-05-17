<?php

require 'config.php';

$publishing = false;
$republishing = false;
$options = getopt("", array("publish"));
if ( array_key_exists("publish", $options) ) {
   $publishing = true;
}
if ( array_key_exists("republish", $options) ) {
   $republishing = true;
}

try {
   $globaldbh = new PDO("mysql:host=" . DBHOST . ";port=" . DBPORT . ";dbname=" . DBNAME, DBUSER, DBPASS);
} catch (PDOException $e) {
   echo "Error connecting to DB!: ", $e->getMessage() . "\n";
   die();
}

$mods = array();
if ( $fh = opendir(LOCALMODFOLDER) ) {
   while ( false !== ($entry = readdir($fh)) ) {
      if ( ($entry != ".") && ($entry != "..") && is_dir(LOCALMODFOLDER . $entry) ) {
         $mods[$entry] = 0;
      }
   }
   closedir($fh);
   asort($mods);
} else {
   echo "Could not pars the mod folder \"", LOCALMODFOLDER, "\"!\n";
   die();
}

if ( count($mods) == 0 ) {
   echo "No mods found in the mod folder \"", LOCALMODFOLDER, "\"!\n";
   die();
}

if ( !file_exists(LOCALMODFOLDER . "published_mods.json") ) {
   $publishedmods = array();
} else {
   $filecontents = file_get_contents(LOCALMODFOLDER . "published_mods.json");
   $publishedmods = json_decode($filecontents);
}

//
// Make sure the mods exist
//
$allmodsexist = true;
$query = "CREATE TEMPORARY TABLE localmods (lname VARCHAR(200) UNIQUE)";
$sth = $globaldbh->prepare($query);
$sth->execute();
$query = "INSERT INTO localmods VALUES ";
$modnum = 0;
$fields = array();
foreach ( $mods as $mod => $mod_id ) {
   if ( $modnum != 0 ) $query .= ", ";
   $query .= "(:mod" . $modnum . ")";
   $fields[":mod" . $modnum] = $mod;
   $modnum++;
}
$sth = $globaldbh->prepare($query);
$sth->execute($fields);
$query = "SELECT lname FROM localmods WHERE lname NOT IN (SELECT name FROM " . DBPREFIX . "mods) ORDER BY lname";
$sth = $globaldbh->prepare($query);
$sth->execute();
while ( $row = $sth->fetch() ) {
   $allmodsexist = false;
   echo "Mod not found in database: {$row['lname']}\n";
}
if ( !$allmodsexist ) {
   echo "\nSome mods not found in database. Correct before continuing.\n";
   die();
}

$query = "SELECT id, name FROM localmods LEFT JOIN " . DBPREFIX . "mods ON lname=name";
$sth = $globaldbh->prepare($query);
$sth->execute();
while ( $row = $sth->fetch() ) {
   $mods[$row['name']] = $row['id'];
}

$query = "CREATE TEMPORARY TABLE localmodversions (mod_id INT(11), version VARCHAR(200))";
$sth = $globaldbh->prepare($query);
$sth->execute();

if ( !$republishing ) echo "Skipping ", count($publishedmods), " previously published mods.\n";

$newversions = false;
//
// Walk through mod version locally and ensure they exist in the DB
//
foreach ( $mods as $mod => $mod_id ) {
   $files = glob(LOCALMODFOLDER . $mod . "/" . $mod . "-*.zip");
   $localversions = array();
   $query = "INSERT INTO localmodversions VALUES ";
   $numvers = 0;
   $fields = array();
   $fields[":mod_id"] = $mod_id;
   foreach ( $files as $file ) {
      $version = substr($file, strlen(LOCALMODFOLDER . $mod . "/" . $mod . "-"), -4);
      if ( !$republishing && in_array($mod . "-" . $version, $publishedmods) ) continue;
      if ( !in_array($mod . "-" . $version, $publishedmods) ) {
         $newversions = true;
         $publishedmods[] = $mod . "-" . $version;
      }
      if ( $numvers != 0 ) $query .= ", ";
      $query .= "(:mod_id, :version" . $numvers . ")";
      $fields[":version" . $numvers] = $version;
      $numvers++;
   }
   if ( $numvers > 0 ) {
      $sth = $globaldbh->prepare($query);
      $sth->execute($fields);
      $query = "SELECT version FROM localmodversions WHERE mod_id=:mod_id AND version NOT IN (SELECT version FROM " . DBPREFIX . "modversions WHERE mod_id=:mod_id)";
      $fields = array();
      $fields[":mod_id"] = $mod_id;
      $sth = $globaldbh->prepare($query);
      $sth->execute($fields);
      while ( $row = $sth->fetch() ) {
         $version = $row['version'];
         $md5 = md5_file(LOCALMODFOLDER . $mod . "/" . $mod . "-" . $version . ".zip");
         echo "New mod version for {$mod} is {$version} with MD5 {$md5}\n";
         if ( $publishing ) {
            // If no mod versions exist for current mod, try making the directory
            $query = "SELECT mv.id AS mvid, m.id AS mid FROM " . DBPREFIX . "modversions AS mv LEFT JOIN " . DBPREFIX . "mods AS m ON mod_id=m.id WHERE name=:name";
            $fields = array();
            $fields[':name'] = $mod;
            $sth = $globaldbh->prepare($query);
            $sth->execute($fields);
            if ( false === ($row = $sth->fetch() ) ) {
               mkdir(TEMPFOLDER . $mod, 0777, true);
               $junk = system(SCPCOMMAND . " -r \"" . TEMPFOLDER . $mod . "\" " . REMOTETARGET);
               rmdir(TEMPFOLDER . $mod);
            }
            // Upload the file
            $junk = system(SCPCOMMAND . " \"" . LOCALMODFOLDER . $mod . "/" . $mod . "-" . $version . ".zip" . "\" " . REMOTETARGET . $mod . "/");
            // Insert new version into modversions table
            $query = "INSERT INTO " . DBPREFIX . "modversions (mod_id, version, md5, created_at, updated_at) VALUES(:mod_id, :version, :md5, NOW(), NOW())";
            $fields = array();
            $fields[':mod_id'] = $mod_id;
            $fields[':version'] = $version;
            $fields[':md5'] = $md5;
            $sth = $globaldbh->prepare($query);
            $sth->execute($fields);
         }
      }
   }
}

if ( $publishing && $newversions ) {
   $filecontents = json_encode($publishedmods);
   file_put_contents(LOCALMODFOLDER . "published_mods.json", $filecontents);
}

?>
