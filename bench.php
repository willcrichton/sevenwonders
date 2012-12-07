<?php

require_once('scoring.php');
require_once('player.php');
require_once('wonders.php');

function res($type, $amt, $one) {
    $ret = new Resource($one, false);
    for ($i = 0; $i < $amt; $i++)
        $ret->add($type);
    return $ret;
}

function test($f) {
    if (!$f) throw new Exception("bad");
}

function satisfiable($want, $have) {
    $have = array_map(function($r) { return ResourceOption::me($r); },
                      $have);
    $ret = Resource::satisfy($want, $have, 100);
    foreach ($ret as $dir) {
        $good = true;
        foreach ($dir as $amt) {
            if ($amt > 0) {
                $good = false;
                break;
            }
        }
        if ($good)
            return true;
    }
    return false;
}

// Resource satisfiability
$clay = res(Resource::CLAY, 1, false);
$stone = res(Resource::STONE, 1, false);
$ore = res(Resource::ORE, 1, false);
$wood = res(Resource::WOOD, 1, false);
$linen = res(Resource::LINEN, 1, false);
$glass = res(Resource::GLASS, 1, false);
$paper = res(Resource::PAPER, 1, false);
$stone2 = res(Resource::STONE, 2, false);
$clay2 = res(Resource::CLAY, 2, false);
$sb = new Resource(true, false);
$sb->add(Resource::CLAY);
$sb->add(Resource::STONE);
$wo = new Resource(true, false);
$wo->add(Resource::WOOD);
$wo->add(Resource::ORE);
$sw = new Resource(true, false);
$sw->add(Resource::WOOD);
$sw->add(Resource::STONE);

Resource::satisfy(
    array($clay, $stone, $ore, $wood, $linen, $glass, $paper),
    array(
        ResourceOption::me($stone2),
        ResourceOption::me($ore),
        ResourceOption::me($clay),
        ResourceOption::me($sb),
        ResourceOption::me($paper),
        ResourceOption::me($glass),
        ResourceOption::left($stone2, 2),
        ResourceOption::left($ore, 2),
        ResourceOption::left($clay, 2),
        ResourceOption::left($sb, 2),
        ResourceOption::left($paper, 2),
        ResourceOption::left($glass, 2),
        ResourceOption::right($stone2, 2),
        ResourceOption::right($ore, 2),
        ResourceOption::right($clay, 2),
        ResourceOption::right($sb, 2),
        ResourceOption::right($paper, 2),
        ResourceOption::right($glass, 2),
    ),
    100
);

Resource::satisfy(
    array($wood, $wood, $wood, $stone, $linen),
    array(
        ResourceOption::me($wood),
        ResourceOption::me($wo),
        ResourceOption::me($sw),
        ResourceOption::me($clay2),
        ResourceOption::me($glass),
        ResourceOption::me($linen),
        ResourceOption::left($wood, 2),
        ResourceOption::left($wo, 2),
        ResourceOption::left($sw, 2),
        ResourceOption::left($clay2, 2),
        ResourceOption::left($glass, 2),
        ResourceOption::left($linen, 2),
        ResourceOption::right($wood, 2),
        ResourceOption::right($wo, 2),
        ResourceOption::right($sw, 2),
        ResourceOption::right($clay2, 2),
        ResourceOption::right($glass, 2),
        ResourceOption::right($linen, 2),
    ),
    100
);
