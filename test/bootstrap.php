<?php declare(strict_types=1);

/*
  Copyright (c) 2023-2024, Manticore Software LTD (https://manticoresearch.com)

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License version 3 or any later
  version. You should have received a copy of the GPL license along with this
  program; if you did not, you can find it at http://www.gnu.org/
*/

use Manticoresearch\Backup\Lib\FileStorage;
use Manticoresearch\Backup\Lib\ManticoreClient;
use Manticoresearch\Backup\Lib\ManticoreConfig;
use Manticoresearch\Backup\Lib\OS;
use Manticoresearch\Backup\Lib\Searchd;

include_once __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR
	. 'vendor' .  DIRECTORY_SEPARATOR . 'autoload.php'
;
include_once __DIR__ . DIRECTORY_SEPARATOR . 'SearchdTestCase.php';

if (!OS::isWindows()) {
	system('id -u test 2>/dev/null || adduser --disabled-password --gecos "" test');
}

FileStorage::deleteDir(FileStorage::getTmpDir(), false);

SearchdTestCase::setUpBeforeClass();
// Initialization of base tables to check and some data in it

$configPath = Searchd::getConfigPath();

$config = new ManticoreConfig($configPath);
$client = new ManticoreClient([$config]);

// people table
$client->execute('DROP TABLE IF EXISTS people');
$client->execute('CREATE TABLE people (name text, age int)');
$client->execute(
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
$client->execute('FLUSH RAMCHUNK people');
$client->execute(
	"
  INSERT INTO people (id, name, age)
  VALUES
    (6, 'Nicolas Pumpkin', 15),
    (7, 'Halle Berry', 33),
    (8, 'Dan Douglas', 54)
"
);


// movie table
$client->execute('DROP TABLE IF EXISTS movie');
$client->execute('CREATE TABLE movie (title text, year1 int)');
$client->execute(
	"
  INSERT INTO movie (id, title, year1)
  VALUES
    (1, 'Conjuring', 2022),
    (2, 'The Avengers', 2020),
    (3, 'Avatar', 2009)
"
);

// people_pq table
$client->execute('DROP TABLE IF EXISTS people_pq');
$client->execute("CREATE TABLE people_pq (name text) type='percolate'");
$client->execute(
	"
  INSERT INTO people_pq (query)
  VALUES
    ('@name Halle'),
    ('@name Nicolas'),
    ('@name Dan')
"
);

// people_dist_local table
$client->execute('DROP TABLE IF EXISTS people_dist_local');
$client->execute("CREATE TABLE people_dist_local type='distributed' local='people'");

// people_dist_agent table
$client->execute('DROP TABLE IF EXISTS people_dist_agent');
$client->execute("CREATE TABLE people_dist_agent type='distributed' agent='127.0.0.1:9312:people'");

SearchdTestCase::tearDownAfterClass();
