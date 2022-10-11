<?php declare(strict_types=1);

/*
  Copyright (c) 2022, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 2 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

include_once __DIR__ . DIRECTORY_SEPARATOR . '..'
  . DIRECTORY_SEPARATOR . 'src'
  . DIRECTORY_SEPARATOR . 'init.php'
;

include_once __DIR__ . DIRECTORY_SEPARATOR . 'SearchdTestCase.php';

system('id -u test 2>/dev/null || useradd test');

FileStorage::deleteDir(FileStorage::getTmpDir(), false);

SearchdTestCase::setUpBeforeClass();
// Initialization of base tables to check and some data in it
Searchd::init();
$config_path = Searchd::getConfigPath();
Searchd::$cmd = null; // Reset static prop for further testing

$Config = new ManticoreConfig($config_path);
$Client = new ManticoreClient($Config);

// people table
$Client->execute('DROP TABLE IF EXISTS people');
$Client->execute('CREATE TABLE people (name text, age int)');
$Client->execute(
	"
  INSERT INTO people (id, name, age)
  VALUES
    (1, 'Vasya Pupkin', 19),
    (2, 'Jack Reacher', 44),
    (3, 'Dylan Maison', 44),
    (4, 'Jessica Alba', 29),
    (5, 'John Wick', 55)
"
);
$Client->execute('FLUSH RAMCHUNK people');
$Client->execute(
	"
  INSERT INTO people (id, name, age)
  VALUES
    (6, 'Nicolas Pumpkin', 15),
    (7, 'Halle Berry', 33),
    (8, 'Dan Douglas', 54)
"
);


// movie table
$Client->execute('DROP TABLE IF EXISTS movie');
$Client->execute('CREATE TABLE movie (title text, year int)');
$Client->execute(
	"
  INSERT INTO movie (id, title, year)
  VALUES
    (1, 'Conjuring', 2022),
    (2, 'The Avengers', 2020),
    (3, 'Avatar', 2009)
"
);

// people_pq table
$Client->execute('DROP TABLE IF EXISTS people_pq');
$Client->execute("CREATE TABLE people_pq (name text) type='percolate'");
$Client->execute(
	"
  INSERT INTO people_pq (query)
  VALUES
    ('@name Halle'),
    ('@name Nicolas'),
    ('@name Dan')
"
);

// people_dist_local table
$Client->execute('DROP TABLE IF EXISTS people_dist_local');
$Client->execute("CREATE TABLE people_dist_local type='distributed' local='people'");

// people_dist_agent table
$Client->execute('DROP TABLE IF EXISTS people_dist_agent');
$Client->execute("CREATE TABLE people_dist_agent type='distributed' agent='127.0.0.1:9312:people'");
SearchdTestCase::tearDownAfterClass();
