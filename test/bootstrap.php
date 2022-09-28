<?php declare(strict_types=1);
// Initialize autoloading
// TODO: rethink it
$dir = dirname(__FILE__) . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'src';
include_once $dir . DIRECTORY_SEPARATOR . 'func.php';
spl_autoload_register(function ($class_name) use ($dir) {
  $file_path = $dir . DIRECTORY_SEPARATOR . (false === strpos($class_name, 'Exception') ? 'lib' : 'exception') . DIRECTORY_SEPARATOR . $class_name . '.php';
  if (file_exists($file_path))  {
    include_once  $file_path;
  }
});
unset($dir);

system('id -u test &>/dev/null || useradd $_');

FileStorage::deleteDir(FileStorage::getTmpDir(), false);

// Initialization of base indexes to check and some data in it
Searchd::init();
$config_path = Searchd::getConfigPath();
Searchd::$cmd = null; // Reset static prop for further testing

$Config = new ManticoreConfig($config_path);
$Client = new ManticoreClient($Config);
$Client->execute('DROP TABLE IF EXISTS people');
$Client->execute('DROP TABLE IF EXISTS movie');
$Client->execute('CREATE TABLE people (name text, age int)');
$Client->execute('CREATE TABLE movie (title text, year int)');

# TODO: Let's create more indexes here:
# - CREATE TABLE people_pq (name text) type='percolate'
# - CREATE TABLE people_dist_local type='distributed' local='people'
# - CREATE TABLE people_dist_agent type='distributed' agent='127.0.0.1:9312:people';

$Client->execute("INSERT INTO people (id, name, age) VALUES (1, 'Vasya Pupkin', 19), (2, 'Jack Reacher', 44), (3, 'Dylan Maison', 44), (4, 'Jessica Alba', 29), (5, 'John Wick', 55)");
$Client->execute("INSERT INTO movie (id, title, year) VALUES (1, 'Conjuring', 2022), (2, 'The Avengers', 2020), (3, 'Avatar', 2009)");

# TODO: Let's:
# - put some data into the pq index
# - flush ram chunk of one of the non-pq idexes (FLUSH RAMCHUNK people) and then add a few more docs into it