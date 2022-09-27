<?php declare(strict_types=1);

include __DIR__ . DIRECTORY_SEPARATOR . '..'
  . DIRECTORY_SEPARATOR . 'src'
  . DIRECTORY_SEPARATOR . 'init.php'
;

system('id -u test 2>/dev/null || useradd test');

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

$Client->execute("
  INSERT INTO people (id, name, age)
  VALUES
    (1, 'Vasya Pupkin', 19),
    (2, 'Jack Reacher', 44),
    (3, 'Dylan Maison', 44),
    (4, 'Jessica Alba', 29),
    (5, 'John Wick', 55)
");
$Client->execute("
  INSERT INTO movie (id, title, year)
  VALUES
    (1, 'Conjuring', 2022),
    (2, 'The Avengers', 2020),
    (3, 'Avatar', 2009)
");
