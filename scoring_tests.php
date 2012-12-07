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
$clay2 = res(Resource::CLAY, 2, false);
$clay = res(Resource::CLAY, 1, false);
$stone = res(Resource::STONE, 1, false);
$ore = res(Resource::ORE, 1, false);
$wood = res(Resource::WOOD, 1, false);
$linen = res(Resource::LINEN, 1, false);
$paper = res(Resource::PAPER, 1, false);
$glass = res(Resource::GLASS, 1, false);
$caravan = new Resource(true, false);
$caravan->add(Resource::CLAY);
$caravan->add(Resource::ORE);
$caravan->add(Resource::WOOD);
$caravan->add(Resource::STONE);
$stonebrick = new Resource(true, false);
$stonebrick->add(Resource::CLAY);
$stonebrick->add(Resource::STONE);
test(satisfiable(array($clay), array($clay2)));
test(satisfiable(array($clay), array($caravan)));
test(!satisfiable(array($clay, $wood), array($caravan)));
test(satisfiable(array($clay, $wood), array($caravan, $wood)));
test(satisfiable(array($clay, $wood), array($caravan, $clay)));
test(satisfiable(array($clay, $wood), array($wood, $caravan)));
test(satisfiable(array($clay, $wood), array($clay, $caravan)));
test(!satisfiable(array($clay, $wood), array($clay2)));
test(!satisfiable(array($clay, $wood), array($ore)));

$caravan0 = ResourceOption::me($caravan);
$caravanl = ResourceOption::left($caravan);
$caravanr = ResourceOption::right($caravan);
test(count(Resource::satisfy(array($clay), array(), 10)) == 0);
test(count(Resource::satisfy(array($clay), array($caravan0), 10)) == 1);
test(count(Resource::satisfy(array($clay, $stone), array($caravan0), 10)) == 0);

$ret = Resource::satisfy(array($clay), array($caravanl), 100);
test(count($ret) == 1);
test($ret[0]['left'] == 1);
test($ret[0]['right'] == 0);
test($ret[0]['self'] == 0);

$ret = Resource::satisfy(array($clay), array($caravan0, $caravanl), 10);
test(count($ret) == 1);
test($ret[0]['left'] == 0);
test($ret[0]['right'] == 0);
test($ret[0]['self'] == 0);

$ret = Resource::satisfy(array($clay), array($caravanr, $caravanl), 10);
test(count($ret) == 2);

$ret = Resource::satisfy(array($clay),
                         array(ResourceOption::left($clay),
                               ResourceOption::right($clay)), 10);
test(count($ret) == 2);

$ret = Resource::satisfy(
    array($stone, $stone, $ore),
    array(
        ResourceOption::me($stone),
        ResourceOption::me($ore),
        ResourceOption::me($glass),
        ResourceOption::me($paper),
        ResourceOption::left($stone, 2),
        ResourceOption::left($ore, 2),
        ResourceOption::left($glass, 2),
        ResourceOption::left($paper, 2),
        ResourceOption::right($stone, 2),
        ResourceOption::right($ore, 2),
        ResourceOption::right($glass, 2),
        ResourceOption::right($paper, 2),
    ),
    100
);
test(count($ret) == 2);

// Test science scoring
$s1 = new Science();
test($s1->points() == 0);
$s1->add(Science::GEAR);
test($s1->points() == 1);
$s1->add(Science::GEAR);
test($s1->points() == 4);
$s1->add(Science::COMPASS);
test($s1->points() == 5);
$s1->add(Science::ANY);
test($s1->points() == 13);
$s1->add(Science::ANY);
test($s1->points() == 18);
$s1->add(Science::TABLET);
test($s1->points() == 26);

// Test playing cards
function testcard($csv, $age, $callback) {
    $player = new Player('id', 4);
    $player->setGame(null); // initialize game fields
    $card = WonderCard::import($age, str_getcsv($csv))[0];
    $card->play($player);
    $callback($player);
}

// Playing a brown resource should add one buyable resource
testcard(',,brown,yard,T,,,1,2,2,2,2', 1, function($player) {
    test(count($player->permResources) == 1);
    test($player->permResources[0]->buyable());
});
// Playing a gray resource should add one buyable resource
testcard(',,grey,yard,G,,,1,2,2,2,2', 1, function($player) {
    test(count($player->permResources) == 1);
    test($player->permResources[0]->buyable());
});
// Playing a brown multi-resource should add two resources
testcard(',,brown,yard,TT,,,1,2,2,2,2', 1, function($player) {
    test(count($player->permResources) == 2);
    test($player->permResources[0]->buyable());
    test($player->permResources[1]->buyable());
    global $wood;
    test(satisfiable(array($wood, $wood), $player->permResources));
});
// Playing a brown one-resource should add one resources
testcard(',,brown,yard,S/T,,,1,2,2,2,2', 1, function($player) {
    test(count($player->permResources) == 1);
    test($player->permResources[0]->buyable());
    global $wood, $stone;
    test(satisfiable(array($wood), $player->permResources));
    test(satisfiable(array($stone), $player->permResources));
    test(!satisfiable(array($stone, $wood), $player->permResources));
});

// Point cards should just add points
testcard(',,blue,yard,4,,,1,2,2,2,2', 1, function($player) {
    test($player->points == 4);
});

// Military cards should add military!
testcard(',,red,yard,1,,,1,2,2,2,2', 1, function($player) {
    test($player->military->size() == 1);
});

// first-age yellow coin card
testcard(',,yellow,yard,1,,,1,2,2,2,2', 1, function($player) {
    test($player->coins == 1);
});
// first-age yellow discount card
testcard(',,yellow,yard,< WO,,,1,2,2,2,2', 1, function($player) {
    test(count($player->discounts['left']) == 2);
});
// second-age yellow resource
testcard(',,yellow,yard,G/P/L,,,1,2,2,2,2', 2, function($player) {
    test(count($player->permResources) == 1);
    global $linen;
    test(satisfiable(array($linen), $player->permResources));
    test(!satisfiable(array($linen, $linen), $player->permResources));
});
// second-age yellow coin-gain card
testcard(',,brown,yard,G,,,1,2,2,2,2', 2, function($player) {
    $other = new Player('f', 5);
    $other->cardsPlayed[] =
        WonderCard::import(1, str_getcsv(',,brown,yard,G,,,1,1,1,1,1,'))[0];
    $player->cardsPlayed[] = $other->cardsPlayed[0];
    $player->leftPlayer = $other;
    $player->rightPlayer = $other;
    $card = WonderCard::import(2, str_getcsv(',,yellow,f,<V> brown 1,,,1,1,1,1,1'))[0];
    test($player->coins == 0);
    $card->play($player);
    test($player->coins == 3);
});
// third-age yellow coin-gain card
testcard(',,yellow,yard,(1){1} yellow,,,1,2,2,2,2', 3, function($player) {
    test($player->coins == 1);
});
